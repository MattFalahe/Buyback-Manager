<?php

namespace BuybackManager\Tests\Unit;

use BuybackManager\Services\Pricing\PriceProviderService;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * Each provider's answer read as both sides of the spread: the highest buy
 * order and the lowest sell order.
 */
class ProviderAnswerTest extends TestCase
{
    public function test_fuzzwork_gives_the_highest_buy_order_and_the_lowest_sell_order(): void
    {
        // Fuzzwork answers every id asked, as strings, with zeros for no orders.
        $data = json_decode('{
            "34": {"buy": {"max": "5.01", "min": "0.01"}, "sell": {"min": "5.50", "max": "99.00"}},
            "35": {"buy": {"max": "0"}, "sell": {"min": "12.00"}},
            "36": {"buy": {"max": "0"}, "sell": {"min": "0"}}
        }', true);

        $this->assertSame([
            34 => ['buy' => 5.01, 'sell' => 5.5],
            35 => ['buy' => null, 'sell' => 12.0],
        ], PriceProviderService::fuzzworkPrices($data, [34, 35, 36, 37]));
    }

    public function test_janice_gives_its_immediate_prices_for_the_items_asked(): void
    {
        $items = [
            ['itemType' => ['eid' => 34, 'name' => 'Tritanium'], 'immediatePrices' => ['buyPrice' => 4.8, 'sellPrice' => 5.2], 'top5AveragePrices' => ['buyPrice' => 4.5]],
            ['itemType' => ['eid' => 35], 'immediatePrices' => ['buyPrice' => 0, 'sellPrice' => 11.0]],
            ['itemType' => ['eid' => 99], 'immediatePrices' => ['buyPrice' => 1.0, 'sellPrice' => 2.0]],
            'not an item',
        ];

        $this->assertSame([
            34 => ['buy' => 4.8, 'sell' => 5.2],
            35 => ['buy' => null, 'sell' => 11.0],
        ], PriceProviderService::janicePrices($items, [34, 35]));
    }

    public function test_manager_core_gives_both_sides_and_leaves_what_it_has_not_priced_unanswered(): void
    {
        $raw = [
            34 => ['buy' => ['max' => 5.0, 'min' => 1.0, 'updated_at' => '2026-11-04 09:00:00'], 'sell' => ['min' => 5.5, 'max' => 50.0, 'updated_at' => '2026-11-04 09:00:00']],
            35 => ['buy' => ['max' => 8.0, 'updated_at' => '2026-11-03 20:00:00'], 'sell' => null],
            36 => ['buy' => null, 'sell' => null],
        ];

        $read = PriceProviderService::managerCorePrices($raw, [34, 35, 36, 37], Carbon::parse('2026-11-04 02:00:00'));

        $this->assertSame([34 => ['buy' => 5.0, 'sell' => 5.5], 35 => ['buy' => 8.0, 'sell' => null]], $read['prices']);
        $this->assertSame([36, 37], $read['unanswered']);
        $this->assertSame(1, $read['stale']);
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
