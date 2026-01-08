<?php

namespace BuybackManager\Services;

use BuybackManager\Models\BuybackSetting;
use Seat\Eveapi\Models\Sde\InvType;
use Illuminate\Support\Facades\Log;

class AppraisalService
{
    protected PricingService $pricingService;
    protected $bridge;
    protected $useManagerCore;

    public function __construct(PricingService $pricingService)
    {
        $this->pricingService = $pricingService;

        // Check if Manager Core is available
        try {
            $this->bridge = app(\ManagerCore\Services\PluginBridge::class);
            $this->useManagerCore = $this->bridge->hasCapability('ManagerCore', 'pricing.getPrice');

            if ($this->useManagerCore) {
                Log::info('[Buyback Manager] Using Manager Core for pricing');
            }
        } catch (\Exception $e) {
            $this->useManagerCore = false;
            Log::info('[Buyback Manager] Manager Core not available, using local pricing');
        }
    }

    public function appraise(array $items, int $corporationId): array
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

        // Subscribe to types if using Manager Core
        if ($this->useManagerCore) {
            try {
                $typeIds = array_column($items, 'type_id');
                $market = $setting->price_source === 'jita' ? 'jita' : 'jita';
                $this->bridge->call('ManagerCore', 'pricing.subscribeTypes', ['BuybackManager', $typeIds, $market, 5]);
                Log::info('[Buyback Manager] Subscribed ' . count($typeIds) . ' types to Manager Core');
            } catch (\Exception $e) {
                Log::warning('[Buyback Manager] Failed to subscribe types: ' . $e->getMessage());
            }
        }

        $appraisal = [];
        $totalValue = 0;
        $totalMarketValue = 0;

        foreach ($items as $item) {
            $typeId = $item['type_id'];
            $quantity = $item['quantity'];

            // Get item info from SDE
            $type = InvType::find($typeId);
            if (!$type) {
                continue;
            }

            $categoryId = $type->group->categoryID ?? null;
            $groupId = $type->groupID ?? null;

            // Get buyback percentage for this item
            $percentage = $setting->getPercentageForItem($typeId, $categoryId, $groupId);

            if ($percentage === null) {
                // Item is excluded
                continue;
            }

            // Get market price - use Manager Core if available, otherwise fall back to local pricing
            if ($this->useManagerCore) {
                try {
                    // Manager Core uses market names (jita, amarr, etc.) instead of region IDs
                    $market = $setting->price_source === 'jita' ? 'jita' : 'jita'; // TODO: Map region_id to market name

                    $priceData = $this->bridge->call('ManagerCore', 'pricing.getPrice', [$typeId, $market, 'sell']);
                    $marketPrice = $priceData['price_min'] ?? $priceData['price_avg'] ?? null;

                    if (!$marketPrice) {
                        throw new \Exception("No price data returned from Manager Core");
                    }
                } catch (\Exception $e) {
                    Log::warning("[Buyback Manager] Failed to get price from Manager Core for type {$typeId}: " . $e->getMessage());
                    // Fall back to local pricing
                    $regionId = $setting->price_source === 'jita'
                        ? PricingService::JITA_REGION_ID
                        : $setting->region_id;
                    $marketPrice = $this->pricingService->getPrice($typeId, $regionId, 'sell');
                }
            } else {
                $regionId = $setting->price_source === 'jita'
                    ? PricingService::JITA_REGION_ID
                    : $setting->region_id;
                $marketPrice = $this->pricingService->getPrice($typeId, $regionId, 'sell');
            }

            if (!$marketPrice) {
                continue;
            }

            $buybackPrice = $marketPrice * ($percentage / 100);
            $itemTotal = $buybackPrice * $quantity;
            $marketTotal = $marketPrice * $quantity;

            $appraisal[] = [
                'type_id' => $typeId,
                'type_name' => $type->typeName,
                'quantity' => $quantity,
                'market_price' => $marketPrice,
                'buyback_price' => $buybackPrice,
                'percentage' => $percentage,
                'total_value' => $itemTotal,
                'market_value' => $marketTotal,
                'category_id' => $categoryId,
                'group_id' => $groupId,
            ];

            $totalValue += $itemTotal;
            $totalMarketValue += $marketTotal;
        }

        return [
            'success' => true,
            'items' => $appraisal,
            'total_value' => $totalValue,
            'total_market_value' => $totalMarketValue,
            'percentage_of_market' => $totalMarketValue > 0
                ? ($totalValue / $totalMarketValue) * 100
                : 0,
        ];
    }
}
