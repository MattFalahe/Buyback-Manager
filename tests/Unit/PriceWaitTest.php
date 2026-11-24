<?php

namespace BuybackManager\Tests\Unit;

use BuybackManager\Models\BuybackSetting;
use BuybackManager\Services\Pricing\PriceWait;
use BuybackManager\Tests\KnownTypes;
use BuybackManager\Tests\RecordingManagerCore;
use BuybackManager\Tests\Sqlite;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;

/**
 * The few seconds a quote waits for Manager Core to fetch what it had no
 * price for, against the real ledger and a recorded Manager Core.
 */
class PriceWaitTest extends TestCase
{
    private RecordingManagerCore $mc;

    private PriceWait $wait;

    protected function setUp(): void
    {
        Sqlite::migrate('000001', '000002', '000008', '000009', '000012', '000016', '000017');
        Carbon::setTestNow('2026-11-21 12:00:00');
        $this->mc = new RecordingManagerCore();
        $this->wait = new PriceWait($this->mc, new KnownTypes());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Sqlite::close();
    }

    private function setting(int $corporationId = 98000001, array $attributes = []): BuybackSetting
    {
        return BuybackSetting::create($attributes + [
            'corporation_id' => $corporationId,
            'enabled' => true,
            'price_provider' => 'manager-core',
            'fallback_provider' => 'fuzzwork',
            'manager_core_market' => 'jita',
            'target_type' => BuybackSetting::TARGET_MY_CORP,
        ]);
    }

    /**
     * Manager Core's stored prices: fresh ones, and ones from before the last
     * refresh ran. Like Manager Core, it answers only for the items asked.
     */
    private function managerCoreHas(array $fresh, array $old = []): void
    {
        $stats = fn (string $at) => ['buy' => ['max' => 5.0, 'updated_at' => $at], 'sell' => ['min' => 6.0, 'updated_at' => $at]];
        $stored = array_fill_keys($fresh, $stats('2026-11-21 11:59:00')) + array_fill_keys($old, $stats('2026-11-21 02:00:00'));

        $this->mc->answers['pricing.getPrices'] = fn (array $typeIds) => array_intersect_key($stored, array_flip($typeIds));
    }

    private function ledger(int $typeId, string $subscribedAt): void
    {
        DB::table('buyback_manager_subscribed_types')->insert([
            'type_id' => $typeId, 'market' => 'jita', 'provider' => '',
            'subscribed_at' => $subscribedAt, 'created_at' => $subscribedAt, 'updated_at' => $subscribedAt,
        ]);
    }

