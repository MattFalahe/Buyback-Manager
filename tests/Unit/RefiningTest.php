<?php

namespace BuybackManager\Tests\Unit;

use BuybackManager\Models\BuybackPricingRule;
use BuybackManager\Models\BuybackSetting;
use BuybackManager\Services\AppraisalService;
use BuybackManager\Services\Pricing\PriceWait;
use BuybackManager\Services\Pricing\Reprocessing;
use BuybackManager\Tests\KnownTypes;
use BuybackManager\Tests\RecordingManagerCore;
use BuybackManager\Tests\ScriptedPrices;
use BuybackManager\Tests\Sqlite;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;

/**
 * Paying on what ore and ice refine into, with CCP's recipes as SeAT's SDE
 * holds them.
 */
class RefiningTest extends TestCase
{
    private const TRITANIUM = 34;
    private const PYERITE = 35;
    private const MEXALLON = 36;
    private const HYDROCARBONS = 16633;
    private const VELDSPAR = 1230;
    private const COMPRESSED_VELDSPAR = 62516;
    private const BITUMENS = 45492;
    private const CLEAR_ICICLE = 16262;

    private const VELDSPAR_GROUP = 462;
    private const BITUMENS_GROUP = 1884;
    private const ICE_GROUP = 465;
    private const ASTEROID_CATEGORY = 25;
    private const DAMAGE_CONTROL = 2046;
    private const MODULE_GROUP = 60;
    private const MODULE_CATEGORY = 7;

