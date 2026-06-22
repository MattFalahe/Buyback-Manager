<?php

namespace BuybackManager\Jobs;

use BuybackManager\Services\OfferService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Sweeps pending offers past their expires_at and flips them to
 * status=expired. Publishes buyback.offer.expired for each one.
 *
 * Scheduled every 5 minutes via ScheduleSeeder. Idempotent: a no-op
 * when no offers are stale. Cache lock prevents two workers stomping
 * on the same set.
 *
 * Note: ContractService::syncContracts also calls
 * OfferService::expireStale at the start of each contract-sync cycle
 * (every 15 min), so this job is the BELT to the contract sync's
 * SUSPENDERS — pending offers get expired even if contract sync is
 * stalled or disabled.
 */
class ExpirePendingOffers implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public int $tries = 2;

    public function handle(OfferService $offerService): void
    {
        $lock = Cache::lock('buyback-manager:expire-offers', 180);

        if (! $lock->get()) {
            Log::info('[Buyback Manager] Expire-offers skipped — another run is in progress.');
            return;
        }

        try {
            $count = $offerService->expireStale();
            if ($count > 0) {
                Log::info("[Buyback Manager] ExpirePendingOffers job flipped {$count} offers to expired");
            }
        } catch (\Throwable $e) {
            Log::error('[Buyback Manager] ExpirePendingOffers job failed: ' . $e->getMessage());
            throw $e;
        } finally {
            $lock->release();
        }
    }
}