    public function test_a_quote_waits_only_on_what_manager_core_has_no_price_for(): void
    {
        $this->managerCoreHas([34], [35]);

        $started = $this->wait->startFor($this->setting(), [34, 35, 36, 37]);

        $this->assertSame(2, $started['waiting']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{32}$/', $started['token']);
        $this->assertSame([PriceWait::WAIT_SECONDS, PriceWait::POLL_SECONDS], [$started['seconds'], $started['poll']]);
        // An old price is the fallback's to replace: Manager Core fetches it
        // again only on its next refresh, so nothing is waited on for it.
        $this->assertSame([['buyback-manager', [36, 37], 'jita', 1, true]], $this->mc->callsTo('pricing.subscribeTypes'));
    }

    public function test_the_quote_goes_ahead_once_every_price_is_in(): void
    {
        $setting = $this->setting();
        $this->managerCoreHas([34]);
        $token = $this->wait->startFor($setting, [34, 36, 37])['token'];

        Carbon::setTestNow('2026-11-21 12:00:02');
        $this->assertSame(['waiting' => 2, 'done' => false], $this->wait->check($setting, $token));

        $this->managerCoreHas([34, 36, 37]);
        Carbon::setTestNow('2026-11-21 12:00:04');
        $this->assertSame(PriceWait::GO_AHEAD, $this->wait->check($setting, $token));

        // The wait is over, so a late check lets the quote go too.
        $this->assertSame(PriceWait::GO_AHEAD, $this->wait->check($setting, $token));
    }

    public function test_once_some_prices_are_in_a_check_that_brings_no_more_lets_the_quote_go(): void
    {
        $setting = $this->setting();
        $this->managerCoreHas([]);
        $token = $this->wait->startFor($setting, [36, 37])['token'];

        // One item has no orders, so Manager Core stores nothing for it.
        $this->managerCoreHas([36]);
        Carbon::setTestNow('2026-11-21 12:00:02');
        $this->assertSame(['waiting' => 1, 'done' => false], $this->wait->check($setting, $token));

        Carbon::setTestNow('2026-11-21 12:00:04');
        $this->assertSame(PriceWait::GO_AHEAD, $this->wait->check($setting, $token));
    }

    public function test_the_wait_ends_at_its_limit(): void
    {
        $setting = $this->setting();
        $this->managerCoreHas([]);
        $token = $this->wait->startFor($setting, [36])['token'];

        Carbon::setTestNow('2026-11-21 12:00:18');
        $this->assertFalse($this->wait->check($setting, $token)['done']);

        Carbon::setTestNow('2026-11-21 12:00:20');
        $this->assertTrue($this->wait->check($setting, $token)['done']);
    }

    public function test_an_item_asked_for_long_ago_is_not_waited_on_and_one_asked_for_moments_ago_is(): void
    {
        $this->managerCoreHas([]);
        $this->ledger(36, '2026-11-21 11:50:00');
        $this->ledger(37, '2026-11-21 11:59:30');

        $started = $this->wait->startFor($this->setting(), [36, 37]);

        $this->assertSame(1, $started['waiting']);
        $this->assertSame([], $this->mc->callsTo('pricing.subscribeTypes'), 'both are in the ledger already');
    }

    public function test_nothing_is_waited_on_when_manager_core_cannot_be_asked(): void
    {
        $this->managerCoreHas([]);
        $this->mc->answers['pricing.subscribeTypes'] = new \RuntimeException('Manager Core is busy');
        $this->assertSame(0, $this->wait->startFor($this->setting(), [36])['waiting'], 'the subscription failed');

        $this->mc->answers['pricing.getPrices'] = new \RuntimeException('Manager Core is busy');
        $this->assertSame($this->wait->nothingToWaitFor(), $this->wait->startFor($this->setting(98000002), [36]), 'its prices cannot be read');
    }

    public function test_a_programme_off_manager_core_or_switched_off_waits_on_nothing(): void
    {
        $this->managerCoreHas([]);

        $this->assertSame(0, $this->wait->startFor($this->setting(98000001, ['price_provider' => 'fuzzwork']), [36])['waiting']);
        $this->assertSame(0, $this->wait->start($this->setting(98000002, ['enabled' => false]), 'Tritanium 5')['waiting']);
        $this->assertSame([], $this->mc->callsTo('pricing.subscribeTypes'));
    }

    public function test_a_paste_is_read_for_its_items_before_they_are_asked_for(): void
    {
        $this->managerCoreHas([1001]);

        $started = $this->wait->start($this->setting(), "Tritanium 5\nPyerite 10\nNonsense line");

        $this->assertSame(1, $started['waiting']);
        $this->assertSame([['buyback-manager', [1002], 'jita', 1, true]], $this->mc->callsTo('pricing.subscribeTypes'));
    }

    public function test_a_wait_is_only_checked_for_the_corporation_that_started_it(): void
    {
        $setting = $this->setting();
        $this->managerCoreHas([]);
        $token = $this->wait->startFor($setting, [36])['token'];

        $this->assertSame(PriceWait::GO_AHEAD, $this->wait->check($this->setting(98000002), $token));
        $this->assertSame(PriceWait::GO_AHEAD, $this->wait->check($setting, str_repeat('a', 32)));
        $this->assertFalse($this->wait->check($setting, $token)['done']);
    }
}
