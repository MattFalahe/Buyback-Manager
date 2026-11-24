<?php

namespace BuybackManager\Console\Commands;

use BuybackManager\Jobs\SyncContracts;
use Illuminate\Console\Command;

class SyncContractsCommand extends Command
{
    protected $signature = 'buyback-manager:sync-contracts {--sync : Run synchronously instead of queueing}';

    protected $description = 'Sync corporation buyback contracts from SeAT contract data';

    public function handle(): int
    {
        if ($this->option('sync')) {
            dispatch_sync(new SyncContracts());
            $this->info('Buyback contract sync completed.');
            return self::SUCCESS;
        }

        SyncContracts::dispatch();
        $this->info('Buyback contract sync dispatched to queue.');
        return self::SUCCESS;
    }
}
