<?php

namespace BuybackManager\Services;

use BuybackManager\Integrations\ManagerCoreIntegration;
use BuybackManager\Models\BuybackSetting;
use BuybackManager\Services\Parsing\StandaloneParserService;
use BuybackManager\Services\Pricing\PriceProviderService;
use Illuminate\Support\Facades\Log;
use Seat\Eveapi\Models\Sde\InvType;

/**
 * AppraisalService — orchestrates parsing + pricing + buyback-rule
 * application for both the manual paste UI and contract sync.
 *
 * Two public entry points, both single-pipeline:
 *
 *   createAppraisal(rawText, corpId)
 *     -> dispatch to provider-specific backend that returns parsed +
 *        priced items (sell_price already set), then apply buyback
 *        rules.
 *
 *   appraiseItems(structuredItems, corpId)
 *     -> always uses PriceProviderService for pricing (the configured
 *        provider's contract-sync path), then apply buyback rules.
 *
 * Provider dispatch for raw-text appraisal:
 *
 *   janice         -> PriceProviderService::appraiseRawViaJanice
 *                     (parses + prices in one Janice /appraisal call —
 *                      richest paste-format support: fitted ships,
 *                      EFT fits, BPCs, contract pastes)
 *   manager-core   -> MC's appraisal.create capability via bridge
 *                     (MC's ParserService + synchronous price refresh)
 *   default        -> StandaloneParserService for parsing + per-corp
 *                     PriceProviderService::getPrices for pricing
 *                     (basic but covers most paste formats)
 *
 * The buyback rule layer (applyBuybackRules) is shared across all three
 * paths so per-item percentages, exclusions, and category/group rules
 * behave identically regardless of provider.
 */
class AppraisalService
{
    protected StandaloneParserService $standaloneParser;

    protected PriceProviderService $priceProvider;

    public function __construct(
        StandaloneParserService $standaloneParser,
        PriceProviderService $priceProvider
    ) {
        $this->standaloneParser = $standaloneParser;
        $this->priceProvider = $priceProvider;
    }

