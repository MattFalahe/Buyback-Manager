<?php

namespace BuybackManager\Services;

use Illuminate\Support\Facades\Http;
use BuybackManager\Models\BuybackPrice;
use Seat\Eveapi\Models\Sde\InvType;

class PricingService
{
    const JITA_REGION_ID = 10000002;
    const JITA_STATION_ID = 60003760;

    public function updatePrices(array $typeIds, int $regionId = self::JITA_REGION_ID): void
    {
        // Batch type IDs to avoid hitting ESI rate limits
        $batches = array_chunk($typeIds, 100);

        foreach ($batches as $batch) {
            $this->fetchAndStorePrices($batch, $regionId);
            
            // Rate limiting - be nice to ESI
            sleep(1);
        }
    }

    private function fetchAndStorePrices(array $typeIds, int $regionId): void
    {
        try {
            $response = Http::get("https://esi.evetech.net/latest/markets/{$regionId}/orders/", [
                'datasource' => 'tranquility',
                'type_id' => implode(',', $typeIds),
            ]);

            if ($response->successful()) {
                $orders = $response->json();
                $this->processOrders($orders, $regionId);
            }
        } catch (\Exception $e) {
            logger()->error('Buyback Manager - Price fetch error: ' . $e->getMessage());
        }
    }

    private function processOrders(array $orders, int $regionId): void
    {
        $priceData = [];

        foreach ($orders as $order) {
            $typeId = $order['type_id'];
            $isBuyOrder = $order['is_buy_order'];
            $price = $order['price'];
            $volume = $order['volume_remain'];

            $key = $typeId . '_' . ($isBuyOrder ? 'buy' : 'sell');

            if (!isset($priceData[$key])) {
                $priceData[$key] = [
                    'type_id' => $typeId,
                    'region_id' => $regionId,
                    'price_type' => $isBuyOrder ? 'buy' : 'sell',
                    'price' => $price,
                    'volume' => $volume,
                    'count' => 1,
                ];
            } else {
                // Calculate weighted average
                $priceData[$key]['price'] = (
                    ($priceData[$key]['price'] * $priceData[$key]['count']) + $price
                ) / ($priceData[$key]['count'] + 1);
                $priceData[$key]['volume'] += $volume;
                $priceData[$key]['count']++;
            }
        }

        // Store in database
        foreach ($priceData as $data) {
            unset($data['count']); // Remove temporary count field
            $data['updated_at'] = now();

            BuybackPrice::updateOrCreate(
                [
                    'type_id' => $data['type_id'],
                    'region_id' => $data['region_id'],
                    'price_type' => $data['price_type'],
                ],
                $data
            );
        }
    }

    public function getPrice(int $typeId, int $regionId = self::JITA_REGION_ID, string $priceType = 'sell'): ?float
    {
        return BuybackPrice::getPrice($typeId, $regionId, $priceType);
    }
}
