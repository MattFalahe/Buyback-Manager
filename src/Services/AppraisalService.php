<?php

namespace BuybackManager\Services;

use BuybackManager\Models\BuybackSetting;
use Seat\Eveapi\Models\Sde\InvType;

class AppraisalService
{
    protected PricingService $pricingService;

    public function __construct(PricingService $pricingService)
    {
        $this->pricingService = $pricingService;
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

            // Get market price
            $regionId = $setting->price_source === 'jita' 
                ? PricingService::JITA_REGION_ID 
                : $setting->region_id;

            $marketPrice = $this->pricingService->getPrice($typeId, $regionId, 'sell');

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
