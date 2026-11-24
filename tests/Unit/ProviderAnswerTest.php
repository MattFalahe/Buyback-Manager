<?php

namespace BuybackManager\Tests\Unit;

use BuybackManager\Services\Pricing\PriceProviderService;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * Each provider's answer read as both sides of the spread: the highest buy
 * order and the lowest sell order, with the average of the top 5% of buy
 * orders when Fuzzwork gave it.
 */
class ProviderAnswerTest extends TestCase
{
    public function test_fuzzwork_gives_the_highest_buy_order_and_the_lowest_sell_order(): void
    {
        // Fuzzwork answers every id asked, as strings, with zeros for no orders.
        $data = json_decode('{
            "34": {"buy": {"max": "5.01", "min": "0.01", "percentile": "4.90"}, "sell": {"min": "5.50", "max": "99.00"}},
            "35": {"buy": {"max": "0"}, "sell": {"min": "12.00"}},
            "36": {"buy": {"max": "0"}, "sell": {"min": "0"}}
        }', true);

        $this->assertSame([
            34 => ['buy' => 5.01, 'sell' => 5.5, 'buy_percentile' => 4.9],
            35 => ['buy' => null, 'sell' => 12.0],
        ], PriceProviderService::fuzzworkPrices($data, [34, 35, 36, 37]));
    }

    public function test_janice_gives_its_immediate_prices_for_the_items_asked_and_no_average(): void
    {
        // PLEX as Janice answered at Jita on 22 Nov: its top-5% buy figure
        // at 2% of the best buy order.
        $items = [
            ['itemType' => ['eid' => 34, 'name' => 'Tritanium'], 'immediatePrices' => ['buyPrice' => 4.8, 'sellPrice' => 5.2], 'top5AveragePrices' => ['buyPrice' => 4.5]],
            ['itemType' => ['eid' => 44992, 'name' => 'PLEX'], 'immediatePrices' => ['buyPrice' => 4886000, 'sellPrice' => 5050000], 'top5AveragePrices' => ['buyPrice' => 112642.73]],
            ['itemType' => ['eid' => 35], 'immediatePrices' => ['buyPrice' => 0, 'sellPrice' => 11.0]],
            ['itemType' => ['eid' => 99], 'immediatePrices' => ['buyPrice' => 1.0, 'sellPrice' => 2.0]],
            'not an item',
        ];

        $this->assertSame([
            34 => ['buy' => 4.8, 'sell' => 5.2],
            44992 => ['buy' => 4886000.0, 'sell' => 5050000.0],
            35 => ['buy' => null, 'sell' => 11.0],
        ], PriceProviderService::janicePrices($items, [34, 44992, 35]));
    }

    public function test_janice_brings_the_packaged_volumes_that_differ_from_assembled(): void
    {
        $items = [
            ['itemType' => ['eid' => 587, 'volume' => 27289, 'packagedVolume' => 2500]],
            ['itemType' => ['eid' => 34, 'volume' => 0.01, 'packagedVolume' => 0.01]],
            ['itemType' => ['eid' => 648, 'volume' => 250000, 'packagedVolume' => 20000]],
            ['itemType' => ['eid' => 35, 'volume' => 0.01]],
        ];

        $this->assertSame([587 => 2500.0], PriceProviderService::janicePackagedVolumes($items, [587, 34, 35]));
    }

    public function test_manager_core_gives_both_sides_and_leaves_what_it_has_not_priced_unanswered(): void
    {
        $raw = [
            34 => ['buy' => ['max' => 5.0, 'min' => 1.0, 'percentile' => 4.75, 'strategy' => 'fuzzwork', 'updated_at' => '2026-11-04 09:00:00'], 'sell' => ['min' => 5.5, 'max' => 50.0, 'updated_at' => '2026-11-04 09:00:00']],
            35 => ['buy' => ['max' => 8.0, 'updated_at' => '2026-11-04 03:00:00'], 'sell' => null],
            36 => ['buy' => null, 'sell' => null],
        ];

        $read = PriceProviderService::managerCorePrices($raw, [34, 35, 36, 37], Carbon::parse('2026-11-04 02:00:00'));

        $this->assertSame([34 => ['buy' => 5.0, 'sell' => 5.5, 'buy_percentile' => 4.75], 35 => ['buy' => 8.0, 'sell' => null]], $read['prices']);
        $this->assertSame([36, 37], $read['unanswered']);
        $this->assertSame([], $read['stale']);
    }

