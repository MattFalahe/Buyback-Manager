<?php

namespace BuybackManager\Services;

use BuybackManager\Models\Casts\EncryptedSecret;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Moves an install off a development build from before 1.0.0. Those builds
 * kept their data in buyback_* tables, and 1.0.0 has buyback_manager_* tables
 * and migrations of its own, so the two sit side by side until this runs. It
 * can carry the old programme over, then removes the old tables, their
 * migration records and any schedule row for a command that is gone.
 *
 * seat-buyback, a different plugin, also names its tables buyback_*. Its
 * buyback_contracts shares a name with the development builds' contracts
 * table, so that one is recognised by seat-buyback's contractId column and
 * left alone. Its other tables are on none of these lists.
 */
final class DevBuildUpgrade
{
    public const PACKAGE = 'mattfalahe/buyback-manager';

    /**
     * Old table => the 1.0.0 table it carries over into. Sync status and
     * packaged volumes are left behind: 1.0.0 builds both again on its own.
     */
    public const CARRIED = [
        'buyback_settings' => 'buyback_manager_settings',
        'buyback_pricing_rules' => 'buyback_manager_pricing_rules',
        'buyback_location_rules' => 'buyback_manager_location_rules',
        'buyback_appraisals' => 'buyback_manager_appraisals',
        'buyback_appraisal_items' => 'buyback_manager_appraisal_items',
        'buyback_contracts' => 'buyback_manager_contracts',
        'buyback_contract_items' => 'buyback_manager_contract_items',
        'buyback_price_cache' => 'buyback_manager_price_cache',
        'buyback_price_status' => 'buyback_manager_price_status',
        'buyback_subscribed_types' => 'buyback_manager_subscribed_types',
        'buyback_webhooks' => 'buyback_manager_webhooks',
        'buyback_notification_log' => 'buyback_manager_notification_log',
        'buyback_programme_access' => 'buyback_manager_programme_access',
    ];

    /** Every table a development build ever made. */
    public const OLD_TABLES = [
        'buyback_settings',
        'buyback_pricing_rules',
        'buyback_location_rules',
        'buyback_appraisals',
        'buyback_appraisal_items',
        'buyback_contracts',
        'buyback_contract_items',
        'buyback_price_cache',
        'buyback_price_status',
        'buyback_sync_status',
        'buyback_subscribed_types',
        'buyback_webhooks',
        'buyback_notification_log',
        'buyback_packaged_volumes',
        'buyback_programme_access',
        'buyback_offers',
        'buyback_offer_items',
        'buyback_prices',
    ];

    /** Every migration file name a development build ever used. */
    public const OLD_MIGRATIONS = [
        '2026_01_01_000001_create_buyback_settings_table',
        '2026_01_01_000002_create_buyback_pricing_rules_table',
        '2026_01_01_000003_create_buyback_contracts_table',
        '2026_01_01_000004_create_buyback_contract_items_table',
        '2026_01_01_000005_create_buyback_prices_table',
        '2026_01_01_000005_remove_local_pricing_system',
        '2026_01_01_000006_add_manager_core_integration',
        '2026_01_01_000006_add_provider_columns_to_buyback_settings',
        '2026_01_01_000007_create_buyback_price_cache_table',
        '2026_01_01_000007_remove_local_pricing_system',
        '2026_01_01_000008_create_buyback_subscribed_types_table',
        '2026_01_01_000009_create_buyback_offers_table',
        '2026_01_01_000010_create_buyback_offer_items_table',
        '2026_01_01_000011_create_buyback_webhooks_table',
        '2026_01_01_000012_create_buyback_notification_log_table',
        '2026_01_01_000013_add_mode_columns_to_buyback_settings',
        '2026_01_01_000014_add_price_side_to_buyback_pricing_rules',
        '2026_01_01_000015_cleanup_renamed_migration_entries',
        '2026_01_01_000016_add_cache_ttl_to_buyback_settings',
        '2026_01_01_000017_add_contract_target_fields',
        '2026_01_01_000018_add_offer_link_to_buyback_contracts',
        '2026_01_01_000019_add_public_page_fields',
        '2026_01_01_000020_add_public_logo_bg',
        '2026_01_01_000021_add_public_rate_detail_flags',
        '2026_01_01_000022_create_buyback_location_rules_table',
        '2026_01_01_000023_add_public_layout',
        '2026_01_01_000024_add_public_logo_style',
        '2026_01_01_000025_add_nudged_at_to_buyback_contracts',
        '2026_01_08_000001_add_manager_core_integration',
        '2026_01_09_000001_remove_local_pricing_system',
        '2026_02_01_000000_reset_legacy_buyback_schema',
        '2026_02_01_000001_create_buyback_settings_table',
        '2026_02_01_000002_create_buyback_pricing_rules_table',
        '2026_02_01_000003_create_buyback_location_rules_table',
        '2026_02_01_000004_create_buyback_appraisals_table',
        '2026_02_01_000005_create_buyback_appraisal_items_table',
        '2026_02_01_000006_create_buyback_contracts_table',
        '2026_02_01_000007_create_buyback_contract_items_table',
        '2026_02_01_000008_create_buyback_price_cache_table',
        '2026_02_01_000009_create_buyback_subscribed_types_table',
        '2026_02_01_000010_create_buyback_webhooks_table',
        '2026_02_01_000011_create_buyback_notification_log_table',
        '2026_02_01_000012_create_buyback_price_status_table',
        '2026_02_01_000012_update_buyback_pricing_chain',
        '2026_02_01_000013_create_buyback_sync_status_table',
        '2026_02_01_000014_create_buyback_packaged_volumes_table',
        '2026_02_01_000015_create_buyback_programme_access_table',
        '2026_02_01_000016_add_buyback_public_closed_columns',
    ];

