<?php

namespace BuybackManager\Console\Commands;

use BuybackManager\Services\DevBuildUpgrade;
use Composer\InstalledVersions;
use Illuminate\Console\Command;
use Throwable;

class UpgradeDevBuildCommand extends Command
{
    protected $signature = 'buyback-manager:upgrade-dev-build
        {--carry-over : Copy the old programme into 1.0.0, then remove the old tables}
        {--fresh : Remove the old tables without copying anything}
        {--force : Do not ask before removing the old tables}';

    protected $description = 'Move off a development build from before 1.0.0: carry its programme over or start fresh, then remove what it left';

    public function handle(DevBuildUpgrade $upgrade): int
    {
        $problem = DevBuildUpgrade::releaseProblem(self::seatPlugins(), self::installedVersion());
        if ($problem !== null) {
            $this->error($problem);

            return self::FAILURE;
        }

        $commands = array_keys($this->getApplication()->all());
        $found = $upgrade->inspect($commands);
        if (DevBuildUpgrade::nothingLeft($found)) {
            $this->info('Nothing is left from a development build. There is nothing to do.');

            return self::SUCCESS;
        }

        $this->report($found);

        $mode = $this->mode($found);
        if ($mode === null) {
            return self::FAILURE;
        }

        if (! $this->option('force')) {
            if (! $this->input->isInteractive()) {
                $this->error('Add --force to run this without questions.');

                return self::FAILURE;
            }
            if (! $this->confirm('Remove the old tables and records now? Take a database backup first if you have not.', false)) {
                $this->info('Nothing was changed.');

                return self::SUCCESS;
            }
        }

        if ($mode === 'carry-over') {
            try {
                $copied = $upgrade->carryOver();
            } catch (Throwable $e) {
                $this->error('Carrying the programme over failed, so nothing was copied and nothing was removed: ' . $e->getMessage());

                return self::FAILURE;
            }
            $this->table(['Old table', 'Rows', 'Carried over'], array_map(
                fn ($old, $counts) => [$old, $counts[0], $counts[1]],
                array_keys($copied),
                $copied
            ));
            foreach ($copied as $old => [$rows, $in]) {
                if ($in < $rows) {
                    $this->warn("{$old}: " . ($rows - $in) . ' row(s) were refused by the 1.0.0 table, usually as a duplicate of one already carried over, and were left out.');
                }
            }
        }

        $removed = $upgrade->removeOld($commands);
        $this->info('Removed ' . count($removed['tables']) . ' old table(s), ' . $removed['migrations'] . ' old migration record(s) and '
            . $removed['schedules'] . ' schedule row(s) for commands that are gone.');
        $this->info($mode === 'carry-over'
            ? 'Done. Open Buyback Manager\'s Settings and check the programme.'
            : 'Done. Set the programme up in Buyback Manager\'s Settings.');

        return self::SUCCESS;
    }

    private function report(array $found): void
    {
        $this->line('Left from a development build before 1.0.0:');
        if ($found['tables'] !== []) {
            $this->table(['Old table', 'Rows'], array_map(fn ($t, $n) => [$t, $n], array_keys($found['tables']), $found['tables']));
        }
        $this->line('  ' . $found['migrations'] . ' old migration record(s), ' . $found['schedules'] . ' schedule row(s) for commands that are gone');
        if ($found['seat_buyback']) {
            $this->line('  buyback_contracts belongs to seat-buyback, a different plugin, and is left alone.');
        }
    }

    /** carry-over, fresh, or null after saying why neither can run. */
    private function mode(array $found): ?string
    {
        if ($this->option('carry-over') && $this->option('fresh')) {
            $this->error('Pick one of --carry-over and --fresh.');

            return null;
        }

        $canCarry = $found['programmes'] > 0 && $found['key_based'] && $found['new_programmes'] === 0;
        $mode = $this->option('carry-over') ? 'carry-over' : ($this->option('fresh') ? 'fresh' : null);

        if ($mode === null) {
            if (! $canCarry) {
                $mode = 'fresh';
            } elseif (! $this->input->isInteractive()) {
                $this->error('Add --carry-over to keep the old programme, or --fresh to start without it.');

                return null;
            } else {
                $mode = $this->choice('What should happen to the old programme?', ['Carry it over into 1.0.0', 'Start fresh'], 0) === 'Start fresh'
                    ? 'fresh'
                    : 'carry-over';
            }
        }

        if ($mode === 'carry-over' && ! $canCarry) {
            $this->error(match (true) {
                $found['programmes'] === 0 => 'The old tables hold no programme to carry over. Run it with --fresh.',
                ! $found['key_based'] => 'This build is older than appraisal keys, so its programme cannot be carried over. Run it with --fresh and set the programme up again in Settings.',
                default => '1.0.0 already has ' . $found['new_programmes'] . ' programme(s), and carrying one over only fills an empty install. Run it with --fresh, or delete those programmes in Settings first.',
            });

            return null;
        }

        if ($mode === 'fresh' && $found['programmes'] > 0) {
            $this->warn('Starting fresh: the old programme is not copied, and appraisal keys sellers already hold stop working.');
        }

        return $mode;
    }

    /** What the next restart installs, on SeAT Docker. Null elsewhere. */
    private static function seatPlugins(): ?string
    {
        $value = getenv('SEAT_PLUGINS');
        if ($value === false || $value === '') {
            $value = $_SERVER['SEAT_PLUGINS'] ?? $_ENV['SEAT_PLUGINS'] ?? null;
        }

        return $value === null || $value === '' ? null : (string) $value;
    }

    private static function installedVersion(): ?string
    {
        try {
            return InstalledVersions::isInstalled(DevBuildUpgrade::PACKAGE)
                ? InstalledVersions::getPrettyVersion(DevBuildUpgrade::PACKAGE)
                : null;
        } catch (Throwable $e) {
            return null;
        }
    }
}