    protected function setUp(): void
    {
        Sqlite::migrate();
        Carbon::setTestNow('2026-11-22 12:00:00');

        Schema::create('invTypes', function (Blueprint $table) {
            $table->unsignedInteger('typeID')->primary();
            $table->string('typeName');
            $table->unsignedInteger('groupID');
            $table->double('volume')->nullable();
            $table->integer('portionSize')->nullable();
            $table->boolean('published')->default(true);
        });
        Schema::create('invGroups', function (Blueprint $table) {
            $table->unsignedInteger('groupID')->primary();
            $table->unsignedInteger('categoryID');
        });
        Schema::create('invTypeMaterials', function (Blueprint $table) {
            $table->unsignedInteger('typeID');
            $table->unsignedInteger('materialTypeID');
            $table->unsignedInteger('quantity');
        });

        DB::table('invGroups')->insert([
            ['groupID' => 18, 'categoryID' => 4],
            ['groupID' => 427, 'categoryID' => 4],
            ['groupID' => self::VELDSPAR_GROUP, 'categoryID' => self::ASTEROID_CATEGORY],
            ['groupID' => self::BITUMENS_GROUP, 'categoryID' => self::ASTEROID_CATEGORY],
            ['groupID' => self::ICE_GROUP, 'categoryID' => self::ASTEROID_CATEGORY],
            ['groupID' => self::MODULE_GROUP, 'categoryID' => self::MODULE_CATEGORY],
        ]);
        DB::table('invTypes')->insert([
            ['typeID' => self::TRITANIUM, 'typeName' => 'Tritanium', 'groupID' => 18, 'volume' => 0.01, 'portionSize' => 1],
            ['typeID' => self::PYERITE, 'typeName' => 'Pyerite', 'groupID' => 18, 'volume' => 0.01, 'portionSize' => 1],
            ['typeID' => self::MEXALLON, 'typeName' => 'Mexallon', 'groupID' => 18, 'volume' => 0.01, 'portionSize' => 1],
            ['typeID' => self::HYDROCARBONS, 'typeName' => 'Hydrocarbons', 'groupID' => 427, 'volume' => 0.05, 'portionSize' => 1],
            ['typeID' => self::VELDSPAR, 'typeName' => 'Veldspar', 'groupID' => self::VELDSPAR_GROUP, 'volume' => 0.1, 'portionSize' => 100],
            ['typeID' => self::COMPRESSED_VELDSPAR, 'typeName' => 'Compressed Veldspar', 'groupID' => self::VELDSPAR_GROUP, 'volume' => 0.001, 'portionSize' => 100],
            ['typeID' => self::BITUMENS, 'typeName' => 'Bitumens', 'groupID' => self::BITUMENS_GROUP, 'volume' => 10, 'portionSize' => 100],
            ['typeID' => self::CLEAR_ICICLE, 'typeName' => 'Clear Icicle', 'groupID' => self::ICE_GROUP, 'volume' => 1000, 'portionSize' => 1],
            ['typeID' => self::DAMAGE_CONTROL, 'typeName' => 'Damage Control I', 'groupID' => self::MODULE_GROUP, 'volume' => 5, 'portionSize' => 1],
        ]);
        DB::table('invTypeMaterials')->insert([
            ['typeID' => self::VELDSPAR, 'materialTypeID' => self::TRITANIUM, 'quantity' => 400],
            ['typeID' => self::COMPRESSED_VELDSPAR, 'materialTypeID' => self::TRITANIUM, 'quantity' => 400],
            ['typeID' => self::BITUMENS, 'materialTypeID' => self::PYERITE, 'quantity' => 6000],
            ['typeID' => self::BITUMENS, 'materialTypeID' => self::MEXALLON, 'quantity' => 400],
            ['typeID' => self::BITUMENS, 'materialTypeID' => self::HYDROCARBONS, 'quantity' => 65],
            ['typeID' => self::DAMAGE_CONTROL, 'materialTypeID' => self::MEXALLON, 'quantity' => 20],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Sqlite::close();
    }

    private function setting(array $rules): BuybackSetting
    {
        $setting = BuybackSetting::create([
            'corporation_id' => 98000001,
            'enabled' => true,
            'base_percentage' => 90,
            'price_provider' => 'fuzzwork',
            'fallback_provider' => 'none',
            'price_source' => 'jita',
            'target_type' => BuybackSetting::TARGET_MY_CORP,
        ]);
        foreach ($rules as $rule) {
            // A yield on the rule used to be what made it refine.
            BuybackPricingRule::create($rule + ['setting_id' => $setting->id, 'excluded' => false, 'refined' => isset($rule['refine_yield'])]);
        }

        return $setting->fresh();
    }

    private static function item(int $typeId, string $name, int $groupId, int $quantity): array
    {
        return ['type_id' => $typeId, 'type_name' => $name, 'group_id' => $groupId, 'category_id' => self::ASTEROID_CATEGORY, 'quantity' => $quantity];
    }

    public function test_a_unit_is_worth_its_share_of_a_batch_at_the_yield(): void
    {
        $recipe = (new Reprocessing())->recipesFor([self::VELDSPAR])[self::VELDSPAR];

        $this->assertSame(['portion' => 100, 'materials' => [self::TRITANIUM => 400]], $recipe);
        // 400 Tritanium per 100 Veldspar, 87.5% of it refined, at 4 ISK each.
        $this->assertEqualsWithDelta(14.0, Reprocessing::unitValue($recipe, 87.5, [self::TRITANIUM => ['buy' => 4.0, 'sell' => 5.0]], 'buy'), 1e-9);
        $this->assertEqualsWithDelta(17.5, Reprocessing::unitValue($recipe, 87.5, [self::TRITANIUM => ['buy' => 4.0, 'sell' => 5.0]], 'sell'), 1e-9);
    }

    public function test_one_missing_mineral_leaves_the_refined_value_unpriced(): void
    {
        $recipe = (new Reprocessing())->recipesFor([self::BITUMENS])[self::BITUMENS];

        $this->assertNull(Reprocessing::unitValue($recipe, 90.0, [self::PYERITE => ['buy' => 10.0, 'sell' => null], self::MEXALLON => ['buy' => 50.0, 'sell' => null]], 'buy'));
    }

    public function test_a_refining_rule_pays_its_rate_on_the_minerals_and_marks_the_line(): void
    {
        $setting = $this->setting([
            ['type' => 'group', 'type_id' => self::BITUMENS_GROUP, 'percentage' => 90, 'price_side' => 'buy', 'refine_yield' => 80],
        ]);
        $items = [self::item(self::BITUMENS, 'Bitumens', self::BITUMENS_GROUP, 1000)];
        $recipes = (new Reprocessing())->recipesForPaste($items, $setting);
        $prices = [
            self::PYERITE => ['buy' => 10.0, 'sell' => 12.0],
            self::MEXALLON => ['buy' => 50.0, 'sell' => 60.0],
            self::HYDROCARBONS => ['buy' => 300.0, 'sell' => 350.0],
        ];

        $line = (new AppraisalService(new KnownTypes(), new ScriptedPrices()))->applyRules($items, $prices, $setting, $recipes)['items'][0];

        // One batch: 6000 x 10 + 400 x 50 + 65 x 300 = 99,500 ISK; at 80% and
        // per unit of a 100-unit batch, 796 ISK, of which the rule pays 90%.
        $this->assertEqualsWithDelta(796.0, $line['market_price'], 1e-9);
        $this->assertEqualsWithDelta(716.4, $line['buyback_price'], 1e-9);
        $this->assertSame(80.0, $line['refine_yield']);
    }

    public function test_a_quote_prices_the_minerals_and_never_the_ore(): void
    {
        $setting = $this->setting([
            ['type' => 'category', 'type_id' => self::ASTEROID_CATEGORY, 'percentage' => 95, 'refine_yield' => 87.5],
        ]);
        $items = [
            self::item(self::VELDSPAR, 'Veldspar', self::VELDSPAR_GROUP, 1000),
            self::item(self::CLEAR_ICICLE, 'Clear Icicle', self::ICE_GROUP, 1),
        ];
        $recipes = (new Reprocessing())->recipesForPaste($items, $setting);

        // Clear Icicle has no recipe in this SDE, so it is priced as itself.
        $this->assertSame([self::VELDSPAR], array_keys($recipes));
        $this->assertEqualsCanonicalizing([self::CLEAR_ICICLE, self::TRITANIUM], Reprocessing::typesToPrice([self::VELDSPAR, self::CLEAR_ICICLE], $recipes));
    }

    public function test_an_item_without_a_refining_rule_is_priced_as_itself(): void
    {
        $setting = $this->setting([
            ['type' => 'item', 'type_id' => self::COMPRESSED_VELDSPAR, 'percentage' => 95, 'refine_yield' => 90],
        ]);
        $items = [self::item(self::VELDSPAR, 'Veldspar', self::VELDSPAR_GROUP, 100)];

        $this->assertSame([], (new Reprocessing())->recipesForPaste($items, $setting));
    }

    public function test_the_rules_name_their_minerals_for_manager_core_whatever_they_cover(): void
    {
        $setting = $this->setting([
            ['type' => 'group', 'type_id' => self::VELDSPAR_GROUP, 'percentage' => 95, 'refine_yield' => 90],
            ['type' => 'category', 'type_id' => self::ASTEROID_CATEGORY, 'percentage' => 90, 'refine_yield' => 85],
            ['type' => 'item', 'type_id' => self::TRITANIUM, 'percentage' => 99],
        ]);

        $this->assertEqualsCanonicalizing(
            [self::TRITANIUM, self::PYERITE, self::MEXALLON, self::HYDROCARBONS],
            (new Reprocessing())->ruleMaterials($setting)
        );
    }

    public function test_an_excluded_rule_names_nothing(): void
    {
        $setting = $this->setting([
            ['type' => 'group', 'type_id' => self::VELDSPAR_GROUP, 'percentage' => 95, 'refine_yield' => 90, 'excluded' => true],
        ]);

        $this->assertSame([], (new Reprocessing())->ruleMaterials($setting));
    }

    public function test_a_refining_rule_without_a_yield_of_its_own_refines_ore_at_the_programmes(): void
    {
        $setting = $this->setting([
            ['type' => 'group', 'type_id' => self::BITUMENS_GROUP, 'percentage' => 90, 'price_side' => 'buy', 'refined' => true],
        ]);
        $setting->update(['refine_yield' => 80]);
        $setting = $setting->fresh();
        $items = [self::item(self::BITUMENS, 'Bitumens', self::BITUMENS_GROUP, 1000)];
        $prices = [
            self::PYERITE => ['buy' => 10.0, 'sell' => 12.0],
            self::MEXALLON => ['buy' => 50.0, 'sell' => 60.0],
            self::HYDROCARBONS => ['buy' => 300.0, 'sell' => 350.0],
        ];

        $line = (new AppraisalService(new KnownTypes(), new ScriptedPrices()))
            ->applyRules($items, $prices, $setting, (new Reprocessing())->recipesForPaste($items, $setting))['items'][0];

        $this->assertSame(80.0, $line['refine_yield']);
        $this->assertEqualsWithDelta(796.0, $line['market_price'], 1e-9);
    }

    public function test_a_new_programme_refines_at_ninety_percent(): void
    {
        $setting = $this->setting([
            ['type' => 'category', 'type_id' => self::ASTEROID_CATEGORY, 'percentage' => 95, 'refined' => true],
        ]);

        $this->assertSame(90.0, $setting->getRuleForItem(self::VELDSPAR, self::ASTEROID_CATEGORY, self::VELDSPAR_GROUP)['refine_yield']);
    }

    public function test_the_programmes_yield_never_reaches_beyond_ore(): void
    {
        $setting = $this->setting([
            ['type' => 'item', 'type_id' => self::DAMAGE_CONTROL, 'percentage' => 80, 'refined' => true],
        ]);

        $this->assertNull($setting->getRuleForItem(self::DAMAGE_CONTROL, self::MODULE_CATEGORY, self::MODULE_GROUP)['refine_yield']);
        $this->assertSame([], (new Reprocessing())->ruleMaterials($setting));
    }

    public function test_a_yield_of_its_own_refines_anything_the_rule_covers(): void
    {
        $setting = $this->setting([
            ['type' => 'item', 'type_id' => self::DAMAGE_CONTROL, 'percentage' => 80, 'refined' => true, 'refine_yield' => 55],
        ]);

        $this->assertSame(55.0, $setting->getRuleForItem(self::DAMAGE_CONTROL, self::MODULE_CATEGORY, self::MODULE_GROUP)['refine_yield']);
        $this->assertSame([self::MEXALLON], (new Reprocessing())->ruleMaterials($setting));
    }

    public function test_a_rule_that_does_not_refine_prices_the_item_whatever_yield_it_holds(): void
    {
        $setting = $this->setting([
            ['type' => 'group', 'type_id' => self::VELDSPAR_GROUP, 'percentage' => 95, 'refined' => false, 'refine_yield' => 90],
        ]);

        $this->assertNull($setting->getRuleForItem(self::VELDSPAR, self::ASTEROID_CATEGORY, self::VELDSPAR_GROUP)['refine_yield']);
        $this->assertSame([], (new Reprocessing())->ruleMaterials($setting));
    }

    public function test_only_ore_ice_and_moon_ore_count_as_the_asteroid_category(): void
    {
        $sde = new Reprocessing();

        $this->assertTrue($sde->inAsteroidCategory('category', self::ASTEROID_CATEGORY));
        $this->assertTrue($sde->inAsteroidCategory('group', self::ICE_GROUP));
        $this->assertTrue($sde->inAsteroidCategory('item', self::COMPRESSED_VELDSPAR));
        $this->assertFalse($sde->inAsteroidCategory('category', self::MODULE_CATEGORY));
        $this->assertFalse($sde->inAsteroidCategory('group', self::MODULE_GROUP));
        $this->assertFalse($sde->inAsteroidCategory('item', self::DAMAGE_CONTROL));
        $this->assertFalse($sde->inAsteroidCategory('group', 999999));
    }

    public function test_a_rules_name_for_sellers_comes_with_the_rule(): void
    {
        $setting = $this->setting([
            ['type' => 'group', 'type_id' => self::BITUMENS_GROUP, 'percentage' => 90, 'display_name' => 'Moon Goo'],
            ['type' => 'group', 'type_id' => self::VELDSPAR_GROUP, 'percentage' => 90],
        ]);

        $this->assertSame('Moon Goo', $setting->getRuleForItem(self::BITUMENS, self::ASTEROID_CATEGORY, self::BITUMENS_GROUP)['rule_name']);
        $this->assertNull($setting->getRuleForItem(self::VELDSPAR, self::ASTEROID_CATEGORY, self::VELDSPAR_GROUP)['rule_name']);
    }

    public function test_the_price_wait_waits_on_the_minerals_a_refined_paste_needs(): void
    {
        $setting = $this->setting([
            ['type' => 'group', 'type_id' => self::VELDSPAR_GROUP, 'percentage' => 95, 'refine_yield' => 90],
        ]);
        $setting->update(['price_provider' => 'manager-core', 'manager_core_market' => 'jita']);
        $mc = new RecordingManagerCore();
        $mc->answers['pricing.getPrices'] = fn () => [];
        $parser = new KnownTypes(['Veldspar' => [self::VELDSPAR, self::VELDSPAR_GROUP, self::ASTEROID_CATEGORY]]);

        $started = (new PriceWait($mc, $parser))->start($setting->fresh(), "Veldspar\t1000");

        $this->assertSame(1, $started['waiting']);
        $this->assertSame([['buyback-manager', [self::TRITANIUM], 'jita', 1, true]], $mc->callsTo('pricing.subscribeTypes'));
    }
}