    /** Secrets 1.0.0 keeps encrypted. Older builds stored them as typed. */
    private const SECRETS = [
        'buyback_manager_settings' => 'janice_api_key',
        'buyback_manager_webhooks' => 'url',
    ];

    /**
     * Why this install should move to a release first, or null when it is on
     * one. Only a release or main should run the upgrade: any other branch can
     * pull unfinished work at the next restart. SEAT_PLUGINS says what the next
     * restart installs, Composer what is running now.
     */
    public static function releaseProblem(?string $seatPlugins, ?string $installed): ?string
    {
        $entry = null;
        foreach (explode(',', (string) $seatPlugins) as $plugin) {
            [$name, $constraint] = array_pad(explode(':', trim($plugin), 2), 2, '');
            if (strcasecmp(trim($name), self::PACKAGE) === 0) {
                $entry = trim($constraint);
            }
        }

        if ($entry !== null && self::isDevBranch($entry)) {
            return 'SEAT_PLUGINS installs ' . self::PACKAGE . ':' . $entry . ', a development branch. Change it to '
                . self::PACKAGE . ' (or ' . self::PACKAGE . ':dev-main), restart SeAT, then run this again.';
        }

        if ($installed !== null && self::isDevBranch($installed)) {
            return $entry !== null
                ? 'SeAT is still running ' . $installed . '. Restart SeAT so it installs what SEAT_PLUGINS asks for, then run this again.'
                : 'This install runs ' . self::PACKAGE . ' ' . $installed . ', a development branch. Install a release with composer require '
                    . self::PACKAGE . ', then run this again.';
        }

        return null;
    }

    /** Any branch but main. A release, a version constraint and main are fine. */
    private static function isDevBranch(string $version): bool
    {
        $version = strtolower(trim($version));

        return (str_starts_with($version, 'dev-') && $version !== 'dev-main') || str_ends_with($version, '-dev');
    }

    /**
     * What a development build left behind. Schedule rows are counted only
     * when the commands that exist now are given.
     *
     * @param string[]|null $commands
     */
    public function inspect(?array $commands = null): array
    {
        $tables = [];
        foreach (self::OLD_TABLES as $table) {
            if ($this->ours($table)) {
                $tables[$table] = DB::table($table)->count();
            }
        }

        return [
            'tables' => $tables,
            'seat_buyback' => $this->seatBuybackContracts(),
            'migrations' => Schema::hasTable('migrations')
                ? DB::table('migrations')->whereIn('migration', self::OLD_MIGRATIONS)->count()
                : 0,
            'schedules' => $commands === null ? 0 : count($this->staleSchedules($commands)),
            'key_based' => isset($tables['buyback_appraisals']),
            'programmes' => $tables['buyback_settings'] ?? 0,
            'new_programmes' => DB::table('buyback_manager_settings')->count(),
        ];
    }

    public static function nothingLeft(array $found): bool
    {
        return $found['tables'] === [] && $found['migrations'] === 0 && $found['schedules'] === 0;
    }

