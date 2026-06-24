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
    public const TARGET_MY_CORP = 'my_corp'; // member's own corporation (was "public")
    public const TARGET_CORP = 'corp';       // a specific (possibly external) corporation
    public const TARGET_PLAYER = 'player';   // a designated character (was "private")

    protected $table = 'buyback_settings';

    protected $fillable = [
        'corporation_id',
        'character_id',
        'enabled',
        'base_percentage',
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
        'buyback_mode',
        'target_type',
        'target_corporation_id',
        'target_corporation_name',
        'offer_lock_hours',
        'private_auto_nudge_hours',
        'public_page_enabled',
        'public_show_rates',
        'public_show_all_rules',
        'public_show_pricing_detail',
        'public_layout',
        'public_appraisal_enabled',
        'public_headline',
        'public_blurb',
        'public_accent_color',
        'public_overlay_opacity',
        'public_background_path',
        'public_logo_path',
        'public_logo_bg',
        'public_logo_style',
        'public_footer_text',
    ];

    protected $casts = [
        'corporation_id' => 'integer',
        'character_id' => 'integer',
        'enabled' => 'boolean',
        'base_percentage' => 'decimal:2',
        'region_id' => 'integer',
        'fallback_to_jita' => 'boolean',
        'price_cache_ttl_minutes' => 'integer',
        'target_corporation_id' => 'integer',
        'offer_lock_hours' => 'integer',
        'private_auto_nudge_hours' => 'integer',
        'public_page_enabled' => 'boolean',
        'public_show_rates' => 'boolean',
        'public_show_all_rules' => 'boolean',
        'public_show_pricing_detail' => 'boolean',
        'public_appraisal_enabled' => 'boolean',
        'public_logo_bg' => 'boolean',
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
     * Returns:
     *   ['type' => 'corporation'|'character', 'feed_id' => int, 'assignee_id' => int]
     * or null for an instructions-only target (BB can't see the feed).
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
     * True when BB cannot see the target's contract feed (an external,
     * free-text corporation target). Such offers stay pending until an
     * operator confirms them by hand, so the public instructions say so.
     */
    public function isInstructionsOnly(): bool
    {
        return $this->resolveSyncSource() === null;
    }

    /**
     * Build the config-aware "how to sell to us" steps shown on the public
     * page. The wording follows the corp's contract target so a member
     * always sees the right assignee, lock window, and confirmation note.
     *
     * @return array<int, string>
     */
    public function publicContractInstructions(): array
    {
        $label = $this->targetDisplayLabel();
        $lockHours = (int) ($this->offer_lock_hours ?: 24);

        $closing = $this->isInstructionsOnly()
            ? 'We confirm the contract by hand and pay out the locked value.'
            : 'We detect the contract automatically and pay out the locked value.';

        $steps = [
            'Paste your items into the appraisal tool and log in to publish an offer.',
            'You get a locked price and a short offer id (for example bb-zj2cc262), held for ' . $lockHours . ' hours.',
            'Create an in-game item exchange contract to ' . $label . '.',
            'Paste the offer id into the contract Description.',
            $closing,
        ];

        // When the corp restricts buyback to specific locations, spell that
        // out right after the "create the contract" step so members do not
        // get rejected after hauling.
        $locations = $this->allowedLocationLabels();
        if (! empty($locations)) {
            array_splice($steps, 3, 0, [
                'Create the contract at one of our buyback locations: ' . implode('; ', $locations) . '. Contracts made anywhere else are rejected.',
            ]);
        }

        return $steps;
    }

    /**
     * True when this corp restricts buyback to specific locations.
     */
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

    /**
     * Convenience relation: the designated character (player target).
     */
    public function character()
    {
        return $this->belongsTo(\Seat\Eveapi\Models\Character\CharacterInfo::class, 'character_id', 'character_id');
    }

    /**
     * Derive the legacy public/private mode from the target type, so the
     * offer-visibility layer (which keys on mode) stays consistent.
     */
    public function derivedMode(): string
    {
        return ($this->target_type ?? self::TARGET_MY_CORP) === self::TARGET_PLAYER
            ? 'private'
            : 'public';
    }

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

        return [
            'percentage' => (float) ($rule?->percentage ?? $this->base_percentage),
            'price_side' => $rule?->price_side ?: null,
            'rule_type' => $rule?->type ?? 'base',
        ];
    }
}
