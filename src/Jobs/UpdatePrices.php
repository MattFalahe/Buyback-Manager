<?php

namespace BuybackManager\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use BuybackManager\Services\PricingService;
use BuybackManager\Models\BuybackContractItem;
use Seat\Eveapi\Models\Sde\InvType;

class UpdatePrices implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(PricingService $pricingService)
    {
        logger()->info('Buyback Manager - Starting price update');
        
        try {
            // Get all unique type_ids from recent contracts (last 30 days)
            $typeIds = BuybackContractItem::whereHas('contract', function ($query) {
                $query->where('issued_date', '>=', now()->subDays(30));
            })
            ->distinct()
            ->pluck('type_id')
            ->toArray();

            if (empty($typeIds)) {
                logger()->info('Buyback Manager - No items to update prices for');
                return;
            }

            logger()->info('Buyback Manager - Updating prices for ' . count($typeIds) . ' items');
            
            // Update Jita prices (default)
            $pricingService->updatePrices($typeIds);
            
            logger()->info('Buyback Manager - Price update completed');
        } catch (\Exception $e) {
            logger()->error('Buyback Manager - Price update failed: ' . $e->getMessage());
            throw $e;
        }
    }
}
