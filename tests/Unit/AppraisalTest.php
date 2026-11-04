<?php

namespace BuybackManager\Tests\Unit;

use BuybackManager\Models\BuybackPricingRule;
use BuybackManager\Models\BuybackSetting;
use BuybackManager\Services\AppraisalService;
use BuybackManager\Tests\KnownTypes;
use BuybackManager\Tests\ScriptedPrices;
use BuybackManager\Tests\Sqlite;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\TestCase;

class AppraisalTest extends TestCase
{
    protected function setUp(): void
    {
        Sqlite::migrate('000008', '000012');
        Carbon::setTestNow('2026-11-04 10:00:00');
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

    public function test_the_default_rate_pays_on_the_sell_side(): void
    {
        $result = $this->service()->applyRules([self::item(34, 100)], [34 => ['buy' => 5.0, 'sell' => 6.0]], $this->setting());

        $line = $result['items'][0];
        $this->assertSame('sell', $line['price_side']);
        $this->assertSame(90.0, $line['percentage']);
        $this->assertEqualsWithDelta(5.4, $line['buyback_price'], 1e-9);
        $this->assertEqualsWithDelta(540.0, $result['total_buyback'], 1e-9);
        $this->assertEqualsWithDelta(600.0, $result['total_market'], 1e-9);
    }

    public function test_with_janice_the_default_side_is_its_price_method(): void
    {
        foreach (['buy' => 5.0, 'sell' => 6.0, 'split' => 5.5, 'nonsense' => 5.0] as $method => $expected) {
            $setting = $this->setting(['price_provider' => 'janice', 'janice_price_method' => $method]);
            $line = $this->service()->applyRules([self::item(34)], [34 => ['buy' => 5.0, 'sell' => 6.0]], $setting)['items'][0];

            $this->assertEqualsWithDelta($expected, $line['market_price'], 1e-9, $method);
        }
    }

    public function test_the_narrowest_rule_wins_and_a_rule_can_pay_on_its_own_side(): void
    {
        $rules = [
            ['type' => 'category', 'type_id' => 4, 'percentage' => 50, 'priority' => 10],
            ['type' => 'group', 'type_id' => 18, 'percentage' => 70, 'priority' => 20],
            ['type' => 'item', 'type_id' => 34, 'percentage' => 95, 'price_side' => 'buy', 'priority' => 30],
        ];
        $items = [self::item(34), self::item(35), self::item(36, 1, 99, 4), self::item(37, 1, 99, 99)];
        $prices = array_fill_keys([34, 35, 36, 37], ['buy' => 5.0, 'sell' => 6.0]);

        $lines = $this->service()->applyRules($items, $prices, $this->setting([], $rules))['items'];

        $this->assertSame([95.0, 70.0, 50.0, 90.0], array_column($lines, 'percentage'));
        $this->assertSame(['buy', 'sell', 'sell', 'sell'], array_column($lines, 'price_side'));
    }

    public function test_a_rule_given_a_higher_priority_beats_a_narrower_one(): void
    {
        $rules = [
            ['type' => 'item', 'type_id' => 34, 'percentage' => 95, 'priority' => 30],
            ['type' => 'category', 'type_id' => 4, 'percentage' => 60, 'priority' => 40],
        ];

        $line = $this->service()->applyRules([self::item(34)], [34 => ['buy' => 5.0, 'sell' => 6.0]], $this->setting([], $rules))['items'][0];

        $this->assertSame(60.0, $line['percentage']);
    }

    public function test_excluded_items_and_unlisted_ones_in_allow_list_mode_are_not_accepted(): void
    {
        $rules = [
            ['type' => 'item', 'type_id' => 34, 'percentage' => 95, 'priority' => 30],
            ['type' => 'item', 'type_id' => 35, 'excluded' => true, 'priority' => 30],
        ];
        $prices = array_fill_keys([34, 35, 36], ['buy' => 5.0, 'sell' => 6.0]);

        $everything = $this->service()->applyRules([self::item(34), self::item(35), self::item(36)], $prices, $this->setting([], $rules));
        $listedOnly = $this->service()->applyRules([self::item(34), self::item(35), self::item(36)], $prices, $this->setting(['buy_listed_only' => true], $rules));

        $this->assertSame([34, 36], array_column($everything['items'], 'type_id'));
        $this->assertSame([[35, 'excluded']], array_map(fn ($e) => [$e['type_id'], $e['reason']], $everything['excluded']));
        $this->assertSame([34], array_column($listedOnly['items'], 'type_id'));
        $this->assertSame([[35, 'excluded'], [36, 'excluded']], array_map(fn ($e) => [$e['type_id'], $e['reason']], $listedOnly['excluded']));
    }

    public function test_an_item_with_no_price_on_the_side_its_rule_pays_on_is_not_accepted(): void
    {
        $rules = [
            ['type' => 'item', 'type_id' => 35, 'percentage' => 90, 'price_side' => 'buy', 'priority' => 30],
            ['type' => 'item', 'type_id' => 36, 'percentage' => 90, 'price_side' => 'split', 'priority' => 30],
        ];
        $prices = [35 => ['buy' => null, 'sell' => 6.0], 36 => ['buy' => null, 'sell' => 6.0]];

        $result = $this->service()->applyRules([self::item(34), self::item(35), self::item(36)], $prices, $this->setting([], $rules));

        $this->assertSame([[34, 'unpriced'], [35, 'unpriced']], array_map(fn ($e) => [$e['type_id'], $e['reason']], $result['excluded']));
        $this->assertSame([36], array_column($result['items'], 'type_id'));
        $this->assertEqualsWithDelta(6.0, $result['items'][0]['market_price'], 1e-9);
    }

    public function test_the_price_on_each_side(): void
    {
        $this->assertSame(5.0, AppraisalService::pickSidePrice(['buy' => 5.0, 'sell' => 6.0], 'buy'));
        $this->assertSame(6.0, AppraisalService::pickSidePrice(['buy' => 5.0, 'sell' => 6.0], 'sell'));
        $this->assertSame(5.5, AppraisalService::pickSidePrice(['buy' => 5.0, 'sell' => 6.0], 'split'));
        $this->assertSame(6.0, AppraisalService::pickSidePrice(['buy' => null, 'sell' => 6.0], 'split'));
        $this->assertNull(AppraisalService::pickSidePrice(['buy' => null, 'sell' => 6.0], 'buy'));
        $this->assertNull(AppraisalService::pickSidePrice(['buy' => 0.0, 'sell' => 0.0], 'split'));
        $this->assertNull(AppraisalService::pickSidePrice(null, 'sell'));
    }

    public function test_a_paste_becomes_a_quote_that_lists_what_is_not_accepted_and_why(): void
    {
        $prices = (new ScriptedPrices())->answers('fuzzwork', 'jita', [
            1001 => ['buy' => 5.0, 'sell' => 6.0],
            1002 => ['buy' => 10.0, 'sell' => 12.0],
        ]);
        $setting = $this->setting([], [['type' => 'item', 'type_id' => 1002, 'excluded' => true, 'priority' => 30]]);

        $result = $this->service($prices)->appraise("Tritanium 1000\nPyerite 50\nMexallon 3\nNonsense line", $setting);

        $this->assertTrue($result['success']);
        $this->assertSame(['Tritanium'], array_column($result['items'], 'type_name'));
        $this->assertSame([['Pyerite', 'excluded'], ['Mexallon', 'unpriced']], array_map(fn ($e) => [$e['type_name'], $e['reason']], $result['excluded']));
        $this->assertSame(['Nonsense line'], $result['unrecognised']);
        $this->assertEqualsWithDelta(5400.0, $result['total_buyback_value'], 1e-6);
        $this->assertSame('Jita', $result['market']);
        $this->assertSame('fuzzwork', $result['provider']);
        $this->assertNull($result['pricing_note']);
    }

    public function test_a_paste_that_names_nothing_says_how_to_paste(): void
    {
        $result = $this->service()->appraise("Nonsense line\n12345", $this->setting());

        $this->assertFalse($result['success']);
        $this->assertStringStartsWith('None of these lines named an item we know.', $result['message']);
    }

    public function test_when_nothing_can_be_bought_the_seller_is_told_why(): void
    {
        $prices = (new ScriptedPrices())->answers('fuzzwork', 'jita', [1001 => ['buy' => 5.0, 'sell' => 6.0]]);
        $excluded = [['type' => 'item', 'type_id' => 1001, 'excluded' => true, 'priority' => 30]];
        $service = $this->service($prices);

        $this->assertSame(
            'No market price could be found for any of these items right now, so we cannot value this list. Please try again in a few minutes.',
            $service->appraise('Pyerite 5', $this->setting())['message']
        );
        $this->assertSame(
            'We do not buy any of these items. Ask a buyback director if you want a custom quote.',
            $service->appraise('Tritanium 5', $this->setting([], $excluded))['message']
        );
        $this->assertSame(
            'None of these items can be valued: we do not buy 1 of them, and 1 has no market price right now.',
            $service->appraise("Tritanium 5\nPyerite 5", $this->setting([], $excluded))['message']
        );
    }

    public function test_a_long_market_name_is_cut_to_fit_its_column(): void
    {
        $market = str_repeat('a-long-market-name-', 5);
        $prices = (new ScriptedPrices())->answers('manager-core', $market, [1001 => ['buy' => 5.0, 'sell' => 6.0]]);
        $prices->managerCore = true;

        $result = $this->service($prices)->appraise('Tritanium 5', $this->setting(['price_provider' => 'manager-core', 'manager_core_market' => $market]));

        // A Manager Core market name can run to 255 characters, and
        // buyback_appraisals.market holds 64. With no Manager Core tables here
        // the market is named by its key.
        $this->assertTrue($result['success']);
        $this->assertSame(64, mb_strlen($result['market']));
    }
}