    /**
     * Raw-text appraisal. Used by the manual paste UI.
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
            $provider = $setting->price_provider ?? PriceProviderService::PROVIDER_FUZZWORK;

            $source = match ($provider) {
                PriceProviderService::PROVIDER_JANICE => $this->appraiseViaJanice($rawInput, $setting),
                PriceProviderService::PROVIDER_MANAGER_CORE => $this->appraiseViaManagerCore($rawInput, $setting),
                default => $this->appraiseStandalone($rawInput, $setting),
            };

            if (! $source['success']) {
                return [
                    'success' => false,
                    'message' => $source['message'] ?? 'Could not build appraisal',
                ];
            }

            $result = $this->applyBuybackRules($source['items'], $setting);

            return [
                'success' => true,
                'items' => $result['items'],
                'total_market_value' => $result['total_market'],
                'total_buyback_value' => $result['total_buyback'],
                'average_percentage' => $result['total_market'] > 0
                    ? ($result['total_buyback'] / $result['total_market']) * 100
                    : 0,
                'corporation' => $setting->corporation,
                'market' => $this->resolveMarketLabel($setting),
                'raw_input' => $rawInput,
                'backend' => $source['backend'],
                'truncated' => $source['truncated'] ?? false,
            ];
        } catch (\Exception $e) {
            Log::error('[Buyback Manager] Failed to create appraisal', [
                'error' => $e->getMessage(),
                'corporation_id' => $corporationId,
            ]);

            return [
                'success' => false,
                'message' => 'Failed to create appraisal: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Structured-item appraisal. Used by ContractService for contract
     * sync. Items are already parsed by ESI; only pricing + rules apply.
     *
     * @param  array  $items  [['type_id' => int, 'quantity' => int], ...]
     */
    public function appraiseItems(array $items, int $corporationId): array
    {
        $setting = BuybackSetting::where('corporation_id', $corporationId)
            ->where('enabled', true)
            ->first();

        if (! $setting) {
            return ['success' => false, 'message' => 'Buyback not enabled for corporation'];
        }

        if (empty($items)) {
            return [
                'success' => true,
                'items' => [],
                'total_value' => 0,
                'market' => $this->resolveMarketLabel($setting),
            ];
        }

        try {
            $typeIds = array_values(array_unique(array_map(fn($i) => (int) $i['type_id'], $items)));

            $typeMeta = InvType::with('group')
                ->whereIn('typeID', $typeIds)
                ->get()
                ->keyBy('typeID');

            // Fetch BOTH sides so each rule can pick its own
            // (price_side: buy | sell | split, or null = setting default).
            $priceMap = $this->priceProvider->getPricesBothSides($typeIds, $setting);
            $defaultSide = $this->resolveDefaultSide($setting);

            $appraisedItems = [];
            $totalValue = 0;

            foreach ($items as $esiItem) {
                $typeId = (int) $esiItem['type_id'];
                $quantity = (int) $esiItem['quantity'];

                $type = $typeMeta->get($typeId);
                $groupId = $type?->groupID !== null ? (int) $type->groupID : null;
                $categoryId = $type?->group?->categoryID !== null ? (int) $type->group->categoryID : null;
                $typeName = $type?->typeName ?? 'Unknown';

                $ruleData = $setting->getRuleForItem($typeId, $categoryId, $groupId);
                if ($ruleData === null) {
                    continue; // excluded
                }

                $percentage = (float) $ruleData['percentage'];
                $side = $ruleData['price_side'] ?? $defaultSide;

                $sides = $priceMap[$typeId] ?? ['buy' => 0, 'sell' => 0];
                $marketPrice = $this->pickSidePrice($sides, $side);
                $buybackPrice = $marketPrice * ($percentage / 100);
                $lineTotal = $buybackPrice * $quantity;

                $appraisedItems[] = [
                    'type_id' => $typeId,
                    'type_name' => $typeName,
                    'group_id' => $groupId,
                    'category_id' => $categoryId,
                    'quantity' => $quantity,
                    'market_price' => $marketPrice,
                    'buyback_price' => $buybackPrice,
                    'percentage' => $percentage,
                    'price_side' => $side,
                    'total_value' => $lineTotal,
                ];

                $totalValue += $lineTotal;
            }

            return [
                'success' => true,
                'items' => $appraisedItems,
                'total_value' => $totalValue,
                'market' => $this->resolveMarketLabel($setting),
            ];
        } catch (\Exception $e) {
            Log::error('[Buyback Manager] Failed to appraise structured items', [
                'error' => $e->getMessage(),
                'corporation_id' => $corporationId,
            ]);

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ============================================================
    // BACKEND DISPATCH
    // ============================================================

    /**
     * Janice path — POST the raw paste to Janice's /appraisal endpoint
     * which parses AND prices in one round trip. Returns items as
     * stdClass with sell_price already populated.
     */
    protected function appraiseViaJanice(string $rawInput, BuybackSetting $setting): array
    {
        $result = $this->priceProvider->appraiseRawViaJanice($rawInput, $setting);
        if (! $result['success']) {
            return ['success' => false, 'backend' => 'janice', 'message' => $result['message']];
        }
        return ['success' => true, 'items' => $result['items'], 'backend' => 'janice'];
    }

    /**
     * Manager Core path — call MC's appraisal.create which parses via
     * MC's ParserService and synchronously refreshes prices via its
     * PricingService.
     *
     * MC's AppraisalItem model exposes buy_price + sell_price accessors
     * that read from the prices JSON column. We convert each model to a
     * plain stdClass exposing BOTH sides as scalar properties so
     * applyBuybackRules can pick per-rule (item->buy_price vs
     * item->sell_price). Setting properties on the Eloquent model
     * directly would have hit the attribute accessors and behaved
     * inconsistently.
     */
    protected function appraiseViaManagerCore(string $rawInput, BuybackSetting $setting): array
    {
        if (! ManagerCoreIntegration::isAvailable()) {
            return ['success' => false, 'backend' => 'manager-core', 'message' => 'Manager Core is not installed'];
        }

        $market = $setting->manager_core_market ?? 'jita';
        $appraisal = ManagerCoreIntegration::bridge()->call(
            'ManagerCore',
            'appraisal.create',
            $rawInput,
            [
                'market' => $market,
                'price_percentage' => 100,
            ]
        );

        if (! $appraisal) {
            return ['success' => false, 'backend' => 'manager-core', 'message' => 'Manager Core failed to create appraisal'];
        }

        $items = [];
        foreach ($appraisal->items as $mcItem) {
            $items[] = (object) [
                'type_id' => (int) $mcItem->type_id,
                'type_name' => (string) ($mcItem->type_name ?? 'Unknown'),
                'group_id' => $mcItem->group_id !== null ? (int) $mcItem->group_id : null,
                'category_id' => $mcItem->category_id !== null ? (int) $mcItem->category_id : null,
                'quantity' => (int) $mcItem->quantity,
                'buy_price' => (float) ($mcItem->buy_price ?? 0),
                'sell_price' => (float) ($mcItem->sell_price ?? 0),
                'total_volume' => (float) ($mcItem->total_volume ?? 0),
            ];
        }

        return ['success' => true, 'items' => $items, 'backend' => 'manager-core'];
    }

    /**
     * Standalone path — local parser for the text, PriceProviderService
     * for the prices. PriceProviderService will dispatch to whatever
     * backend the corp configured (default Fuzzwork) and apply the
     * full resilience chain (Jita fallback + local cache).
     */
    protected function appraiseStandalone(string $rawInput, BuybackSetting $setting): array
    {
        $parsed = $this->standaloneParser->parse($rawInput);

        if (! $parsed['success']) {
            return [
                'success' => false,
                'backend' => 'standalone',
                'message' => 'Could not resolve any items. Install Manager Core or configure Janice for richer paste-format support.',
            ];
        }

        $typeIds = array_values(array_unique(array_map(fn($i) => (int) $i->type_id, $parsed['items'])));
        // Both sides so per-rule price_side can pick later.
        $bothMap = $this->priceProvider->getPricesBothSides($typeIds, $setting);

        foreach ($parsed['items'] as $item) {
            $sides = $bothMap[$item->type_id] ?? ['buy' => 0, 'sell' => 0];
            $item->buy_price = (float) ($sides['buy'] ?? 0);
            $item->sell_price = (float) ($sides['sell'] ?? 0);
        }

        return [
            'success' => true,
            'items' => $parsed['items'],
            'backend' => 'standalone',
            'truncated' => $parsed['truncated'] ?? false,
        ];
    }

    // ============================================================
    // BUYBACK RULES (shared post-processing)
    // ============================================================

    /**
     * Walk parsed/priced items, apply the corp's per-item / per-group /
     * per-category buyback percentage rules, and produce the shared
     * item/total shape consumed by the view + contract sync.
     *
     * Each rule can specify its own price_side (buy / sell / split).
     * When a rule's price_side is null, the setting's default side
     * preference applies. Items must expose both buy_price and
     * sell_price properties so the rule can pick correctly.
     */
    protected function applyBuybackRules(iterable $items, BuybackSetting $setting): array
    {
        $defaultSide = $this->resolveDefaultSide($setting);

        $buybackItems = [];
        $totalMarket = 0;
        $totalBuyback = 0;

        foreach ($items as $item) {
            $groupId = $item->group_id !== null ? (int) $item->group_id : null;
            $categoryId = $item->category_id !== null ? (int) $item->category_id : null;
            $typeId = (int) $item->type_id;

            $ruleData = $setting->getRuleForItem($typeId, $categoryId, $groupId);
            if ($ruleData === null) {
                continue; // excluded
            }

            $percentage = (float) $ruleData['percentage'];
            $side = $ruleData['price_side'] ?? $defaultSide;

            $sides = [
                'buy' => (float) ($item->buy_price ?? 0),
                'sell' => (float) ($item->sell_price ?? 0),
            ];
            $marketPrice = $this->pickSidePrice($sides, $side);
            $buybackPrice = $marketPrice * ($percentage / 100);
            $quantity = (int) $item->quantity;

            $buybackItems[] = [
                'type_id' => $typeId,
                'type_name' => $item->type_name,
                'group_id' => $groupId,
                'category_id' => $categoryId,
                'quantity' => $quantity,
                'market_price' => $marketPrice,
                'buyback_price' => $buybackPrice,
                'percentage' => $percentage,
                'price_side' => $side,
                'total_market' => $marketPrice * $quantity,
                'total_buyback' => $buybackPrice * $quantity,
                'volume' => (float) ($item->total_volume ?? 0),
            ];

            $totalMarket += $marketPrice * $quantity;
            $totalBuyback += $buybackPrice * $quantity;
        }

        return [
            'items' => $buybackItems,
            'total_market' => $totalMarket,
            'total_buyback' => $totalBuyback,
        ];
    }

    /**
     * Pick the price for a single side from a [buy => float, sell => float]
     * pair. 'split' takes the midpoint of the two sides when both are
     * positive, otherwise the available side.
     */
    protected function pickSidePrice(array $sides, string $side): float
    {
        $buy = (float) ($sides['buy'] ?? 0);
        $sell = (float) ($sides['sell'] ?? 0);
        return match ($side) {
            'buy' => $buy,
            'split' => ($buy > 0 && $sell > 0) ? ($buy + $sell) / 2.0 : max($buy, $sell),
            default => $sell,
        };
    }

    /**
     * Resolve the setting-wide default side. Mirrors PriceProviderService::
     * resolveSidePreference logic so rules with price_side=null behave
     * consistently with the underlying pricing pipeline default.
     */
    protected function resolveDefaultSide(BuybackSetting $setting): string
    {
        if ($setting->price_provider === 'janice') {
            $m = $setting->janice_price_method ?? 'buy';
            return $m === 'split' ? 'split' : $m;
        }
        return 'sell';
    }

    /**
     * Human-readable market label for views. Prefers the provider's
     * configured market over the legacy price_source column.
     */
    protected function resolveMarketLabel(BuybackSetting $setting): string
    {
        return match ($setting->price_provider) {
            PriceProviderService::PROVIDER_JANICE => $setting->janice_market ?? 'jita',
            PriceProviderService::PROVIDER_MANAGER_CORE => $setting->manager_core_market ?? 'jita',
            default => $setting->price_source ?? 'jita',
        };
    }
}
