<?php

namespace BuybackManager\Database\Seeders;

use Illuminate\Support\Facades\DB;
use Seat\Services\Seeding\AbstractScheduleSeeder;

class ScheduleSeeder extends AbstractScheduleSeeder
{
    public function run(): void
    {
        foreach ($this->getSchedules() as $job) {
            DB::table('schedules')->updateOrInsert(
                ['command' => $job['command']],
                $job
            );
        }

        $deprecated = $this->getDeprecatedSchedules();
        if (! empty($deprecated)) {
            DB::table('schedules')->whereIn('command', $deprecated)->delete();
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

    /**
     * Commands that no longer exist. Listed here so the seeder deletes
     * their rows from the schedules table — otherwise SeAT would keep
     * trying to run a command that has been removed from the plugin.
     *
     * `expire-offers` belonged to the retired offer workflow: quotes are no
     * longer locked with an expiry, so nothing needs sweeping.
     */
    public function getDeprecatedSchedules(): array
    {
        return [
            'buyback-manager:expire-offers',
        ];
    }
}
