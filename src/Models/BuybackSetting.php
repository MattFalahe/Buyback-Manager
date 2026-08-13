<?php

namespace BuybackManager\Models;

use Illuminate\Database\Eloquent\Model;
use Seat\Eveapi\Models\Corporation\CorporationInfo;

class BuybackSetting extends Model
{
    /**
     * Contract target types. Where the member's EVE item-exchange
     * contract should be sent.
     */
    public const TARGET_MY_CORP = 'my_corp'; // the programme's own corporation
    public const TARGET_CORP = 'corp';       // a specific (possibly external) corporation
    public const TARGET_PLAYER = 'player';   // a designated character

    protected $table = 'buyback_settings';

    protected $fillable = [
        'corporation_id',
        'character_id',
        'enabled',
        'base_percentage',
        'buy_listed_only',
        'price_source',
        'region_id',
        'price_provider',
        'janice_api_key',
        'janice_market',
        'janice_price_method',
        'manager_core_market',
        'manager_core_variant',
        'fallback_to_jita',
        'price_cache_ttl_minutes',
        'target_type',
        'target_corporation_id',
        'target_corporation_name',
        'max_deviation_percent',
        'appraisal_stale_hours',
        'appraisal_item_retention_days',
        'appraisal_retention_days',
        'auto_nudge_hours',
        'public_page_enabled',
        'public_show_rates',
        'public_show_all_rules',
        'public_show_pricing_detail',
        'public_appraisal_enabled',
        'public_layout',
        'public_headline',
        'public_blurb',
        'public_accent_color',
        'public_overlay_opacity',
        'public_background_path',
        'public_logo_path',
        'public_logo_style',
        'public_footer_text',
    ];

    protected $casts = [
        'corporation_id' => 'integer',
        'character_id' => 'integer',
        'enabled' => 'boolean',
        'base_percentage' => 'decimal:2',
        'buy_listed_only' => 'boolean',
        'region_id' => 'integer',
        'fallback_to_jita' => 'boolean',
        'price_cache_ttl_minutes' => 'integer',
        'target_corporation_id' => 'integer',
        'max_deviation_percent' => 'decimal:2',
        'appraisal_stale_hours' => 'integer',
        'appraisal_item_retention_days' => 'integer',
        'appraisal_retention_days' => 'integer',
        'auto_nudge_hours' => 'integer',
        'public_page_enabled' => 'boolean',
        'public_show_rates' => 'boolean',
        'public_show_all_rules' => 'boolean',
        'public_show_pricing_detail' => 'boolean',
        'public_appraisal_enabled' => 'boolean',
        'public_overlay_opacity' => 'integer',
    ];

    public function corporation()
    {
        return $this->belongsTo(CorporationInfo::class, 'corporation_id', 'corporation_id');
    }

    public function targetCorporation()
    {
        return $this->belongsTo(CorporationInfo::class, 'target_corporation_id', 'corporation_id');
    }

    /**
     * Convenience relation: the designated character (player target).
     */
    public function character()
    {
        return $this->belongsTo(\Seat\Eveapi\Models\Character\CharacterInfo::class, 'character_id', 'character_id');
    }

    public function pricingRules()
    {
        return $this->hasMany(BuybackPricingRule::class, 'setting_id');
    }

    public function locationRules()
    {
        return $this->hasMany(BuybackLocationRule::class, 'setting_id');
    }

    // ------------------------------------------------------------
    // Contract target helpers
    // ------------------------------------------------------------

    /**
     * Resolve WHICH ESI contract feed to read and WHICH assignee_id to
     * match, per the contract target. The feed must be the one the
     * contract actually appears in, which follows EVE's contract
     * visibility (only the parties who can see a contract have it in
     * their ESI feed):
     *
     *   my_corp  -> read the OWN CORP feed (corporation_contracts),
     *               assignee = own corp. The contract is sent to the corp
     *               and visible to corp members; it lands in the corp
     *               contracts endpoint SeAT already syncs.
     *
     *   corp     -> read the TARGET CORP feed, assignee = target corp.
     *               Only that corp's directors can see it, so SeAT must
     *               hold that corp's director token. A free-text external
     *               corp (no resolved id) returns null = instructions-only.
     *
     *   player   -> read the DESIGNATED CHARACTER feed (character_contracts),
     *               assignee = that character. A private contract to a
     *               character is only visible to the issuer + receiver, so
     *               we read the RECEIVER's (operator's) own character
     *               contracts. Requires SeAT to hold the operator's
     *               character token with esi-contracts.read_character_contracts.
     *
     * @return array{type: string, feed_id: int, assignee_id: int}|null
     */
    public function resolveSyncSource(): ?array
    {
        return match ($this->target_type ?? self::TARGET_MY_CORP) {
            self::TARGET_PLAYER => $this->character_id
                ? ['type' => 'character', 'feed_id' => (int) $this->character_id, 'assignee_id' => (int) $this->character_id]
                : null,
            self::TARGET_CORP => $this->target_corporation_id
                ? ['type' => 'corporation', 'feed_id' => (int) $this->target_corporation_id, 'assignee_id' => (int) $this->target_corporation_id]
                : null,
            default => ['type' => 'corporation', 'feed_id' => (int) $this->corporation_id, 'assignee_id' => (int) $this->corporation_id],
        };
    }

