<?php

namespace BuybackManager\Tests\Unit;

use BuybackManager\Models\BuybackPricingRule;
use BuybackManager\Models\BuybackSetting;
use BuybackManager\Services\AppraisalService;
use BuybackManager\Services\Pricing\PriceProviderService;
use BuybackManager\Tests\KnownTypes;
use BuybackManager\Tests\ScriptedPrices;
use BuybackManager\Tests\Sqlite;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\TestCase;

/**
 * A top buy order far above the rest is passed over for the average of the
 * top 5%, so a lone high order on a thin market cannot set a quote.
 */
class ThinMarketTest extends TestCase
{
    private ScriptedPrices $prices;

    protected function setUp(): void
    {
        Sqlite::migrate('000008', '000012');
        Carbon::setTestNow('2026-11-22 12:00:00');
        $this->prices = new ScriptedPrices();
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
            'thin_market_guard_percent' => 10,
        ]);
        $setting->id = 7;
        $setting->exists = true;
        $setting->setRelation('corporation', null);
        $setting->setRelation('pricingRules', new Collection(array_map(fn ($rule) => new BuybackPricingRule($rule), $rules)));

        return $setting;
    }

    private static function item(int $typeId, int $quantity = 1): array
    {
        return ['type_id' => $typeId, 'type_name' => "Type $typeId", 'group_id' => 18, 'category_id' => 4, 'quantity' => $quantity];
    }

    private function lines(BuybackSetting $setting, array $items, array $prices, array $recipes = []): array
    {
        return (new AppraisalService(new KnownTypes(), $this->prices))->applyRules($items, $prices, $setting, $recipes)['items'];
    }

    public function test_a_buy_order_past_the_guard_is_passed_over_for_the_average(): void
    {
        [$prices, $thin] = AppraisalService::guardThinMarkets([
            34 => ['buy' => 130.0, 'sell' => 140.0, 'buy_percentile' => 100.0],
            35 => ['buy' => 125.0, 'sell' => 140.0, 'buy_percentile' => 100.0],
            36 => ['buy' => 500.0, 'sell' => 600.0],
        ], 25.0);

        $this->assertSame(['buy' => 100.0, 'sell' => 140.0, 'buy_percentile' => 100.0], $prices[34]);
        // Exactly at the guard is a market, not a thin one.
        $this->assertSame(125.0, $prices[35]['buy']);
        // No average from the provider, so nothing to compare with.
        $this->assertSame(500.0, $prices[36]['buy']);
        $this->assertSame([34 => true], $thin);
    }

    public function test_a_guard_of_zero_is_off(): void
    {
        [$prices, $thin] = AppraisalService::guardThinMarkets([34 => ['buy' => 900.0, 'sell' => 950.0, 'buy_percentile' => 100.0]], 0.0);

        $this->assertSame(900.0, $prices[34]['buy']);
        $this->assertSame([], $thin);
    }

    public function test_only_a_line_that_reads_the_buy_side_is_marked(): void
    {
        $setting = $this->setting([], [
            ['type' => 'item', 'type_id' => 34, 'percentage' => 90, 'price_side' => 'buy'],
            ['type' => 'item', 'type_id' => 35, 'percentage' => 90, 'price_side' => 'sell'],
            ['type' => 'item', 'type_id' => 36, 'percentage' => 90, 'price_side' => 'split'],
        ]);
        $thin = ['buy' => 150.0, 'sell' => 160.0, 'buy_percentile' => 100.0];

        $lines = $this->lines($setting, [self::item(34), self::item(35), self::item(36)], [34 => $thin, 35 => $thin, 36 => $thin]);

        $this->assertSame([true, false, true], array_column($lines, 'thin_market'));
        $this->assertEqualsWithDelta(100.0, $lines[0]['market_price'], 1e-9);
        $this->assertEqualsWithDelta(160.0, $lines[1]['market_price'], 1e-9);
        $this->assertEqualsWithDelta(130.0, $lines[2]['market_price'], 1e-9);
    }

    public function test_a_refined_line_is_marked_when_one_of_its_minerals_was_thin(): void
    {
        $setting = $this->setting([], [
            ['type' => 'item', 'type_id' => 45492, 'percentage' => 90, 'price_side' => 'buy', 'refined' => true, 'refine_yield' => 100],
        ]);
        $recipes = [45492 => ['portion' => 100, 'materials' => [35 => 6000, 36 => 400]]];

        $lines = $this->lines($setting, [self::item(45492, 100)], [
            35 => ['buy' => 10.0, 'sell' => 11.0, 'buy_percentile' => 9.9],
            36 => ['buy' => 80.0, 'sell' => 90.0, 'buy_percentile' => 50.0],
        ], $recipes);

        $this->assertTrue($lines[0]['thin_market']);
        // (6000 x 10 + 400 x 50) / 100 per unit.
        $this->assertEqualsWithDelta(800.0, $lines[0]['market_price'], 1e-9);
    }

    public function test_through_manager_core_only_a_fuzzwork_price_is_guarded(): void
    {
        // Hammerhead II as dev priced it at Jita through Manager Core's
        // Goonpraisal on 22 Nov, and a Fuzzwork price with a lone high order.
        $at = '2026-11-22 11:00:00';
        $raw = [
            2185 => ['buy' => ['max' => 781700.0, 'percentile' => 0.01, 'strategy' => 'goonpraisal', 'updated_at' => $at], 'sell' => ['min' => 810000.0, 'updated_at' => $at]],
            587 => ['buy' => ['max' => 300000.0, 'percentile' => 233500.0, 'strategy' => 'fuzzwork', 'updated_at' => $at], 'sell' => ['min' => 288000.0, 'updated_at' => $at]],
        ];
        $read = PriceProviderService::managerCorePrices($raw, [2185, 587], Carbon::now()->subHours(8));
        $setting = $this->setting(['price_provider' => 'manager-core'], [
            ['type' => 'item', 'type_id' => 2185, 'percentage' => 90, 'price_side' => 'buy'],
            ['type' => 'item', 'type_id' => 587, 'percentage' => 90, 'price_side' => 'buy'],
        ]);

        $lines = $this->lines($setting, [self::item(2185), self::item(587)], $read['prices']);

        $this->assertSame([false, true], array_column($lines, 'thin_market'));
        $this->assertEqualsWithDelta(781700.0, $lines[0]['market_price'], 1e-9);
        $this->assertEqualsWithDelta(233500.0, $lines[1]['market_price'], 1e-9);
    }

    public function test_a_janice_price_is_not_guarded(): void
    {
        // PLEX as Janice answered at Jita on 22 Nov, its top-5% buy figure at
        // 2% of the best buy order.
        $prices = PriceProviderService::janicePrices([
            ['itemType' => ['eid' => 44992], 'immediatePrices' => ['buyPrice' => 4886000, 'sellPrice' => 5050000], 'top5AveragePrices' => ['buyPrice' => 112642.73]],
        ], [44992]);
        $setting = $this->setting(['price_provider' => 'janice'], [
            ['type' => 'item', 'type_id' => 44992, 'percentage' => 90, 'price_side' => 'buy'],
        ]);

        $lines = $this->lines($setting, [self::item(44992)], $prices);

        $this->assertFalse($lines[0]['thin_market']);
        $this->assertEqualsWithDelta(4886000.0, $lines[0]['market_price'], 1e-9);
    }

    public function test_the_average_is_cached_with_the_buy_price_and_guards_a_cached_quote(): void
    {
        $setting = $this->setting();
        $this->prices->answers('fuzzwork', 'jita', [34 => ['buy' => 150.0, 'sell' => 160.0, 'buy_percentile' => 100.0]]);
        $this->prices->pricesFor($setting, [34]);

        Carbon::setTestNow('2026-11-22 12:30:00');
        $cached = $this->prices->pricesFor($setting, [34])['prices'];

        $this->assertCount(1, $this->prices->asked, 'the second quote came from the cache');
        $this->assertEquals(['buy' => 150.0, 'sell' => 160.0, 'buy_percentile' => 100.0], $cached[34]);
    }

    public function test_an_answer_without_an_average_clears_the_one_cached(): void
    {
        $setting = $this->setting();
        $this->prices->answers('fuzzwork', 'jita', [34 => ['buy' => 150.0, 'sell' => 160.0, 'buy_percentile' => 100.0]]);
        $this->prices->pricesFor($setting, [34]);

        Carbon::setTestNow('2026-11-22 13:30:00');
        $this->prices->answers('fuzzwork', 'jita', [34 => ['buy' => 150.0, 'sell' => 160.0]]);
        $this->prices->pricesFor($setting, [34]);

        Carbon::setTestNow('2026-11-22 14:00:00');
        $this->assertEquals(['buy' => 150.0, 'sell' => 160.0], $this->prices->pricesFor($setting, [34])['prices'][34]);
    }
}
