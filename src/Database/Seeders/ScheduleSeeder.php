<?php

namespace BuybackManager\Database\Seeders;

use Illuminate\Support\Facades\DB;
use Seat\Services\Seeding\AbstractScheduleSeeder;

class ScheduleSeeder extends AbstractScheduleSeeder
{
    /**
     * Registers the plugin's schedules.
     *
     * updateOrInsert rather than the inherited firstOrCreate: keying on the
     * command alone means a later change to a cron expression is actually
     * applied to the existing row, instead of being silently ignored because
     * the row already exists.
     */
    public function run(): void
    {
        foreach ($this->getSchedules() as $job) {
            DB::table('schedules')->updateOrInsert(
                ['command' => $job['command']],
                $job
            );
        }
    }

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
}
