<?php

namespace BuybackManager\Services;

use BuybackManager\Models\BuybackAppraisal;
use BuybackManager\Models\BuybackSetting;
use BuybackManager\Services\Parsing\PasteParserService;
use BuybackManager\Services\Pricing\PriceProviderService;
use BuybackManager\Services\Pricing\Reprocessing;
use Illuminate\Support\Facades\Log;

/**
 * Turns a pasted list into a quote. The parser names the items, the price
 * chain gives both sides of each, and the corporation's rules say what share
 * of which side it pays.
 *
 * An item the rules leave out, or that nothing could price, is not accepted.
 * It is listed with the reason, never valued at zero or at a guess, so the
 * seller knows to leave it out of the contract.
 *
 * A top buy order far above the rest of the buy orders is what a thin market
 * looks like, and what someone placing a lone high order to inflate a quote
 * would leave. Past the corporation's guard, a Fuzzwork price gives way to
 * the average of the top 5% of buy orders, and the quote says so.
 */
class AppraisalService
{
    /** A pricing rule leaves the item out. */
    public const NOT_ACCEPTED_EXCLUDED = 'excluded';

    /** No provider and no cached price had the side its rule pays on. */
    public const NOT_ACCEPTED_UNPRICED = 'unpriced';

    protected PasteParserService $parser;

    protected PriceProviderService $priceProvider;

    protected Reprocessing $reprocessing;

    protected VolumeLookup $volumes;

    public function __construct(PasteParserService $parser, PriceProviderService $priceProvider, ?Reprocessing $reprocessing = null, ?VolumeLookup $volumes = null)
    {
        $this->parser = $parser;
        $this->priceProvider = $priceProvider;
        $this->reprocessing = $reprocessing ?? new Reprocessing();
        $this->volumes = $volumes ?? new VolumeLookup();
    }

    /**
     * Appraise a paste for a corporation's enabled buyback programme, at
     * public rates or, for a signed-in member, member rates.
     */
    public function createAppraisal(string $rawInput, int $corporationId, string $tier = BuybackAppraisal::RATE_PUBLIC): array
    {
        $setting = BuybackSetting::where('corporation_id', $corporationId)
            ->where('enabled', true)
            ->first();

        if (! $setting) {
            return [
                'success' => false,
                'message' => 'Buyback is not enabled for this corporation',
            ];
        }

        try {
            return $this->appraise($rawInput, $setting, $tier);
        } catch (\Throwable $e) {
            Log::error('[Buyback Manager] Failed to create appraisal', [
                'error' => $e->getMessage(),
                'corporation_id' => $corporationId,
            ]);

            // Anyone can reach this from the public page, so the reason stays
            // in the log.
            return [
                'success' => false,
                'message' => 'The appraisal could not be completed. Please try again in a few minutes.',
            ];
        }
    }

    public function appraise(string $rawInput, BuybackSetting $setting, string $tier = BuybackAppraisal::RATE_PUBLIC): array
    {
        // A programme with no bonus has one set of rates, so nobody's quote
        // is recorded as a member quote there.
        $tier = $tier === BuybackAppraisal::RATE_MEMBER && $setting->hasMemberRates()
            ? BuybackAppraisal::RATE_MEMBER
            : BuybackAppraisal::RATE_PUBLIC;

        $parsed = $this->parser->parse($rawInput);

        if (empty($parsed['items'])) {
            return [
                'success' => false,
                'message' => 'None of these lines named an item we know. Paste from an inventory, a contract or a fit, or put one item on each line with its quantity.',
            ];
        }

        $recipes = $this->reprocessing->recipesForPaste($parsed['items'], $setting);
        $priced = $this->priceProvider->pricesFor($setting, Reprocessing::typesToPrice(array_column($parsed['items'], 'type_id'), $recipes));
        $result = $this->applyRules($parsed['items'], $priced['prices'], $setting, $recipes, $tier);

        if (empty($result['items'])) {
            // Told apart from a paste nobody trades, which would only get
            // the same answer again.
            if (PriceProviderService::nothingAnswered($priced)) {
                return [
                    'success' => false,
                    'no_prices' => true,
                    'message' => 'No price provider answered, so nothing could be priced. Try again in a few minutes.',
                ];
            }

            return [
                'success' => false,
                'message' => $this->nothingAcceptedMessage($result['excluded']),
            ];
        }

        $measured = $this->measure($result['items']);

        return [
            'success' => true,
            'items' => $measured['items'],
            'excluded' => $result['excluded'],
            'unrecognised' => $parsed['unrecognised'],
            'truncated' => $parsed['truncated'],
            'total_market_value' => $result['total_market'],
            'total_buyback_value' => $result['total_buyback'],
            'total_volume' => $measured['total_volume'],
            // Items counted assembled, since no packaged volume was known yet.
            'volume_assembled' => $measured['assembled'],
            'average_percentage' => $result['total_market'] > 0
                ? ($result['total_buyback'] / $result['total_market']) * 100
                : 0,
            'rate_tier' => $tier,
            'member_bonus' => $tier === BuybackAppraisal::RATE_MEMBER ? $setting->memberBonus() : 0.0,
            'corporation' => $setting->corporation,
            // The stored column holds 64 characters, and a Manager Core
            // market name can be longer.
            'market' => mb_substr($this->priceProvider->pricedAtName($priced['market'], $priced['via'] ?? ''), 0, 64),
            'provider' => $priced['provider'],
            'pricing_note' => $priced['note'],
            'raw_input' => $rawInput,
        ];
    }