    public function test_only_a_manager_core_percentile_from_fuzzwork_comes_through(): void
    {
        // Hobgoblin II through Manager Core's Goonpraisal at Jita on 22 Nov:
        // a 0.01 ISK order for millions of units set its percentile.
        $side = fn (float $max, float $percentile, ?string $strategy) => ['max' => $max, 'percentile' => $percentile, 'updated_at' => '2026-11-04 09:00:00']
            + ($strategy === null ? [] : ['strategy' => $strategy]);
        $sell = ['min' => 306500.0, 'updated_at' => '2026-11-04 09:00:00'];
        $raw = [
            2456 => ['buy' => $side(285900.0, 0.01, 'goonpraisal'), 'sell' => $sell],
            2185 => ['buy' => $side(285900.0, 250000.0, 'janice'), 'sell' => $sell],
            2203 => ['buy' => $side(285900.0, 250000.0, 'orders'), 'sell' => $sell],
            2446 => ['buy' => $side(285900.0, 250000.0, 'seat-price-provider'), 'sell' => $sell],
            2464 => ['buy' => $side(285900.0, 250000.0, null), 'sell' => $sell],
            2476 => ['buy' => $side(285900.0, 250000.0, ' Fuzzwork '), 'sell' => $sell],
        ];

        $read = PriceProviderService::managerCorePrices($raw, array_keys($raw), Carbon::parse('2026-11-04 02:00:00'));

        $plain = ['buy' => 285900.0, 'sell' => 306500.0];
        $this->assertSame([
            2456 => $plain,
            2185 => $plain,
            2203 => $plain,
            2446 => $plain,
            2464 => $plain,
            2476 => $plain + ['buy_percentile' => 250000.0],
        ], $read['prices']);
    }

    public function test_a_manager_core_price_too_old_to_use_is_left_for_the_fallback(): void
    {
        $raw = [
            34 => ['buy' => ['max' => 5.0, 'updated_at' => '2026-11-04 09:00:00'], 'sell' => ['min' => 5.5, 'updated_at' => '2026-11-04 09:00:00']],
            35 => ['buy' => ['max' => 8.0, 'updated_at' => '2026-11-03 20:00:00'], 'sell' => null],
            36 => ['buy' => null, 'sell' => null],
        ];

        $read = PriceProviderService::managerCorePrices($raw, [34, 35, 36], Carbon::parse('2026-11-04 02:00:00'));

        $this->assertSame([34 => ['buy' => 5.0, 'sell' => 5.5]], $read['prices']);
        $this->assertSame([35, 36], $read['unanswered']);
        $this->assertSame([35], $read['stale']);
        $this->assertSame([35 => ['buy' => 8.0, 'sell' => null]], $read['old_prices']);
    }

    public function test_an_older_manager_core_answer_for_one_item_is_read_too(): void
    {
        $read = PriceProviderService::managerCorePrices(['buy' => ['max' => 5.0], 'sell' => ['min' => 5.5]], [34], Carbon::parse('2026-11-04 02:00:00'));

        $this->assertSame([34 => ['buy' => 5.0, 'sell' => 5.5]], $read['prices']);
    }

    public function test_a_side_with_no_orders_is_empty_and_an_item_with_none_has_no_price(): void
    {
        $this->assertSame(['buy' => null, 'sell' => 6.0], PriceProviderService::sides(0.0, 6.0));
        $this->assertSame(['buy' => 5.0, 'sell' => null], PriceProviderService::sides(5.0, -1.0));
        $this->assertNull(PriceProviderService::sides(0.0, 0.0));
    }
}
