<?php

namespace BuybackManager\Tests\Unit;

use BuybackManager\Tests\Sqlite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;

class RuleRefiningMigrationTest extends TestCase
{
    private const MIGRATIONS = __DIR__ . '/../../src/Database/migrations/';

    protected function tearDown(): void
    {
        Sqlite::close();
    }

    public function testARuleSavedWithAYieldBeforeTheFlagExistedKeepsRefining(): void
    {
        Sqlite::migrate('000001', '000002');
        DB::table('buyback_manager_pricing_rules')->insert([
            ['setting_id' => 1, 'type' => 'group', 'type_id' => 1884, 'percentage' => 90, 'refine_yield' => 87.5],
            ['setting_id' => 1, 'type' => 'group', 'type_id' => 462, 'percentage' => 90, 'refine_yield' => null],
        ]);

        require_once self::MIGRATIONS . '2026_11_01_000016_add_display_name_and_refined_to_buyback_manager_pricing_rules.php';
        (new \AddDisplayNameAndRefinedToBuybackManagerPricingRules())->up();
        (new \AddDisplayNameAndRefinedToBuybackManagerPricingRules())->up();

        $this->assertTrue(Schema::hasColumn('buyback_manager_pricing_rules', 'display_name'));
        $this->assertSame([1884 => 1, 462 => 0], DB::table('buyback_manager_pricing_rules')
            ->pluck('refined', 'type_id')->map(fn ($refined) => (int) $refined)->all());
    }

    public function testAProgrammeStartsAtNinetyPercent(): void
    {
        Sqlite::migrate('000001', '000017');
        DB::table('buyback_manager_settings')->insert(['corporation_id' => 98000001]);

        $this->assertSame(90.0, (float) DB::table('buyback_manager_settings')->value('refine_yield'));
    }
}