    /**
     * Value each item by its rule. An item with no price on the side its rule
     * pays on is not accepted, with the reason. Under a refining rule the
     * item's market price is what its materials fetch at the rule's yield;
     * an item that reprocesses into nothing is priced as itself. At member
     * rates every rule pays the member bonus on top.
     *
     * @param  array  $items    The parser's items
     * @param  array  $prices   [typeId => ['buy' => ?float, 'sell' => ?float]]
     * @param  array  $recipes  Reprocessing::recipesFor() of the refined items
     */
    public function applyRules(array $items, array $prices, BuybackSetting $setting, array $recipes = [], string $tier = BuybackAppraisal::RATE_PUBLIC): array
    {
        $member = $tier === BuybackAppraisal::RATE_MEMBER;
        $defaultSide = self::defaultSide($setting);
        [$prices, $thin] = self::guardThinMarkets($prices, (float) ($setting->thin_market_guard_percent ?? 10));

        $accepted = [];
        $notAccepted = [];
        $totalMarket = 0.0;
        $totalBuyback = 0.0;

        foreach ($items as $item) {
            $typeId = (int) $item['type_id'];
            $quantity = (int) $item['quantity'];

            $rule = $setting->getRuleForItem($typeId, $item['category_id'] ?? null, $item['group_id'] ?? null);
            if ($rule === null) {
                $notAccepted[] = self::notAccepted($item, self::NOT_ACCEPTED_EXCLUDED);
                continue;
            }

            $side = $rule['price_side'] ?? $defaultSide;
            $yield = ($rule['refine_yield'] ?? null) !== null && isset($recipes[$typeId]) ? (float) $rule['refine_yield'] : null;
            $marketPrice = $yield !== null
                ? Reprocessing::unitValue($recipes[$typeId], $yield, $prices, $side)
                : self::pickSidePrice($prices[$typeId] ?? null, $side);
            if ($marketPrice === null) {
                $notAccepted[] = self::notAccepted($item, self::NOT_ACCEPTED_UNPRICED);
                continue;
            }

            // A thin buy side only moves a price that reads the buy side.
            $thinMarket = $side !== 'sell' && ($yield !== null
                ? array_intersect_key($recipes[$typeId]['materials'], $thin) !== []
                : isset($thin[$typeId]));

            $percentage = (float) $rule['percentage'];
            if ($member) {
                $percentage = $setting->memberPercentage($percentage);
            }
            $buybackPrice = $marketPrice * ($percentage / 100);

            $accepted[] = [
                'type_id' => $typeId,
                'type_name' => $item['type_name'],
                'group_id' => $item['group_id'] ?? null,
                'category_id' => $item['category_id'] ?? null,
                'quantity' => $quantity,
                'market_price' => $marketPrice,
                'buyback_price' => $buybackPrice,
                'percentage' => $percentage,
                'price_side' => $side,
                'refine_yield' => $yield,
                'rule_name' => $rule['rule_name'] ?? null,
                'thin_market' => $thinMarket,
                'total_market' => $marketPrice * $quantity,
                'total_buyback' => $buybackPrice * $quantity,
            ];

            $totalMarket += $marketPrice * $quantity;
            $totalBuyback += $buybackPrice * $quantity;
        }

        return [
            'items' => $accepted,
            'excluded' => $notAccepted,
            'total_market' => $totalMarket,
            'total_buyback' => $totalBuyback,
        ];
    }

