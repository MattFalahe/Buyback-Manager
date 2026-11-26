<?php

namespace BuybackManager\Tests\Unit;

use BuybackManager\Services\DevBuildUpgrade;
use BuybackManager\Tests\Sqlite;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class DevBuildUpgradeTest extends TestCase
{
    private const PACKAGE = 'mattfalahe/buyback-manager';
    private const URL = 'https://discord.test/api/webhooks/1/secret-token';
    private const JANICE_KEY = 'G9KwKq3465588VPd6747t95Zh94q3W2E';
    private const COMMANDS = ['buyback-manager:sync-contracts', 'mining-manager:update-ledger'];

    protected function setUp(): void
    {
        Sqlite::migrate();
        DB::statement('CREATE TABLE migrations (id INTEGER PRIMARY KEY AUTOINCREMENT, migration VARCHAR(255), batch INTEGER)');
        DB::statement('CREATE TABLE schedules (id INTEGER PRIMARY KEY AUTOINCREMENT, command VARCHAR(255), expression VARCHAR(255))');
        foreach (glob(dirname(__DIR__, 2) . '/src/Database/migrations/*.php') as $path) {
            DB::table('migrations')->insert(['migration' => basename($path, '.php'), 'batch' => 2]);
        }
        DB::table('schedules')->insert([
            ['command' => 'buyback-manager:sync-contracts', 'expression' => '*/15 * * * *'],
            ['command' => 'mining-manager:update-ledger', 'expression' => '0 * * * *'],
        ]);
    }

    protected function tearDown(): void
    {
        Sqlite::close();
    }

    /**
     * The tables a development build left, with the columns 1.0.0 has under
     * the old names, no unique indexes, a column 1.0.0 dropped, and the old
     * migration and schedule rows.
     */
    private function devBuild(bool $keyBased = true): void
    {
        foreach (DevBuildUpgrade::CARRIED as $old => $new) {
            if ($old === 'buyback_appraisals' && ! $keyBased) {
                continue;
            }
            DB::statement("CREATE TABLE {$old} AS SELECT * FROM {$new} WHERE 0");
        }
        DB::statement('CREATE TABLE buyback_sync_status AS SELECT * FROM buyback_manager_sync_status WHERE 0');
        DB::statement('CREATE TABLE buyback_packaged_volumes AS SELECT * FROM buyback_manager_packaged_volumes WHERE 0');
        Schema::table('buyback_settings', fn ($table) => $table->string('manager_core_variant')->nullable());

        foreach (array_slice(DevBuildUpgrade::OLD_MIGRATIONS, 30) as $migration) {
            DB::table('migrations')->insert(['migration' => $migration, 'batch' => 1]);
        }
        DB::table('schedules')->insert(['command' => 'buyback-manager:expire-offers', 'expression' => '0 * * * *']);
    }

    private function programme(): void
    {
        DB::table('buyback_settings')->insert([
            'id' => 7,
            'corporation_id' => 98000001,
            'enabled' => 1,
            'base_percentage' => 85,
            'price_provider' => 'janice',
            'janice_api_key' => self::JANICE_KEY,
            'target_type' => 'my_corp',
            'manager_core_variant' => 'buy',
            'public_headline' => 'MINC buys ore',
        ]);
        DB::table('buyback_pricing_rules')->insert([
            ['id' => 3, 'setting_id' => 7, 'type' => 'group', 'type_id' => 1884, 'percentage' => 92, 'refine_yield' => 87.5, 'excluded' => 0, 'featured' => 1, 'priority' => 0],
            ['id' => 4, 'setting_id' => 7, 'type' => 'group', 'type_id' => 1884, 'percentage' => 50, 'refine_yield' => null, 'excluded' => 0, 'featured' => 0, 'priority' => 0],
            ['id' => 5, 'setting_id' => 7, 'type' => 'item', 'type_id' => 34, 'percentage' => null, 'refine_yield' => null, 'excluded' => 1, 'featured' => 0, 'priority' => 0],
        ]);
        DB::table('buyback_appraisals')->insert(['id' => 40, 'corporation_id' => 98000001, 'public_id' => 'k3y0000000000001', 'total_buyback_value' => 1500000]);
        DB::table('buyback_appraisal_items')->insert(['id' => 90, 'appraisal_id' => 40, 'type_id' => 34, 'quantity' => 1000]);
        DB::table('buyback_webhooks')->insert(['id' => 2, 'corporation_id' => 98000001, 'name' => 'Directors', 'url' => self::URL, 'enabled' => 1]);
        DB::table('buyback_sync_status')->insert(['id' => 1, 'setting_id' => 7, 'failures' => 3]);
    }

    public function testAReleaseOrMainMayRunIt(): void
    {
        $this->assertNull(DevBuildUpgrade::releaseProblem(self::PACKAGE, '1.0.0'));
        $this->assertNull(DevBuildUpgrade::releaseProblem(self::PACKAGE . ':dev-main', 'dev-main'));
        $this->assertNull(DevBuildUpgrade::releaseProblem(self::PACKAGE . ':^1.0', '1.0.0'));
        $this->assertNull(DevBuildUpgrade::releaseProblem('mattfalahe/manager-core:dev-dev, ' . self::PACKAGE . ' ,mattfalahe/hr-manager:dev-dev', '1.0.0'));
        $this->assertNull(DevBuildUpgrade::releaseProblem(null, '1.0.0'));
        $this->assertNull(DevBuildUpgrade::releaseProblem(null, null));
    }

    public function testADevelopmentBranchInSeatPluginsIsSentToMain(): void
    {
        foreach (['dev-dev-1.0', 'dev-Dev-2.0', '1.0.x-dev'] as $branch) {
            $problem = DevBuildUpgrade::releaseProblem('mattfalahe/manager-core,' . self::PACKAGE . ':' . $branch, 'dev-main');
            $this->assertNotNull($problem, $branch);
            $this->assertStringContainsString(self::PACKAGE . ':' . $branch . ', a development branch', $problem);
            $this->assertStringContainsString('restart SeAT', $problem);
        }
    }

    public function testABranchStillRunningAfterSeatPluginsChangedAsksForARestart(): void
    {
        $this->assertStringContainsString('still running dev-dev-1.0', DevBuildUpgrade::releaseProblem(self::PACKAGE, 'dev-dev-1.0'));
        $this->assertStringContainsString('composer require ' . self::PACKAGE, DevBuildUpgrade::releaseProblem(null, 'dev-dev-1.0'));
    }

    public function testAFreshInstallHasNothingLeft(): void
    {
        $found = (new DevBuildUpgrade())->inspect(self::COMMANDS);

        $this->assertTrue(DevBuildUpgrade::nothingLeft($found));
        $this->assertSame(0, $found['programmes']);
    }

    public function testItFindsWhatADevelopmentBuildLeft(): void
    {
        $this->devBuild();
        $this->programme();

        $found = (new DevBuildUpgrade())->inspect(self::COMMANDS);

        $this->assertFalse(DevBuildUpgrade::nothingLeft($found));
        $this->assertCount(15, $found['tables']);
        $this->assertSame(3, $found['tables']['buyback_pricing_rules']);
        $this->assertSame(18, $found['migrations']);
        $this->assertSame(1, $found['schedules']);
        $this->assertTrue($found['key_based']);
        $this->assertSame(1, $found['programmes']);
        $this->assertSame(0, $found['new_programmes']);
        $this->assertFalse($found['seat_buyback']);
    }

    public function testTheProgrammeIsCarriedOverWithItsIds(): void
    {
        $this->devBuild();
        $this->programme();

        $copied = (new DevBuildUpgrade())->carryOver();

        $this->assertSame([3, 2], $copied['buyback_pricing_rules']);
        $this->assertSame([1, 1], $copied['buyback_settings']);
        $this->assertArrayNotHasKey('buyback_sync_status', $copied);

        $setting = DB::table('buyback_manager_settings')->first();
        $this->assertSame(7, (int) $setting->id);
        $this->assertSame('MINC buys ore', $setting->public_headline);
        $this->assertSame(92.0, (float) DB::table('buyback_manager_pricing_rules')->where('type_id', 1884)->value('percentage'));
        $this->assertTrue((bool) DB::table('buyback_manager_pricing_rules')->where('id', 3)->value('refined'));
        $this->assertFalse((bool) DB::table('buyback_manager_pricing_rules')->where('id', 5)->value('refined'));
        $this->assertSame(40, (int) DB::table('buyback_manager_appraisal_items')->where('id', 90)->value('appraisal_id'));
        $this->assertSame('k3y0000000000001', DB::table('buyback_manager_appraisals')->where('id', 40)->value('public_id'));
        $this->assertSame(0, DB::table('buyback_manager_sync_status')->count());
    }

    public function testAProgrammeFromBeforeMemberRatesStartsWithNone(): void
    {
        $this->devBuild();
        // No development build had who can sell, members or rate tiers.
        foreach ([
            'buyback_settings' => ['seller_scope', 'seller_alliance_id', 'member_scope', 'member_bonus', 'open_in_game'],
            'buyback_appraisals' => ['rate_tier'],
            'buyback_contracts' => ['seller_note'],
        ] as $table => $columns) {
            foreach ($columns as $column) {
                DB::statement("ALTER TABLE {$table} DROP COLUMN {$column}");
            }
        }
        $this->programme();
        DB::table('buyback_contracts')->insert(['id' => 1, 'contract_id' => 200001, 'corporation_id' => 98000001, 'issuer_id' => 90000001, 'status' => 'outstanding']);

        (new DevBuildUpgrade())->carryOver();

        $setting = DB::table('buyback_manager_settings')->first();
        $this->assertSame('any', $setting->seller_scope);
        $this->assertSame('corporation', $setting->member_scope);
        $this->assertSame(0.0, (float) $setting->member_bonus);
        $this->assertFalse((bool) $setting->open_in_game);
        $this->assertSame('public', DB::table('buyback_manager_appraisals')->where('id', 40)->value('rate_tier'));
        $this->assertNull(DB::table('buyback_manager_contracts')->where('id', 1)->value('seller_note'));
    }

    public function testSecretsTypedIntoAnOldBuildAreEncrypted(): void
    {
        $this->devBuild();
        $this->programme();
        $encrypted = Crypt::encryptString(self::URL . '/two');
        DB::table('buyback_webhooks')->insert(['id' => 3, 'corporation_id' => 98000001, 'name' => 'Haulers', 'url' => $encrypted, 'enabled' => 1]);

        (new DevBuildUpgrade())->carryOver();

        $key = DB::table('buyback_manager_settings')->value('janice_api_key');
        $this->assertNotSame(self::JANICE_KEY, $key);
        $this->assertSame(self::JANICE_KEY, Crypt::decryptString($key));
        $this->assertSame(self::URL, Crypt::decryptString(DB::table('buyback_manager_webhooks')->where('id', 2)->value('url')));
        $this->assertSame($encrypted, DB::table('buyback_manager_webhooks')->where('id', 3)->value('url'));
    }

    public function testABuildOlderThanAppraisalKeysCannotBeCarriedOver(): void
    {
        $this->devBuild(keyBased: false);

        $this->assertFalse((new DevBuildUpgrade())->inspect()['key_based']);
        $this->expectException(RuntimeException::class);
        (new DevBuildUpgrade())->carryOver();
    }

    public function testCarryingOverNeverMergesIntoAProgrammeAlreadyThere(): void
    {
        $this->devBuild();
        $this->programme();
        DB::table('buyback_manager_settings')->insert(['corporation_id' => 98000002]);

        try {
            (new DevBuildUpgrade())->carryOver();
            $this->fail('A programme was carried over into an install that already had one.');
        } catch (RuntimeException $e) {
            $this->assertSame(0, DB::table('buyback_manager_pricing_rules')->count());
        }
    }

    public function testAFailedCarryOverCopiesNothing(): void
    {
        $this->devBuild();
        $this->programme();
        DB::table('buyback_contracts')->insert(['id' => 1, 'contract_id' => 200001, 'corporation_id' => 98000001, 'issuer_id' => 90000001, 'status' => 'outstanding']);
        DB::statement("CREATE TRIGGER refuse BEFORE INSERT ON buyback_manager_contracts BEGIN SELECT RAISE(ABORT, 'refused'); END");

        try {
            (new DevBuildUpgrade())->carryOver();
            $this->fail('The carry-over went on past a refused insert.');
        } catch (\Throwable $e) {
            $this->assertSame(0, DB::table('buyback_manager_settings')->count());
            $this->assertSame(0, DB::table('buyback_manager_appraisals')->count());
            $this->assertTrue(Schema::hasTable('buyback_settings'));
        }
    }

    public function testRemovingTakesTheOldTablesRecordsAndDeadScheduleOnly(): void
    {
        $this->devBuild();
        $this->programme();

        $removed = (new DevBuildUpgrade())->removeOld(self::COMMANDS);

        $this->assertCount(15, $removed['tables']);
        $this->assertSame(18, $removed['migrations']);
        $this->assertSame(1, $removed['schedules']);
        foreach (DevBuildUpgrade::OLD_TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table), $table);
        }
        foreach (array_values(DevBuildUpgrade::CARRIED) as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }
        $this->assertSame(count(glob(dirname(__DIR__, 2) . '/src/Database/migrations/*.php')), DB::table('migrations')->count());
        $this->assertSame(self::COMMANDS, DB::table('schedules')->orderBy('id')->pluck('command')->all());
        $this->assertTrue(DevBuildUpgrade::nothingLeft((new DevBuildUpgrade())->inspect(self::COMMANDS)));
    }

    public function testSeatBuybackKeepsItsContractsTable(): void
    {
        DB::statement('CREATE TABLE buyback_contracts (id INTEGER PRIMARY KEY, contractId INTEGER, contractIssuer VARCHAR(64))');
        DB::table('buyback_contracts')->insert(['id' => 1, 'contractId' => 300001, 'contractIssuer' => 'A seller']);
        DB::statement('CREATE TABLE buyback_market_config (id INTEGER PRIMARY KEY, typeId INTEGER)');

        $upgrade = new DevBuildUpgrade();
        $found = $upgrade->inspect(self::COMMANDS);
        $this->assertTrue($found['seat_buyback']);
        $this->assertTrue(DevBuildUpgrade::nothingLeft($found));

        $this->assertSame([], $upgrade->removeOld(self::COMMANDS)['tables']);
        $this->assertSame(1, DB::table('buyback_contracts')->count());
        $this->assertTrue(Schema::hasTable('buyback_market_config'));
    }
}
