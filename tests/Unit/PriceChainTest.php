<?php

namespace BuybackManager\Tests\Unit;

use BuybackManager\Models\BuybackPriceStatus;
use BuybackManager\Models\BuybackSetting;
use BuybackManager\Services\Pricing\PriceProviderService;
use BuybackManager\Tests\ScriptedPrices;
use BuybackManager\Tests\Sqlite;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class PriceChainTest extends TestCase
{
    private ScriptedPrices $prices;

    protected function setUp(): void
    {
        // A fresh install runs the old cache table's migration and then the
        // one that rebuilds it.
        Sqlite::migrate('000008', '000012');
        Carbon::setTestNow('2026-11-04 10:00:00');
        $this->prices = new ScriptedPrices();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Sqlite::close();
    }

    protected function assertPostConditions(): void
    {
        $this->assertSame([], $this->prices->unscripted, 'a provider was asked that nothing was scripted for');
    }

    private function setting(array $attributes = []): BuybackSetting
    {
        $setting = new BuybackSetting($attributes + [
            'corporation_id' => 98000001,
            'price_provider' => 'fuzzwork',
            'fallback_provider' => 'none',
            'price_source' => 'jita',
            'fallback_to_jita' => false,
            'price_cache_ttl_minutes' => 60,
        ]);
        $setting->id = 7;
        $setting->exists = true;
        $setting->setRelation('corporation', null);

        return $setting;
    }

    private static function sides(?float $buy, ?float $sell): array
    {
        return ['buy' => $buy, 'sell' => $sell];
    }

    public function test_a_fresh_answer_is_reused_and_only_the_rest_is_asked_for(): void
    {
        $setting = $this->setting();
        $this->prices->answers('fuzzwork', 'jita', [34 => self::sides(5.0, 6.0), 35 => self::sides(9.0, 10.0)]);

        $this->prices->pricesFor($setting, [34]);
        Carbon::setTestNow('2026-11-04 10:30:00');
        $result = $this->prices->pricesFor($setting, [34, 35]);

        $this->assertSame([['fuzzwork', 'jita', [34]], ['fuzzwork', 'jita', [35]]], $this->prices->asked);
        $this->assertEquals([34 => self::sides(5.0, 6.0), 35 => self::sides(9.0, 10.0)], $result['prices']);
        $this->assertNull($result['note']);

        Carbon::setTestNow('2026-11-04 11:01:00');
        $this->prices->pricesFor($setting, [34]);
        $this->assertSame(['fuzzwork', 'jita', [34]], $this->prices->asked[2]);
    }

    public function test_an_item_nobody_trades_is_not_asked_about_again_until_the_ttl_runs_out(): void
    {
        $setting = $this->setting();
        $this->prices->answers('fuzzwork', 'jita', []);

        $result = $this->prices->pricesFor($setting, [34, 35]);
        Carbon::setTestNow('2026-11-04 10:30:00');
        $this->prices->pricesFor($setting, [34, 35]);

        $this->assertFalse($result['stood_in']);
        $this->assertSame([], $result['prices']);
        $this->assertCount(1, $this->prices->asked);
        $this->assertSame([], $this->prices->alerts);
        $this->assertSame(0, BuybackPriceStatus::count());
    }

    public function test_a_side_with_no_orders_stays_empty_while_fresh_but_keeps_its_last_price_to_stand_in(): void
    {
        $setting = $this->setting();
        $this->prices->answers('fuzzwork', 'jita', [34 => self::sides(5.0, 6.0)]);
        $this->prices->pricesFor($setting, [34]);

        Carbon::setTestNow('2026-11-04 12:00:00');
        $this->prices->answers('fuzzwork', 'jita', [34 => self::sides(null, 7.0)]);
        $this->assertEquals([34 => self::sides(null, 7.0)], $this->prices->pricesFor($setting, [34])['prices']);

        Carbon::setTestNow('2026-11-04 12:30:00');
        $this->assertEquals([34 => self::sides(null, 7.0)], $this->prices->pricesFor($setting, [34])['prices']);
        $this->assertCount(2, $this->prices->asked);

        Carbon::setTestNow('2026-11-04 14:00:00');
        $this->prices->fails('fuzzwork', 'jita', 'Fuzzwork returned HTTP 503');
        $result = $this->prices->pricesFor($setting, [34]);

        $this->assertTrue($result['stood_in']);
        $this->assertSame([34], $result['from_cache']);
        $this->assertEquals([34 => self::sides(5.0, 7.0)], $result['prices']);
    }

    public function test_a_provider_that_fails_outright_hands_the_quote_to_the_fallback_at_the_same_market(): void
    {
        $setting = $this->setting(['price_source' => 'amarr', 'fallback_provider' => 'janice', 'janice_api_key' => 'test-key']);
        $this->prices->fails('fuzzwork', 'amarr', 'Fuzzwork returned HTTP 503');
        $this->prices->answers('janice', 'amarr', [34 => self::sides(4.0, 5.0)]);

        $result = $this->prices->pricesFor($setting, [34]);

        $this->assertTrue($result['stood_in']);
        $this->assertSame([34], $result['from_fallback']);
        $this->assertEquals([34 => self::sides(4.0, 5.0)], $result['prices']);
        $this->assertSame('Fuzzwork did not answer, so Janice priced 1 item.', $result['note']);

        $this->assertCount(1, $this->prices->alerts);
        [$event, $payload] = $this->prices->alerts[0];
        $this->assertSame('buyback.pricing.failing', $event);
        $this->assertSame('Fuzzwork returned HTTP 503', $payload['error']);
        $this->assertSame('Janice, Amarr', $payload['fallback']);
        $this->assertTrue($payload['fallback_ok']);

        $status = BuybackPriceStatus::where('setting_id', 7)->first();
        $this->assertTrue($status->failing);
        $this->assertSame('janice', $status->fallback);
        $this->assertTrue($status->fallback_ok);
    }

    public function test_a_fallback_that_cannot_price_at_the_market_prices_at_jita_and_says_so(): void
    {
        $setting = $this->setting(['price_source' => 'dodixie', 'fallback_provider' => 'janice', 'janice_api_key' => 'test-key']);
        $this->prices->fails('fuzzwork', 'dodixie', 'Fuzzwork returned HTTP 503');
        $this->prices->answers('janice', 'jita', [34 => self::sides(4.0, 5.0)]);

        $result = $this->prices->pricesFor($setting, [34]);

        $this->assertSame('Fuzzwork did not answer, so Janice priced 1 item at Jita.', $result['note']);
        $this->assertSame('Janice cannot price at Dodixie, so it prices at Jita.', $this->prices->alerts[0][1]['fallback_note']);
    }

    public function test_with_no_fallback_the_last_cached_price_stands_in_and_an_item_never_priced_has_none(): void
    {
        $setting = $this->setting();
        $this->prices->answers('fuzzwork', 'jita', [34 => self::sides(5.0, 6.0)]);
        $this->prices->pricesFor($setting, [34]);

        Carbon::setTestNow('2026-11-04 12:00:00');
        $this->prices->fails('fuzzwork', 'jita', 'Connection timed out');
        $result = $this->prices->pricesFor($setting, [34, 35]);

        $this->assertEquals([34 => self::sides(5.0, 6.0)], $result['prices']);
        $this->assertSame([34], $result['from_cache']);
        $this->assertSame('Fuzzwork did not answer. 1 item kept the last cached price.', $result['note']);
        $this->assertNull($this->prices->alerts[0][1]['fallback']);
        $this->assertSame('No fallback provider is set.', $this->prices->alerts[0][1]['fallback_error']);
    }

    public function test_no_prices_at_all_for_items_priced_before_counts_as_not_answering(): void
    {
        $setting = $this->setting();
        $ids = [34, 35, 36, 37, 38];
        $this->prices->answers('fuzzwork', 'jita', array_fill_keys($ids, self::sides(5.0, 6.0)));
        $this->prices->pricesFor($setting, $ids);

        Carbon::setTestNow('2026-11-04 12:00:00');
        $this->prices->answers('fuzzwork', 'jita', []);
        $result = $this->prices->pricesFor($setting, $ids);

        $this->assertTrue($result['stood_in']);
        $this->assertEqualsCanonicalizing($ids, $result['from_cache']);
        $this->assertSame('The provider answered, but with no prices at all', $this->prices->alerts[0][1]['error']);
    }

    public function test_items_left_unanswered_go_to_the_fallback_then_to_the_cache(): void
    {
        $setting = $this->setting(['fallback_provider' => 'janice', 'janice_api_key' => 'test-key']);
        $this->prices->answers('fuzzwork', 'jita', [36 => self::sides(50.0, 60.0)]);
        $this->prices->pricesFor($setting, [36]);

        Carbon::setTestNow('2026-11-04 12:00:00');
        $this->prices->answers('fuzzwork', 'jita', [34 => self::sides(5.0, 6.0)], [35, 36], 'Fuzzwork returned HTTP 502');
        $this->prices->answers('janice', 'jita', [35 => self::sides(8.0, 9.0)]);
        $result = $this->prices->pricesFor($setting, [34, 35, 36]);

        $this->assertFalse($result['stood_in']);
        $this->assertSame(['janice', 'jita', [35, 36]], $this->prices->asked[2]);
        $this->assertSame([35], $result['from_fallback']);
        $this->assertSame([36], $result['from_cache']);
        $this->assertEquals([34 => self::sides(5.0, 6.0), 35 => self::sides(8.0, 9.0), 36 => self::sides(50.0, 60.0)], $result['prices']);
        $this->assertSame('Fuzzwork could not answer for 2 items, so Janice priced 1. 1 item kept the last cached price.', $result['note']);
        $this->assertSame([], $this->prices->alerts);
    }

    public function test_manager_core_is_read_on_every_quote_and_asked_to_keep_what_was_quoted_priced(): void
    {
        $this->prices->managerCore = true;
        $setting = $this->setting(['price_provider' => 'manager-core', 'manager_core_market' => 'jita', 'fallback_provider' => 'fuzzwork']);
        $this->prices->answers('manager-core', 'jita', [34 => self::sides(5.0, 6.0)], [35]);
        $this->prices->answers('fuzzwork', 'jita', [35 => self::sides(8.0, 9.0)]);

        $result = $this->prices->pricesFor($setting, [34, 35]);
        Carbon::setTestNow('2026-11-04 10:05:00');
        $this->prices->pricesFor($setting, [34]);

        $this->assertSame([35], $result['from_fallback']);
        $this->assertSame('Manager Core had no price yet for 1 item, so Fuzzwork priced 1.', $result['note']);
        $this->assertSame([['manager-core', 'jita', [34, 35]], ['fuzzwork', 'jita', [35]], ['manager-core', 'jita', [34]]], $this->prices->asked);
        $this->assertSame([[34, 35], 'jita', true], $this->prices->subscribed[0]);
    }

    public function test_a_market_an_admin_set_in_manager_core_wins_over_the_corporations_own(): void
    {
        $this->prices->managerCore = true;
        $setting = $this->setting(['price_provider' => 'manager-core', 'manager_core_market' => 'jita']);

        $this->prices->preference = ['market' => 'c-j6mt', 'admin_overridden' => true];
        $this->assertSame('c-j6mt', $this->prices->marketFor($setting));

        // A preference Buyback registered itself mirrors one corporation only.
        $this->prices->preference = ['market' => 'amarr', 'admin_overridden' => false];
        $this->assertSame('jita', $this->prices->marketFor($setting));
    }

    public function test_a_manager_core_market_of_its_own_is_cached_apart_from_the_hubs(): void
    {
        $this->assertSame('mc:c-j6mt', $this->prices->cacheMarket('manager-core', 'c-j6mt'));
        $this->assertSame('amarr', $this->prices->cacheMarket('manager-core', 'amarr'));
        $this->assertSame('10000060', $this->prices->cacheMarket('fuzzwork', '10000060'));
    }

    public function test_a_side_with_no_orders_at_another_hub_takes_jitas_price_when_asked_to(): void
    {
        $setting = $this->setting(['price_source' => 'amarr', 'fallback_to_jita' => true]);
        $this->prices->answers('fuzzwork', 'amarr', [34 => self::sides(null, 6.0), 35 => self::sides(8.0, 9.0)]);
        $this->prices->answers('fuzzwork', 'jita', [34 => self::sides(5.0, 5.5)]);

        $result = $this->prices->pricesFor($setting, [34, 35]);

        $this->assertEquals([34 => self::sides(5.0, 6.0), 35 => self::sides(8.0, 9.0)], $result['prices']);
        $this->assertSame([34], $result['from_jita']);
        $this->assertSame(['fuzzwork', 'jita', [34]], $this->prices->asked[1]);
        $this->assertSame("1 item with no orders on one side at Amarr took Jita's price for that side.", $result['note']);
    }

    public function test_without_the_jita_fill_a_side_with_no_orders_stays_empty(): void
    {
        $setting = $this->setting(['price_source' => 'amarr', 'fallback_to_jita' => false]);
        $this->prices->answers('fuzzwork', 'amarr', [34 => self::sides(null, 6.0)]);

        $result = $this->prices->pricesFor($setting, [34]);

        $this->assertEquals([34 => self::sides(null, 6.0)], $result['prices']);
        $this->assertCount(1, $this->prices->asked);
    }

    public function test_the_alert_goes_out_once_when_the_provider_stops_answering_and_once_when_it_answers_again(): void
    {
        $setting = $this->setting();
        $this->prices->fails('fuzzwork', 'jita', 'Fuzzwork returned HTTP 503');

        foreach (['10:00', '10:20', '10:40'] as $time) {
            Carbon::setTestNow("2026-11-04 $time:00");
            $this->prices->pricesFor($setting, [34]);
        }

        $this->assertCount(1, $this->prices->alerts);
        $this->assertSame('2026-11-04 10:00:00', BuybackPriceStatus::first()->since->toDateTimeString());

        Carbon::setTestNow('2026-11-04 11:00:00');
        $this->prices->answers('fuzzwork', 'jita', [34 => self::sides(5.0, 6.0)]);
        $this->prices->pricesFor($setting, [34]);
        Carbon::setTestNow('2026-11-04 12:30:00');
        $this->prices->pricesFor($setting, [34]);

        $this->assertSame(['buyback.pricing.failing', 'buyback.pricing.recovered'], array_column($this->prices->alerts, 0));
        $this->assertSame(Carbon::parse('2026-11-04 10:00:00')->toIso8601String(), $this->prices->alerts[1][1]['since']);

        $status = BuybackPriceStatus::first();
        $this->assertFalse($status->failing);
        $this->assertNull($status->since);
        $this->assertSame('2026-11-04 12:30:00', $status->last_success->toDateTimeString());
    }

    public function test_while_the_provider_is_down_its_fallback_stopping_too_is_news(): void
    {
        $setting = $this->setting(['fallback_provider' => 'janice', 'janice_api_key' => 'test-key']);
        $this->prices->fails('fuzzwork', 'jita', 'Fuzzwork returned HTTP 503');
        $this->prices->answers('janice', 'jita', [34 => self::sides(4.0, 5.0)]);
        $this->prices->pricesFor($setting, [34]);

        $this->prices->fails('janice', 'jita', 'Janice refused the request with HTTP 429 (too many requests)');
        foreach (['10:20', '10:40'] as $time) {
            Carbon::setTestNow("2026-11-04 $time:00");
            $this->prices->pricesFor($setting, [34]);
        }

        $this->assertSame([true, false], array_map(fn ($alert) => $alert[1]['fallback_ok'], $this->prices->alerts));
        $this->assertSame('Janice refused the request with HTTP 429 (too many requests)', $this->prices->alerts[1][1]['fallback_error']);
    }

    public function test_only_a_change_is_news(): void
    {
        $this->assertTrue(PriceProviderService::statusChanged(false, null, false, false, 'janice', true), 'stops answering');
        $this->assertFalse(PriceProviderService::statusChanged(true, 'janice', true, false, 'janice', true), 'still down, same fallback');
        $this->assertTrue(PriceProviderService::statusChanged(true, 'janice', true, false, 'janice', false), 'the fallback stops too');
        $this->assertTrue(PriceProviderService::statusChanged(true, 'janice', true, false, 'fuzzwork', true), 'another fallback stands in');
        $this->assertTrue(PriceProviderService::statusChanged(true, null, false, true, null, false), 'answers again');
        $this->assertFalse(PriceProviderService::statusChanged(false, null, false, true, null, false), 'still answering');
    }

    public function test_a_fallback_that_cannot_run_says_why(): void
    {
        $why = fn (array $attributes) => $this->prices->fallbackFor($this->setting($attributes), 'fuzzwork', 'jita')['why'];

        $this->assertSame('No fallback provider is set.', $why(['fallback_provider' => 'none']));
        $this->assertSame('The fallback provider is the price provider itself.', $why(['fallback_provider' => 'fuzzwork']));
        $this->assertSame('The fallback provider cannot run: Janice has no API key.', $why(['fallback_provider' => 'janice']));
        $this->assertSame('The fallback provider cannot run: Manager Core is not installed.', $why(['fallback_provider' => 'manager-core']));
        $this->assertNull($why(['fallback_provider' => 'janice', 'janice_api_key' => 'test-key']));
    }

    public function test_who_can_price_where(): void
    {
        $this->assertTrue($this->prices->canPriceAt('fuzzwork', 'hek'));
        $this->assertTrue($this->prices->canPriceAt('fuzzwork', '10000060'));
        $this->assertTrue($this->prices->canPriceAt('janice', 'amarr'));
        $this->assertFalse($this->prices->canPriceAt('janice', 'dodixie'));
        $this->assertFalse($this->prices->canPriceAt('janice', '10000060'));
        $this->assertTrue($this->prices->canPriceAt('manager-core', 'rens'));
        $this->assertFalse($this->prices->canPriceAt('manager-core', 'c-j6mt'));
    }

    public function test_the_market_a_corporation_prices_at(): void
    {
        $this->assertSame('amarr', $this->prices->marketFor($this->setting(['price_source' => 'region', 'region_id' => 10000043])));
        $this->assertSame('10000060', $this->prices->marketFor($this->setting(['price_source' => 'region', 'region_id' => 10000060])));
        $this->assertSame('jita', $this->prices->marketFor($this->setting(['price_source' => 'region', 'region_id' => null])));
        $this->assertSame('jita', $this->prices->marketFor($this->setting(['price_provider' => 'janice', 'janice_market' => 'dodixie'])));
        $this->assertSame('fuzzwork', $this->prices->providerOf($this->setting(['price_provider' => 'evepraisal'])));
    }

    public function test_the_note_always_fits_its_column(): void
    {
        $many = range(1, 999);
        $note = $this->prices->noteFor([
            'provider' => 'manager-core',
            'market' => str_repeat('a-long-market-name-', 8),
            'stood_in' => false,
            'fallback' => ['provider' => 'fuzzwork', 'market' => 'jita'],
            'unanswered' => $many,
            'from_fallback' => $many,
            'from_cache' => $many,
            'from_jita' => $many,
        ]);

        // buyback_appraisals.pricing_note is a string(255).
        $this->assertLessThanOrEqual(255, mb_strlen($note));
    }
}
