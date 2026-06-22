<?php

namespace BuybackManager\Console\Commands;

use BuybackManager\Jobs\ExpirePendingOffers;
use Illuminate\Console\Command;

class ExpirePendingOffersCommand extends Command
{
    protected $signature = 'buyback-manager:expire-offers {--sync : Run synchronously instead of queueing}';

    protected $description = 'Expire pending buyback offers past their lock window';

    public function handle(): int
    {
        if ($this->option('sync')) {
            dispatch_sync(new ExpirePendingOffers());
            $this->info('Buyback offer expiry sweep completed.');
            return self::SUCCESS;
        }

        ExpirePendingOffers::dispatch();
        $this->info('Buyback offer expiry sweep dispatched to queue.');
        return self::SUCCESS;
    }
}
