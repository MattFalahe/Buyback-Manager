<?php

namespace BuybackManager\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use BuybackManager\Services\ContractService;

class SyncContracts implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(ContractService $contractService)
    {
        logger()->info('Buyback Manager - Starting contract sync');
        
        try {
            $contractService->syncContracts();
            logger()->info('Buyback Manager - Contract sync completed');
        } catch (\Exception $e) {
            logger()->error('Buyback Manager - Contract sync failed: ' . $e->getMessage());
            throw $e;
        }
    }
}