    /**
     * Copies the old programme into the empty 1.0.0 tables, every column both
     * sides have, ids included so appraisals, items and contracts still point
     * at each other. All or nothing. A row the new tables refuse as a
     * duplicate (old builds allowed two pricing rules for one item) keeps the
     * first.
     *
     * @return array<string, array{0: int, 1: int}> old table => [rows, copied]
     */
    public function carryOver(): array
    {
        if (! Schema::hasTable('buyback_appraisals')) {
            throw new RuntimeException('This build is older than appraisal keys, so its programme cannot be carried over. Start fresh instead.');
        }
        if (DB::table('buyback_manager_settings')->exists()) {
            throw new RuntimeException('1.0.0 already has a programme. Carrying one over only fills an empty install.');
        }

        $copied = [];
        DB::transaction(function () use (&$copied) {
            foreach (self::CARRIED as $old => $new) {
                if (! $this->ours($old)) {
                    continue;
                }

                $columns = array_values(array_intersect(Schema::getColumnListing($old), Schema::getColumnListing($new)));
                $rows = 0;
                $in = 0;
                DB::table($old)->select($columns)->orderBy(in_array('id', $columns, true) ? 'id' : $columns[0])
                    ->chunk(500, function ($chunk) use ($new, &$rows, &$in) {
                        foreach ($chunk as $row) {
                            $rows++;
                            // A NULL is left out so the column takes 1.0.0's default. Sent
                            // as NULL, a NOT NULL column refuses the row, and MySQL's
                            // INSERT IGNORE would store 0 there instead: a 0% rate.
                            $in += DB::table($new)->insertOrIgnore(array_filter((array) $row, fn ($value) => $value !== null));
                        }
                    });
                $copied[$old] = [$rows, $in];
            }

            $this->encryptTypedSecrets();

            // In the old builds a yield on the rule was what made it refine.
            if (Schema::hasColumn('buyback_manager_pricing_rules', 'refined')) {
                DB::table('buyback_manager_pricing_rules')->whereNotNull('refine_yield')->update(['refined' => true]);
            }
        });

        return $copied;
    }

    /**
     * Drops the old tables, deletes their migration records and the schedule
     * rows of Buyback commands that no longer exist (old builds scheduled
     * buyback-manager:expire-offers, which would fail on every run).
     *
     * @param string[] $commands every command name this install has
     * @return array{tables: string[], migrations: int, schedules: int}
     */
    public function removeOld(array $commands): array
    {
        $dropped = [];
        Schema::disableForeignKeyConstraints();
        try {
            foreach (self::OLD_TABLES as $table) {
                if ($this->ours($table)) {
                    Schema::drop($table);
                    $dropped[] = $table;
                }
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        $migrations = Schema::hasTable('migrations')
            ? DB::table('migrations')->whereIn('migration', self::OLD_MIGRATIONS)->delete()
            : 0;
        $stale = $this->staleSchedules($commands);
        $schedules = $stale === [] ? 0 : DB::table('schedules')->whereIn('id', $stale)->delete();

        return ['tables' => $dropped, 'migrations' => $migrations, 'schedules' => $schedules];
    }

    /** A development build's table, never seat-buyback's buyback_contracts. */
    private function ours(string $table): bool
    {
        return Schema::hasTable($table) && ! ($table === 'buyback_contracts' && $this->seatBuybackContracts());
    }

    private function seatBuybackContracts(): bool
    {
        return Schema::hasTable('buyback_contracts') && Schema::hasColumn('buyback_contracts', 'contractId');
    }

    /** @return int[] ids of schedule rows for a buyback-manager command not in $commands */
    private function staleSchedules(array $commands): array
    {
        if (! Schema::hasTable('schedules')) {
            return [];
        }

        return DB::table('schedules')->where('command', 'like', 'buyback-manager:%')->get(['id', 'command'])
            ->filter(fn ($row) => ! in_array(strtok((string) $row->command, ' '), $commands, true))
            ->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }

    /**
     * Encrypts a secret carried over as it was typed. One that is encrypted
     * already, or under another app key, is left as it is.
     */
    private function encryptTypedSecrets(): void
    {
        foreach (self::SECRETS as $table => $column) {
            foreach (DB::table($table)->whereNotNull($column)->where($column, '!=', '')->get(['id', $column]) as $row) {
                $value = (string) $row->{$column};
                try {
                    Crypt::decryptString($value);
                    continue;
                } catch (DecryptException $e) {
                    if (EncryptedSecret::looksEncrypted($value)) {
                        continue;
                    }
                }
                DB::table($table)->where('id', $row->id)->update([$column => Crypt::encryptString($value)]);
            }
        }
    }
}
