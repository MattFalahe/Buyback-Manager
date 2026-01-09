<?php

namespace BuybackManager\Services;

use BuybackManager\Models\BuybackSetting;
use Illuminate\Support\Facades\Log;

/**
 * AppraisalService - Handles buyback appraisals via Manager Core integration
 */
class AppraisalService
{
    protected $bridge;

    public function __construct()
    {
        $this->bridge = app(\ManagerCore\Services\PluginBridge::class);
    }

    /**
     * Create appraisal from raw input text
     *
     * @param string $rawInput
     * @param int $corporationId
     * @return array
     */
    public function createAppraisal(string $rawInput, int $corporationId): array
    {
        $setting = BuybackSetting::where('corporation_id', $corporationId)
            ->where('enabled', true)
            ->first();

        if (!$setting) {
            return [
                'success' => false,
                'message' => 'Buyback is not enabled for this corporation',
            ];
        }

        try {
            // Use Manager Core to parse and price items
            $market = $setting->price_source === 'jita' ? 'jita' : 'jita';

            Log::info('[Buyback Manager] Creating appraisal via Manager Core', [
                'corporation_id' => $corporationId,
                'market' => $market
            ]);

            $appraisal = $this->bridge->call('ManagerCore', 'appraisal.create', [
                $rawInput,
                [
                    'market' => $market,
                    'price_percentage' => 100, // Get 100% market value
                ]
            ]);

            if (!$appraisal) {
                throw new \Exception('Manager Core failed to create appraisal');
            }

            Log::info('[Buyback Manager] Received appraisal from Manager Core', [
                'item_count' => $appraisal->items->count(),
                'total_sell' => $appraisal->total_sell
            ]);

            // Convert Manager Core items to buyback format with corporate rules
            $buybackItems = [];
            $totalMarketValue = 0;
            $totalBuybackValue = 0;

            foreach ($appraisal->items as $item) {
                $marketPrice = $item->sell_price; // Manager Core's sell price (100%)

                // Get buyback percentage for this item
                $percentage = $this->getPercentageForItem(
                    $setting,
                    $item->type_id,
                    $item->group_id,
                    $item->category_id
                );

                $buybackPrice = $marketPrice * ($percentage / 100);
                $quantity = $item->quantity;

                $buybackItems[] = [
                    'type_id' => $item->type_id,
                    'type_name' => $item->type_name,
                    'quantity' => $quantity,
                    'market_price' => $marketPrice,
                    'buyback_price' => $buybackPrice,
                    'percentage' => $percentage,
                    'total_market' => $marketPrice * $quantity,
                    'total_buyback' => $buybackPrice * $quantity,
                    'volume' => $item->total_volume,
                ];

                $totalMarketValue += $marketPrice * $quantity;
                $totalBuybackValue += $buybackPrice * $quantity;
            }

            Log::info('[Buyback Manager] Applied buyback rules', [
                'corporation_id' => $corporationId,
                'item_count' => count($buybackItems),
                'total_market' => $totalMarketValue,
                'total_buyback' => $totalBuybackValue
            ]);

            return [
                'success' => true,
                'items' => $buybackItems,
                'total_market_value' => $totalMarketValue,
                'total_buyback_value' => $totalBuybackValue,
                'average_percentage' => $totalMarketValue > 0
                    ? ($totalBuybackValue / $totalMarketValue) * 100
                    : 0,
                'corporation' => $setting->corporation,
                'market' => $market,
                'raw_input' => $rawInput,
            ];

        } catch (\Exception $e) {
            Log::error('[Buyback Manager] Failed to create appraisal', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'message' => 'Failed to create appraisal: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Get buyback percentage for an item based on corporation rules
     *
     * @param BuybackSetting $setting
     * @param int $typeId
     * @param int|null $groupId
     * @param int|null $categoryId
     * @return float
     */
    protected function getPercentageForItem(
        BuybackSetting $setting,
        int $typeId,
        ?int $groupId,
        ?int $categoryId
    ): float {
        // Check for item-specific modifier
        $itemModifier = $setting->itemModifiers()
            ->where('type_id', $typeId)
            ->first();

        if ($itemModifier) {
            Log::debug("[Buyback Manager] Using item modifier for type {$typeId}: {$itemModifier->percentage}%");
            return $itemModifier->percentage;
        }

        // Check for group-specific modifier
        if ($groupId) {
            $groupModifier = $setting->groupModifiers()
                ->where('group_id', $groupId)
                ->first();

            if ($groupModifier) {
                Log::debug("[Buyback Manager] Using group modifier for group {$groupId}: {$groupModifier->percentage}%");
                return $groupModifier->percentage;
            }
        }

        // Check for category-specific modifier
        if ($categoryId) {
            $categoryModifier = $setting->categoryModifiers()
                ->where('category_id', $categoryId)
                ->first();

            if ($categoryModifier) {
                Log::debug("[Buyback Manager] Using category modifier for category {$categoryId}: {$categoryModifier->percentage}%");
                return $categoryModifier->percentage;
            }
        }

        // Use base percentage
        Log::debug("[Buyback Manager] Using base percentage for type {$typeId}: {$setting->base_percentage}%");
        return $setting->base_percentage;
    }
}