    /**
     * Human-readable "send to" label for instructions + listings.
     */
    public function targetDisplayLabel(): string
    {
        return match ($this->target_type ?? self::TARGET_MY_CORP) {
            self::TARGET_PLAYER => optional($this->character)->name
                ?? ('character #' . $this->character_id),
            self::TARGET_CORP => $this->target_corporation_name
                ?: (optional($this->targetCorporation)->name
                    ?? ('corporation #' . $this->target_corporation_id)),
            default => optional($this->corporation)->name
                ?? ('corporation #' . $this->corporation_id),
        };
    }

    /**
     * True when Buyback Manager cannot see the target's contract feed (an
     * external, free-text corporation target). Such contracts have to be
     * confirmed by hand.
     */
    public function isInstructionsOnly(): bool
    {
        return $this->resolveSyncSource() === null;
    }

    // ------------------------------------------------------------
    // Review thresholds
    // ------------------------------------------------------------

    /**
     * How far the asked ISK may drift from the quote before the contract
     * is flagged, as a percentage.
     */
    public function deviationTolerance(): float
    {
        return (float) ($this->max_deviation_percent ?? 1.0);
    }

    /**
     * Age in hours past which a quote is treated as stale, because market
     * prices may have moved since it was generated.
     */
    public function staleAfterHours(): int
    {
        return (int) ($this->appraisal_stale_hours ?: 48);
    }

    // ------------------------------------------------------------
    // Locations
    // ------------------------------------------------------------

    public function hasLocationRestriction(): bool
    {
        return $this->locationRules()->exists();
    }

    /**
     * Human labels for the allowed locations, for instructions + display.
     *
     * @return array<int, string>
     */
    public function allowedLocationLabels(): array
    {
        return $this->locationRules()
            ->orderBy('location_type')
            ->orderBy('location_name')
            ->get()
            ->map(fn ($r) => ($r->location_name ?: ('#' . $r->location_id)) . ' (' . $r->location_type . ')')
            ->all();
    }

    // ------------------------------------------------------------
    // Public page
    // ------------------------------------------------------------

    /**
     * Build the config-aware "how to sell to us" steps. The wording
     * follows the corporation's own setup so a seller always sees the
     * right destination, the right key instruction, and the locations we
     * accept from.
     *
     * @return array<int, string>
     */
    public function publicContractInstructions(): array
    {
        $label = $this->targetDisplayLabel();

        $closing = $this->isInstructionsOnly()
            ? 'We confirm the contract by hand, check it against your appraisal, and pay out.'
            : 'We detect the contract automatically, check it against your appraisal, and pay out.';

        $steps = [
            'Paste your items into the appraisal tool to get a quote and an appraisal key (for example bb-zj2cc262).',
            'Create an in-game item exchange contract to ' . $label . '.',
            'Set the price to the quoted value, and paste the appraisal key into the contract Description.',
        ];

        // When the corporation restricts where it buys, say so before the
        // seller hauls anywhere.
        $locations = $this->allowedLocationLabels();
        if (! empty($locations)) {
            $steps[] = 'Create the contract at one of our buyback locations: ' . implode('; ', $locations) . '.';
        }

        $steps[] = $closing;

        return $steps;
    }

    /**
     * Corp ticker from SeAT (for the public URL). Falls back to the corp
     * id when the corporation_infos row is absent.
     */
    public function getCorpTickerAttribute(): string
    {
        return optional($this->corporation)->ticker ?: ('#' . $this->corporation_id);
    }

    /**
     * Public landing page URL for this corporation's buyback programme.
     */
    public function getPublicUrlAttribute(): string
    {
        return route('buyback-manager.public.show', ['ticker' => $this->corp_ticker]);
    }

    // ------------------------------------------------------------
    // Pricing rules
    // ------------------------------------------------------------

    /**
     * Resolve the buyback rule that applies to an item: percentage,
     * which side of the spread to apply it to, and which rule (if any)
     * matched.
     *
     * Returns null when the item is EXCLUDED (no buyback). Otherwise:
     *   [
     *     'percentage' => float,           the percentage to multiply
     *     'price_side' => ?string,         'buy' | 'sell' | 'split'
     *                                       — null means "use the
     *                                       BuybackSetting default
     *                                       side preference"
     *     'rule_type'  => 'item' | 'group' | 'category' | 'base',
     *   ]
     *
     * Precedence: item > group > category > base. Falls back to
     * base_percentage with null price_side if no rule matches.
     */
    public function getRuleForItem(int $typeId, int $categoryId = null, int $groupId = null): ?array
    {
        $rule = $this->pricingRules()
            ->where(function ($query) use ($typeId, $categoryId, $groupId) {
                $query->where(function ($q) use ($typeId) {
                    $q->where('type', 'item')->where('type_id', $typeId);
                })
                ->orWhere(function ($q) use ($groupId) {
                    if ($groupId) {
                        $q->where('type', 'group')->where('type_id', $groupId);
                    }
                })
                ->orWhere(function ($q) use ($categoryId) {
                    if ($categoryId) {
                        $q->where('type', 'category')->where('type_id', $categoryId);
                    }
                });
            })
            ->orderBy('priority', 'desc')
            ->first();

        if ($rule && $rule->excluded) {
            return null;
        }

        // Allow-list mode: with no matching price exception the item is not
        // part of the programme at all. Report it as not accepted rather than
        // valuing it at the default rate, which would otherwise quote every
        // unlisted item at whatever the default happens to be.
        if ($rule === null && $this->buy_listed_only) {
            return null;
        }

        return [
            'percentage' => (float) ($rule?->percentage ?? $this->base_percentage),
            'price_side' => $rule?->price_side ?: null,
            'rule_type' => $rule?->type ?? 'base',
        ];
    }
}