    /**
     * Pass over a buy price standing more than $guardPercent above the
     * average of the top 5% of buy orders, for that average. Only a Fuzzwork
     * price comes with the average; any other price stands.
     *
     * @return array{0: array, 1: array<int, true>}  the prices, and the items guarded
     */
    public static function guardThinMarkets(array $prices, float $guardPercent): array
    {
        $thin = [];
        if ($guardPercent <= 0) {
            return [$prices, $thin];
        }

        foreach ($prices as $typeId => $sides) {
            $buy = $sides['buy'] ?? null;
            $typical = $sides['buy_percentile'] ?? null;
            if ($buy !== null && $typical !== null && $typical > 0 && $buy > $typical * (1 + $guardPercent / 100)) {
                $prices[$typeId]['buy'] = (float) $typical;
                $thin[(int) $typeId] = true;
            }
        }

        return [$prices, $thin];
    }

    /**
     * Each accepted item's m3, and the quote's, for the haul fee and to show
     * what the seller has to haul.
     *
     * @return array{items: array, total_volume: float, assembled: string[]}
     */
    protected function measure(array $items): array
    {
        try {
            $found = $this->volumes->unitVolumes(array_column($items, 'type_id'));
        } catch (\Throwable $e) {
            // No volume means no haul fee, never a failed quote.
            Log::warning('[Buyback Manager] Could not read item volumes: ' . $e->getMessage());
            $found = ['volumes' => [], 'assembled' => []];
        }

        $total = 0.0;
        $assembled = [];
        foreach ($items as $i => $item) {
            $unit = (float) ($found['volumes'][$item['type_id']] ?? 0);
            $items[$i]['unit_volume'] = $unit;
            $total += $unit * $item['quantity'];
            if (in_array((int) $item['type_id'], $found['assembled'], true)) {
                $assembled[] = (string) $item['type_name'];
            }
        }

        return ['items' => $items, 'total_volume' => $total, 'assembled' => $assembled];
    }

    /**
     * The price on one side. A split takes the middle of the two, or the one
     * side there is. Null when the side has no price.
     */
    public static function pickSidePrice(?array $sides, string $side): ?float
    {
        $buy = isset($sides['buy']) && $sides['buy'] > 0 ? (float) $sides['buy'] : null;
        $sell = isset($sides['sell']) && $sides['sell'] > 0 ? (float) $sides['sell'] : null;

        return match ($side) {
            'buy' => $buy,
            'split' => $buy !== null && $sell !== null ? ($buy + $sell) / 2 : ($buy ?? $sell),
            default => $sell,
        };
    }

    /**
     * The side a rule with no side of its own pays on: Janice's price method
     * when Janice is the provider, the sell side otherwise.
     */
    public static function defaultSide(BuybackSetting $setting): string
    {
        if ($setting->price_provider === PriceProviderService::PROVIDER_JANICE) {
            $method = $setting->janice_price_method ?: 'buy';

            return in_array($method, ['buy', 'sell', 'split'], true) ? $method : 'buy';
        }

        return 'sell';
    }

    protected static function notAccepted(array $item, string $reason): array
    {
        return [
            'type_id' => (int) $item['type_id'],
            'type_name' => $item['type_name'],
            'quantity' => (int) $item['quantity'],
            'reason' => $reason,
        ];
    }

    protected function nothingAcceptedMessage(array $notAccepted): string
    {
        $unpriced = count(array_filter($notAccepted, fn ($e) => $e['reason'] === self::NOT_ACCEPTED_UNPRICED));

        if ($unpriced === count($notAccepted)) {
            return 'No market price could be found for any of these items right now, so we cannot value this list. Please try again in a few minutes.';
        }

        if ($unpriced === 0) {
            return 'We do not buy any of these items. Ask a buyback director if you want a custom quote.';
        }

        $excluded = count($notAccepted) - $unpriced;

        return 'None of these items can be valued: we do not buy ' . $excluded . ' of them, and '
            . $unpriced . ' ' . ($unpriced === 1 ? 'has' : 'have') . ' no market price right now.';
    }
}
