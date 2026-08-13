<?php

namespace BuybackManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Seat\Eveapi\Models\Corporation\CorporationInfo;

/**
 * Discord webhook configured for buyback event notifications.
 *
 * Per-corp scoping — null corporation_id is a "global" webhook that
 * fires for every corp's events. categories is a JSON array of
 * category keys; recognized values defined by WebhookDispatcher.
 *
 * role_mention is stored as the Discord-ready string ('<@&123>' role
 * or '<@456>' user). WebhookDispatcher never builds these at send
 * time — it uses whatever's persisted verbatim. Always groups, never
 * direct messages, per the notification design principle.
 */
class BuybackWebhook extends Model
{
    /** A contract matched its appraisal key cleanly. */
    public const CATEGORY_CONTRACT_MATCHED = 'contract_matched';
    /** A contract matched but needs review (price drift, stale quote, wrong location...). */
    public const CATEGORY_CONTRACT_FLAGGED = 'contract_flagged';
    /** A contract quoted an appraisal key that did not resolve. */
    public const CATEGORY_CONTRACT_UNMATCHED = 'contract_unmatched';
    public const CATEGORY_CONTRACT_COMPLETED = 'contract_completed';
    public const CATEGORY_CONTRACT_CANCELLED = 'contract_cancelled';
    /** A matched contract has sat unaccepted past the auto-nudge window. */
    public const CATEGORY_CONTRACT_NUDGE = 'contract_nudge';

    public const ALL_CATEGORIES = [
        self::CATEGORY_CONTRACT_MATCHED,
        self::CATEGORY_CONTRACT_FLAGGED,
        self::CATEGORY_CONTRACT_UNMATCHED,
        self::CATEGORY_CONTRACT_COMPLETED,
        self::CATEGORY_CONTRACT_CANCELLED,
        self::CATEGORY_CONTRACT_NUDGE,
    ];

    /**
     * Human-facing description of every category: the label an operator
     * reads, a one-line explanation, and the buyback.* events that route to
     * it. Single source of truth for the webhook form, the routing map and
     * anywhere else a category needs a name rather than a key.
     *
     * @return array<string, array{label: string, help: string, events: array}>
     */
    public static function categoryMeta(): array
    {
        return [
            self::CATEGORY_CONTRACT_MATCHED => [
                'label' => 'Contract matched',
                'help' => 'A contract matched its appraisal key cleanly, with nothing to review.',
                'events' => ['buyback.contract.matched'],
            ],
            self::CATEGORY_CONTRACT_COMPLETED => [
                'label' => 'Buyback completed',
                'help' => 'The contract was accepted in game and the buyback is done.',
                'events' => ['buyback.contract.completed'],
            ],
            self::CATEGORY_CONTRACT_FLAGGED => [
                'label' => 'Needs review',
                'help' => 'Matched, but something is off: price drift, a stale quote, the wrong location, a reused key or mismatched items.',
                'events' => ['buyback.contract.flagged'],
            ],
            self::CATEGORY_CONTRACT_UNMATCHED => [
                'label' => 'Key did not resolve',
                'help' => 'Someone quoted an appraisal key that does not exist, has expired, or belongs to another corporation.',
                'events' => ['buyback.contract.unmatched'],
            ],
            self::CATEGORY_CONTRACT_CANCELLED => [
                'label' => 'Contract cancelled',
                'help' => 'The contract was cancelled, rejected or allowed to lapse.',
                'events' => ['buyback.contract.cancelled'],
            ],
            self::CATEGORY_CONTRACT_NUDGE => [
                'label' => 'Waiting too long',
                'help' => 'A matched contract is still sitting unaccepted past the auto-nudge window.',
                'events' => ['buyback.contract.nudge'],
            ],
        ];
    }

    /**
     * The categories arranged into labelled boxes, so the form reads by
     * concern instead of as one flat list of keys.
     *
     * @return array<int, array{title: string, icon: string, keys: array}>
     */
    public static function categoryGroups(): array
    {
        return [
            [
                'title' => 'Normal buyback flow',
                'icon' => 'fa-exchange-alt',
                'keys' => [self::CATEGORY_CONTRACT_MATCHED, self::CATEGORY_CONTRACT_COMPLETED],
            ],
            [
                'title' => 'Needs a director',
                'icon' => 'fa-exclamation-triangle',
                'keys' => [self::CATEGORY_CONTRACT_FLAGGED, self::CATEGORY_CONTRACT_UNMATCHED],
            ],
            [
                'title' => 'Stalled and cancelled',
                'icon' => 'fa-clock',
                'keys' => [self::CATEGORY_CONTRACT_CANCELLED, self::CATEGORY_CONTRACT_NUDGE],
            ],
        ];
    }

    /**
     * Readable label for a category key, falling back to the raw key.
     */
    public static function categoryLabel(string $category): string
    {
        return self::categoryMeta()[$category]['label'] ?? $category;
    }

    /**
     * Readable label for a fully qualified event name (buyback.contract.*),
     * resolved through whichever category routes it. Falls back to the last
     * segment of the event name so nothing ever renders as a bare key.
     */
    public static function eventLabel(string $eventName): string
    {
        foreach (self::categoryMeta() as $meta) {
            if (in_array($eventName, $meta['events'], true)) {
                return $meta['label'];
            }
        }

        $tail = substr($eventName, strrpos($eventName, '.') + 1);

        return ucfirst(str_replace('_', ' ', $tail));
    }

    protected $table = 'buyback_webhooks';

    protected $fillable = [
        'corporation_id',
        'name',
        'url',
        'enabled',
        'role_mention',
        'categories',
    ];

    protected $casts = [
        'corporation_id' => 'integer',
        'enabled' => 'boolean',
        'categories' => 'array',
    ];

    public function corporation(): BelongsTo
    {
        return $this->belongsTo(CorporationInfo::class, 'corporation_id', 'corporation_id');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(BuybackNotificationLog::class, 'webhook_id');
    }

    public function scopeEnabled($query)
    {
        return $query->where('enabled', true);
    }

    public function scopeForCategory($query, string $category)
    {
        // JSON array contains check — works on MySQL 5.7+ / MariaDB 10.2+.
        // The categories column is cast to array on read; this scope
        // uses JSON_CONTAINS for DB-level filtering rather than a
        // PHP-side post-filter.
        return $query->whereJsonContains('categories', $category);
    }

    public function scopeMatchingCorp($query, int $corporationId)
    {
        return $query->where(function ($q) use ($corporationId) {
            $q->whereNull('corporation_id')
              ->orWhere('corporation_id', $corporationId);
        });
    }
}
