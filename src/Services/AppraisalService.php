<?php

namespace BuybackManager\Services;

use BuybackManager\Models\BuybackSetting;
use BuybackManager\Services\Parsing\PasteParserService;
use BuybackManager\Services\Pricing\PriceProviderService;
use Illuminate\Support\Facades\Log;

/**
 * Turns a pasted list into a quote. The parser names the items, the price
 * chain gives both sides of each, and the corporation's rules say what share
 * of which side it pays.
 *
 * An item the rules leave out, or that nothing could price, is not accepted.
 * It is listed with the reason, never valued at zero or at a guess, so the
 * seller knows to leave it out of the contract.
 */
class AppraisalService
{
    /** A pricing rule leaves the item out. */
    public const NOT_ACCEPTED_EXCLUDED = 'excluded';

    /** No provider and no cached price had the side its rule pays on. */
    public const NOT_ACCEPTED_UNPRICED = 'unpriced';

    protected PasteParserService $parser;

    protected PriceProviderService $priceProvider;

    public function __construct(PasteParserService $parser, PriceProviderService $priceProvider)
    {
        $this->parser = $parser;
        $this->priceProvider = $priceProvider;
    }

    /**
     * Appraise a paste for a corporation's enabled buyback programme.
     */
    public function createAppraisal(string $rawInput, int $corporationId): array
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
            return $this->appraise($rawInput, $setting);
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

    public function appraise(string $rawInput, BuybackSetting $setting): array
    {
        $parsed = $this->parser->parse($rawInput);

        if (empty($parsed['items'])) {
            return [
                'success' => false,
                'message' => 'None of these lines named an item we know. Paste from an inventory, a contract or a fit, or put one item on each line with its quantity.',
            ];
        }

        $priced = $this->priceProvider->pricesFor($setting, array_column($parsed['items'], 'type_id'));
        $result = $this->applyRules($parsed['items'], $priced['prices'], $setting);

        if (empty($result['items'])) {
            return [
                'success' => false,
                'message' => $this->nothingAcceptedMessage($result['excluded']),
            ];
        }

        return [
            'success' => true,
            'items' => $result['items'],
            'excluded' => $result['excluded'],
            'unrecognised' => $parsed['unrecognised'],
            'truncated' => $parsed['truncated'],
            'total_market_value' => $result['total_market'],
            'total_buyback_value' => $result['total_buyback'],
            'average_percentage' => $result['total_market'] > 0
                ? ($result['total_buyback'] / $result['total_market']) * 100
                : 0,
            'corporation' => $setting->corporation,
            // The stored column holds 64 characters, and a Manager Core
            // market name can be longer.
            'market' => mb_substr($this->priceProvider->marketName($priced['market']), 0, 64),
            'provider' => $priced['provider'],
            'pricing_note' => $priced['note'],
            'raw_input' => $rawInput,
        ];
    }

    /**
     * Value each item by its rule. An item with no price on the side its rule
     * pays on is not accepted, with the reason.
     *
     * @param  array  $items   The parser's items
     * @param  array  $prices  [typeId => ['buy' => ?float, 'sell' => ?float]]
     */
    public function applyRules(array $items, array $prices, BuybackSetting $setting): array
    {
        $defaultSide = self::defaultSide($setting);

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
            $marketPrice = self::pickSidePrice($prices[$typeId] ?? null, $side);
            if ($marketPrice === null) {
                $notAccepted[] = self::notAccepted($item, self::NOT_ACCEPTED_UNPRICED);
                continue;
            }

            $percentage = (float) $rule['percentage'];
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
