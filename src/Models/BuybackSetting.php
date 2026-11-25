<?php

namespace BuybackManager\Models;

use BuybackManager\Models\Casts\EncryptedSecret;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
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

    /** SDE category of ore, ice and moon ore, compressed or not. */
    public const CATEGORY_ASTEROID = 25;
    public const DEFAULT_REFINE_YIELD = 90.0;

    /**
     * What the public page shows while it is off: a closed notice, for now or
     * for good, or a 404 for a corporation that would rather not show it has
     * a programme.
     */
    public const PUBLIC_CLOSED_TEMPORARY = 'temporary';
    public const PUBLIC_CLOSED_PERMANENT = 'permanent';
    public const PUBLIC_CLOSED_HIDDEN = 'hidden';

    protected $table = 'buyback_manager_settings';

    protected $fillable = [
        'corporation_id',
        'character_id',
        'enabled',
        'base_percentage',
        'refine_yield',
        'buy_listed_only',
        'price_source',
        'region_id',
        'price_provider',
        'fallback_provider',
        'janice_api_key',
        'janice_market',
        'janice_price_method',
        'manager_core_market',
        'manager_core_provider',
        'fallback_to_jita',
        'price_cache_ttl_minutes',
        'target_type',
        'target_corporation_id',
        'target_corporation_name',
        'max_deviation_percent',
        'thin_market_guard_percent',
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
        'public_closed_state',
        'public_closed_message',
    ];

    /**
     * Kept out of anything that serialises a setting. The settings page says
     * whether a key is saved and never echoes it back.
     */
    protected $hidden = [
        'janice_api_key',
    ];

    protected $casts = [
        'corporation_id' => 'integer',
        'janice_api_key' => EncryptedSecret::class,
        'character_id' => 'integer',
        'enabled' => 'boolean',
        'base_percentage' => 'decimal:2',
        'refine_yield' => 'decimal:2',
        'buy_listed_only' => 'boolean',
        'region_id' => 'integer',
        'fallback_to_jita' => 'boolean',
        'price_cache_ttl_minutes' => 'integer',
        'target_corporation_id' => 'integer',
        'max_deviation_percent' => 'decimal:2',
        'thin_market_guard_percent' => 'decimal:2',
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

    public function access()
    {
        return $this->hasMany(BuybackProgrammeAccess::class, 'setting_id');
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
     * Who the contract goes to, in the fields HR Manager reads to decide how
     * a completed buyback is credited. A free-text external corporation has
     * no id, so only its type is known.
     *
     * @return array{target_type: string, target_corporation_id: ?int, target_character_id: ?int}
     */
    public function targetIdentity(): array
    {
        $type = $this->target_type ?: self::TARGET_MY_CORP;

        return [
            'target_type' => $type,
            'target_corporation_id' => match ($type) {
                self::TARGET_PLAYER => null,
                self::TARGET_CORP => $this->target_corporation_id ? (int) $this->target_corporation_id : null,
                default => (int) $this->corporation_id,
            },
            'target_character_id' => $type === self::TARGET_PLAYER && $this->character_id
                ? (int) $this->character_id
                : null,
        ];
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
     * Human labels for the allowed locations, for instructions + display,
     * with the haul fee where one is charged.
     *
     * @return array<int, string>
     */
    public function allowedLocationLabels(): array
    {
        return $this->locationRules()
            ->orderBy('location_type')
            ->orderBy('location_name')
            ->get()
            ->map(fn ($r) => self::locationLabel($r)
                . ((float) $r->haul_fee_per_m3 > 0 ? ', haul fee ' . self::iskPerM3((float) $r->haul_fee_per_m3) : ''))
            ->all();
    }

    public function chargesHaulFees(): bool
    {
        return $this->locationRules()->where('haul_fee_per_m3', '>', 0)->exists();
    }

    /**
     * What to set as the contract price at each location that charges a haul
     * fee: the quote less the fee on its volume. Anywhere else the quote
     * stands as it is.
     *
     * @return array<int, array{location: string, per_m3: float, fee: float, price: float}>
     */
    public function haulFeeQuotes(float $quote, float $volume): array
    {
        return $this->locationRules()
            ->where('haul_fee_per_m3', '>', 0)
            ->orderBy('location_type')
            ->orderBy('location_name')
            ->get()
            ->map(function ($r) use ($quote, $volume) {
                $fee = self::haulFee((float) $r->haul_fee_per_m3, $volume, $quote);

                return [
                    'location' => self::locationLabel($r),
                    'per_m3' => (float) $r->haul_fee_per_m3,
                    'fee' => $fee,
                    'price' => round($quote - $fee, 2),
                ];
            })
            ->all();
    }

    /**
     * The fee on a quote's volume, never more than the quote: a fee that ate
     * the whole value would ask the seller to pay to sell.
     */
    public static function haulFee(float $perM3, float $volume, float $quote): float
    {
        return round(min(max(0.0, $quote), max(0.0, $perM3) * max(0.0, $volume)), 2);
    }

    public static function iskPerM3(float $perM3): string
    {
        return rtrim(rtrim(number_format($perM3, 2), '0'), '.') . ' ISK/m3';
    }

    protected static function locationLabel(BuybackLocationRule $rule): string
    {
        return ($rule->location_name ?: ('#' . $rule->location_id)) . ' (' . $rule->location_type . ')';
    }

    // ------------------------------------------------------------
    // Public page
    // ------------------------------------------------------------

    /** Whether the public page sells: switched on, and the programme with it. */
    public function publicPageOpen(): bool
    {
        return (bool) $this->public_page_enabled && (bool) $this->enabled;
    }

    /** What the page shows while it is off, temporarily closed unless set otherwise. */
    public function publicClosedState(): string
    {
        return in_array($this->public_closed_state, [self::PUBLIC_CLOSED_PERMANENT, self::PUBLIC_CLOSED_HIDDEN], true)
            ? $this->public_closed_state
            : self::PUBLIC_CLOSED_TEMPORARY;
    }

    /** The corporation's own words for the closed page, or the default for its state. */
    public function publicClosedMessage(): string
    {
        $own = trim((string) $this->public_closed_message);

        return $own !== '' ? $own : self::defaultClosedMessage($this->publicClosedState());
    }

    public static function defaultClosedMessage(string $state): string
    {
        return $state === self::PUBLIC_CLOSED_PERMANENT
            ? 'This buyback has closed for good. Thank you to everyone who sold to us.'
            : "We aren't buying right now. Check back soon.";
    }

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
            $this->chargesHaulFees()
                ? 'Set the price to the quoted value for the location you contract from, since a haul fee comes off at some of them, and paste the appraisal key into the contract Description.'
                : 'Set the price to the quoted value, and paste the appraisal key into the contract Description.',
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
     * Corp ticker from SeAT, shown on the public page. Falls back to the
     * corp id when the corporation_infos row is absent.
     */
    public function getCorpTickerAttribute(): string
    {
        return optional($this->corporation)->ticker ?: ('#' . $this->corporation_id);
    }

    /**
     * The name in the public link: whoever the contract goes to, since that
     * is who sellers deal with. The programme's own ticker when it buys for
     * its own corporation, the target corporation's ticker (or the name typed
     * for it) when it buys for another, the character's name when it buys
     * for a player. The programme's ticker stands in while SeAT does not
     * know the target yet.
     */
    public function getPublicSlugAttribute(): string
    {
        $slug = match ($this->target_type ?: self::TARGET_MY_CORP) {
            self::TARGET_CORP => optional($this->targetCorporation)->ticker
                ?: Str::slug((string) $this->target_corporation_name),
            self::TARGET_PLAYER => Str::slug((string) optional($this->character)->name),
            default => '',
        };

        return $slug !== '' ? $slug : $this->corp_ticker;
    }

    /**
     * The name under the logo on the public page, after the same rule as the
     * link but as sellers read it: a ticker for a corporation SeAT knows, the
     * name typed for one it does not, the character's name for a player.
     */
    public function publicTargetBadge(): string
    {
        $badge = match ($this->target_type ?: self::TARGET_MY_CORP) {
            self::TARGET_CORP => optional($this->targetCorporation)->ticker
                ?: trim((string) $this->target_corporation_name),
            self::TARGET_PLAYER => trim((string) optional($this->character)->name),
            default => '',
        };

        return $badge !== '' ? $badge : $this->corp_ticker;
    }

    /**
     * Public landing page URL for this corporation's buyback programme.
     */
    public function getPublicUrlAttribute(): string
    {
        return route('buyback-manager.public.show', ['ticker' => $this->public_slug]);
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
     *     'rule_name'  => ?string,         the rule's name for sellers
     *     'refine_yield' => ?float,        pay on the reprocessed
     *                                       materials at this yield, or
     *                                       null for the item's own price
     *   ]
     *
     * A rule for this exact item decides, excluded or not. Otherwise an
     * exclusion on its group or category wins over any rate, and then the
     * group's rate beats the category's. Priority plays no part, so a rule
     * stored with an odd priority cannot turn that order round. Falls back to
     * base_percentage with null price_side if no rule matches.
     *
     * The rules are read once per setting and matched in memory, so a quote
     * with hundreds of items costs one query, not one per item.
     */
    public function getRuleForItem(int $typeId, ?int $categoryId = null, ?int $groupId = null): ?array
    {
        $find = fn (string $type, ?int $id) => $id
            ? $this->pricingRules->first(fn ($rule) => $rule->type === $type && (int) $rule->type_id === $id)
            : null;

        $item = $find('item', $typeId);
        $group = $find('group', $groupId);
        $category = $find('category', $categoryId);

        if ($item === null && ($group?->excluded || $category?->excluded)) {
            return null;
        }

        $rule = $item ?? $group ?? $category;

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
            'rule_name' => $rule?->display_name ?: null,
            'refine_yield' => $rule ? $this->refineYieldFor($rule, $categoryId) : null,
        ];
    }

    /**
     * The yield a refining rule pays at for an item in this category, or null
     * for the item's own price. A rule's own yield applies to anything it
     * covers. The programme's yield is an ore yield, and modules reprocess
     * for far less, so it reaches only the Asteroid category.
     */
    public function refineYieldFor(BuybackPricingRule $rule, ?int $categoryId): ?float
    {
        if (! $rule->refined) {
            return null;
        }
        if ($rule->refine_yield !== null) {
            return (float) $rule->refine_yield;
        }

        return $categoryId === self::CATEGORY_ASTEROID ? $this->programmeRefineYield() : null;
    }

    public function programmeRefineYield(): float
    {
        return (float) ($this->refine_yield ?? self::DEFAULT_REFINE_YIELD);
    }
}
