<?php

namespace BuybackManager\Tests\Unit;

use BuybackManager\Models\BuybackAppraisal;
use BuybackManager\Models\BuybackPricingRule;
use BuybackManager\Models\BuybackSetting;
use BuybackManager\Services\AppraisalRecordService;
use BuybackManager\Services\AppraisalService;
use BuybackManager\Services\BuybackPublicService;
use BuybackManager\Services\Pricing\PriceProviderService;
use BuybackManager\Tests\KnownTypes;
use BuybackManager\Tests\ScriptedPrices;
use BuybackManager\Tests\Sqlite;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;

/**
 * The member bonus: what it does to each rule, which quotes get it, and that
 * a quote keeps which rates it used.
 */
class MemberRatesTest extends TestCase
{
    protected function setUp(): void
    {
        Sqlite::migrate();
        Carbon::setTestNow('2026-11-26 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Sqlite::close();
    }

    private function setting(array $attributes = [], array $rules = []): BuybackSetting
    {
        $setting = new BuybackSetting($attributes + [
            'corporation_id' => 98000001,
            'base_percentage' => 90,
            'buy_listed_only' => false,
            'price_provider' => 'fuzzwork',
            'fallback_provider' => 'none',
            'price_source' => 'jita',
            'fallback_to_jita' => false,
            'price_cache_ttl_minutes' => 60,
        ]);
        $setting->id = 7;
        $setting->exists = true;
        $setting->setRelation('corporation', null);
        $setting->setRelation('pricingRules', new Collection(array_map(fn ($rule) => new BuybackPricingRule($rule), $rules)));

        return $setting;
    }

    private static function item(int $typeId, int $quantity = 1, int $groupId = 18, int $categoryId = 4): array
    {
        return ['type_id' => $typeId, 'type_name' => "Type $typeId", 'group_id' => $groupId, 'category_id' => $categoryId, 'quantity' => $quantity];
    }

    private function service(?ScriptedPrices $prices = null): AppraisalService
    {
        return new AppraisalService(new KnownTypes(), $prices ?? new ScriptedPrices());
    }

    public function test_the_bonus_goes_on_top_of_a_rule_and_stops_at_100(): void
    {
        $setting = $this->setting(['member_bonus' => 5]);

        $this->assertSame(95.0, $setting->memberPercentage(90.0));
        $this->assertSame(100.0, $setting->memberPercentage(97.0));
        $this->assertSame(0.0, $setting->memberPercentage(0.0), 'a rule paying nothing stays at nothing');
        $this->assertSame(100.0, $setting->memberPercentage(100.0));
        $this->assertSame(110.0, $setting->memberPercentage(110.0), 'a rule past 100% is left where it is');
    }

    public function test_the_bonus_reads_safely(): void
    {
        $this->assertSame(0.0, $this->setting()->memberBonus());
        $this->assertFalse($this->setting()->hasMemberRates());
        $this->assertSame(0.0, $this->setting(['member_bonus' => -5])->memberBonus());
        $this->assertSame(100.0, $this->setting(['member_bonus' => 150])->memberBonus());
        $this->assertTrue($this->setting(['member_bonus' => 2.5])->hasMemberRates());
    }

    public function test_member_rates_lift_every_rule_and_leave_exclusions_alone(): void
    {
        $rules = [
            ['type' => 'item', 'type_id' => 34, 'percentage' => 97, 'priority' => 30],
            ['type' => 'item', 'type_id' => 35, 'percentage' => 0, 'priority' => 30],
            ['type' => 'item', 'type_id' => 36, 'excluded' => true, 'priority' => 30],
            ['type' => 'item', 'type_id' => 37, 'percentage' => 105, 'priority' => 30],
        ];
        $setting = $this->setting(['member_bonus' => 5], $rules);
        $items = [self::item(34, 10), self::item(35), self::item(36), self::item(37), self::item(38, 10)];
        $prices = array_fill_keys([34, 35, 36, 37, 38], ['buy' => 5.0, 'sell' => 10.0]);

        $public = $this->service()->applyRules($items, $prices, $setting);
        $member = $this->service()->applyRules($items, $prices, $setting, [], BuybackAppraisal::RATE_MEMBER);

        $this->assertSame([97.0, 0.0, 105.0, 90.0], array_column($public['items'], 'percentage'));
        $this->assertSame([100.0, 0.0, 105.0, 95.0], array_column($member['items'], 'percentage'));
        $this->assertSame([[36, 'excluded']], array_map(fn ($e) => [$e['type_id'], $e['reason']], $member['excluded']));
        $this->assertEqualsWithDelta(10.0, $member['items'][0]['buyback_price'], 1e-9);
        $this->assertEqualsWithDelta(100.0 + 0.0 + 10.5 + 95.0, $member['total_buyback'], 1e-9);
    }

    public function test_a_member_quote_says_so_and_pays_the_bonus(): void
    {
        $prices = (new ScriptedPrices())->answers('fuzzwork', 'jita', [1001 => ['buy' => 5.0, 'sell' => 6.0]]);
        $setting = $this->setting(['member_bonus' => 5]);

        $member = $this->service($prices)->appraise('Tritanium 1000', $setting, BuybackAppraisal::RATE_MEMBER);
        $public = $this->service($prices)->appraise('Tritanium 1000', $setting);

        $this->assertSame(BuybackAppraisal::RATE_MEMBER, $member['rate_tier']);
        $this->assertSame(5.0, $member['member_bonus']);
        $this->assertEqualsWithDelta(5700.0, $member['total_buyback_value'], 1e-6);
        $this->assertSame(BuybackAppraisal::RATE_PUBLIC, $public['rate_tier']);
        $this->assertSame(0.0, $public['member_bonus']);
        $this->assertEqualsWithDelta(5400.0, $public['total_buyback_value'], 1e-6);
    }

    public function test_with_no_bonus_a_member_is_quoted_and_recorded_at_public_rates(): void
    {
        $prices = (new ScriptedPrices())->answers('fuzzwork', 'jita', [1001 => ['buy' => 5.0, 'sell' => 6.0]]);

        $result = $this->service($prices)->appraise('Tritanium 1000', $this->setting(), BuybackAppraisal::RATE_MEMBER);

        $this->assertSame(BuybackAppraisal::RATE_PUBLIC, $result['rate_tier']);
        $this->assertSame(0.0, $result['member_bonus']);
        $this->assertEqualsWithDelta(5400.0, $result['total_buyback_value'], 1e-6);
    }

    public function test_a_quote_keeps_which_rates_it_used(): void
    {
        $prices = (new ScriptedPrices())->answers('fuzzwork', 'jita', [1001 => ['buy' => 5.0, 'sell' => 6.0]]);
        $setting = $this->setting(['member_bonus' => 5]);
        $records = new AppraisalRecordService();

        $member = $records->store($this->service($prices)->appraise('Tritanium 1000', $setting, BuybackAppraisal::RATE_MEMBER), 98000001, 5);
        $public = $records->store($this->service($prices)->appraise('Tritanium 1000', $setting), 98000001);
        $odd = $records->store(['success' => true, 'rate_tier' => 'gold', 'items' => []], 98000001);

        $this->assertSame(BuybackAppraisal::RATE_MEMBER, $member->fresh()->rate_tier);
        $this->assertTrue($member->fresh()->isMemberRate());
        $this->assertSame(BuybackAppraisal::RATE_PUBLIC, $public->fresh()->rate_tier);
        $this->assertFalse($public->fresh()->isMemberRate());
        $this->assertSame(BuybackAppraisal::RATE_PUBLIC, $odd->fresh()->rate_tier, 'anything but member is stored as public');
    }

    public function test_the_members_page_shows_member_rates_with_the_public_ones_beside_them(): void
    {
        Schema::create('invTypes', function (Blueprint $table) {
            $table->integer('typeID')->primary();
            $table->string('typeName');
        });
        DB::table('invTypes')->insert([['typeID' => 34, 'typeName' => 'Tritanium'], ['typeID' => 35, 'typeName' => 'Pyerite']]);
        $setting = BuybackSetting::create([
            'corporation_id' => 98000001,
            'enabled' => true,
            'target_type' => BuybackSetting::TARGET_MY_CORP,
            'base_percentage' => 90,
            'member_bonus' => 5,
            'public_show_all_rules' => true,
        ]);
        BuybackPricingRule::insert([
            ['setting_id' => $setting->id, 'type' => 'item', 'type_id' => 34, 'percentage' => 98, 'excluded' => false, 'featured' => true, 'priority' => 30],
            ['setting_id' => $setting->id, 'type' => 'item', 'type_id' => 35, 'percentage' => 80, 'excluded' => false, 'featured' => false, 'priority' => 30],
            ['setting_id' => $setting->id, 'type' => 'item', 'type_id' => 36, 'percentage' => null, 'excluded' => true, 'featured' => false, 'priority' => 30],
        ]);
        $service = new BuybackPublicService();

        $member = $service->rates($setting, new PriceProviderService(), true);
        $public = $service->rates($setting, new PriceProviderService());

        $this->assertTrue($member['member']);
        $this->assertSame(95.0, $member['base_percentage']);
        $this->assertSame(90.0, $member['public_base_percentage']);
        $this->assertSame(['Tritanium', 'Pyerite'], array_column($member['items'], 'name'));
        $this->assertSame([100.0, 85.0], array_column($member['items'], 'percentage'));
        $this->assertSame([98.0, 80.0], array_column($member['items'], 'public_percentage'));

        $this->assertFalse($public['member']);
        $this->assertSame(90.0, $public['base_percentage']);
        $this->assertSame([98.0, 80.0], array_column($public['items'], 'percentage'));
    }
}
