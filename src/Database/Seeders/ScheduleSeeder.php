<?php

namespace BuybackManager\Database\Seeders;

use Seat\Services\Seeding\AbstractScheduleSeeder;

/**
 * Buyback Manager's schedules.
 *
 * AbstractScheduleSeeder only adds rows that are missing, so an operator's
 * own changes to these are kept on every update. A schedule that has to
 * change goes in getDeprecatedSchedules() and comes back as a new entry.
 */
class ScheduleSeeder extends AbstractScheduleSeeder
{
    public function getSchedules(): array
    {
        return [
            [
                'command' => 'buyback-manager:sync-contracts',
                'expression' => '*/15 * * * *',
                'allow_overlap' => false,
                'allow_maintenance' => false,
                'ping_before' => null,
                'ping_after' => null,
            ],
        ];
    }

    /**
     * Commands retired since the first release, so their rows are removed
     * rather than run forever. None yet.
     */
    public function getDeprecatedSchedules(): array
    {
        return [];
    }
}
