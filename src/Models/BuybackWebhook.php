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
    public const CATEGORY_OFFER_PUBLISHED = 'offer_published';
    public const CATEGORY_OFFER_MATCHED = 'offer_matched';
    public const CATEGORY_OFFER_REJECTED = 'offer_rejected';
    public const CATEGORY_CONTRACT_UNMATCHED = 'contract_unmatched';
    public const CATEGORY_CONTRACT_COMPLETED = 'contract_completed';
    public const CATEGORY_CONTRACT_CANCELLED = 'contract_cancelled';

    public const ALL_CATEGORIES = [
        self::CATEGORY_OFFER_PUBLISHED,
        self::CATEGORY_OFFER_MATCHED,
        self::CATEGORY_OFFER_REJECTED,
        self::CATEGORY_CONTRACT_UNMATCHED,
        self::CATEGORY_CONTRACT_COMPLETED,
        self::CATEGORY_CONTRACT_CANCELLED,
    ];

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
