<?php

namespace BuybackManager\Jobs;

use BuybackManager\Services\ContractService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SyncContracts implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 2;

    public function handle(ContractService $contractService): void
    {
        $lock = Cache::lock('buyback-manager:sync-contracts', 900);

        if (! $lock->get()) {
            Log::info('[Buyback Manager] Sync skipped — another run is in progress.');
            return;
        }

        try {
            Log::info('[Buyback Manager] Starting contract sync');
            $contractService->syncContracts();
            Log::info('[Buyback Manager] Contract sync completed');
        } catch (\Throwable $e) {
            Log::error('[Buyback Manager] Contract sync failed: ' . $e->getMessage());
            throw $e;
        } finally {
            $lock->release();
        }
    }
}
