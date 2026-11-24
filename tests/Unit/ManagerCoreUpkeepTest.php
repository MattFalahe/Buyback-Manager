<?php

namespace BuybackManager\Tests\Unit;

use BuybackManager\Models\BuybackPricingRule;
use BuybackManager\Models\BuybackSetting;
use BuybackManager\Models\BuybackSubscribedType;
use BuybackManager\Tests\RecordingManagerCore;
use BuybackManager\Tests\Sqlite;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;

class ManagerCoreUpkeepTest extends TestCase
{
    private RecordingManagerCore $mc;

    protected function setUp(): void
    {
        // The cache and the status table too, for the quotes priced here.
        Sqlite::migrate('000001', '000002', '000008', '000009', '000012', '000016', '000017');
        Carbon::setTestNow('2026-11-21 12:00:00');
        $this->mc = new RecordingManagerCore();

        Schema::create('invTypes', function (Blueprint $table) {
            $table->unsignedInteger('typeID')->primary();
            $table->unsignedInteger('groupID');
            $table->boolean('published');
        });
        // Veldspar's group: two published ores and one that never shipped.
        DB::table('invTypes')->insert([
            ['typeID' => 1230, 'groupID' => 462, 'published' => 1],
            ['typeID' => 17470, 'groupID' => 462, 'published' => 1],
            ['typeID' => 28432, 'groupID' => 462, 'published' => 0],
            ['typeID' => 34, 'groupID' => 18, 'published' => 1],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Sqlite::close();
    }

    private function setting(int $corporationId, array $attributes = []): BuybackSetting
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

    private function rule(BuybackSetting $setting, string $type, int $typeId, bool $excluded = false): void
    {
        BuybackPricingRule::create([
            'setting_id' => $setting->id,
            'type' => $type,
            'type_id' => $typeId,
            'percentage' => 90,
            'excluded' => $excluded,
        ]);
    }

    private function ledger(string $market, int $typeId, ?string $subscribedAt): void
    {
        DB::table('buyback_manager_subscribed_types')->insert([
            'type_id' => $typeId,
            'market' => $market,
            'subscribed_at' => $subscribedAt,
            'created_at' => '2026-11-01 00:00:00',
            'updated_at' => '2026-11-01 00:00:00',
        ]);
    }

    /** @return array<string, int[]> */
    private function ledgerByMarket(): array
    {
        $rows = [];
        foreach (BuybackSubscribedType::orderBy('market')->orderBy('type_id')->get() as $row) {
            $rows[$row->market][] = $row->type_id;
        }

        return $rows;
    }

    public function test_the_items_a_programmes_rules_name_are_subscribed_once_at_its_market(): void
    {
        $setting = $this->setting(98000001, ['manager_core_market' => 'keepstar']);
        $this->rule($setting, 'item', 34);
        $this->rule($setting, 'item', 35, excluded: true);
        $this->rule($setting, 'group', 462);
        // A category can hold thousands of items, so the quotes subscribe it.
        $this->rule($setting, 'category', 25);

        $this->assertSame(3, $this->mc->subscribeRuleItems($setting));
        $this->assertSame([['buyback-manager', [34, 1230, 17470], 'keepstar', 1, true]], $this->mc->callsTo('pricing.subscribeTypes'));
        $this->assertSame(['keepstar' => [34, 1230, 17470]], $this->ledgerByMarket());

        $this->assertSame(0, $this->mc->subscribeRuleItems($setting));
        $this->assertCount(1, $this->mc->callsTo('pricing.subscribeTypes'), 'the ledger stops a second ask');
    }

    public function test_a_programme_off_manager_core_or_switched_off_subscribes_nothing(): void
    {
        $fuzzwork = $this->setting(98000001, ['price_provider' => 'fuzzwork']);
        $off = $this->setting(98000002, ['enabled' => false]);
        $this->rule($fuzzwork, 'item', 34);
        $this->rule($off, 'item', 34);

        $this->assertSame(0, $this->mc->subscribeRuleItems($fuzzwork));
        $this->assertSame(0, $this->mc->subscribeRuleItems($off));
        $this->assertSame([], $this->mc->calls);
    }

    public function test_an_admins_market_is_where_the_rule_items_go(): void
    {
        $this->mc->answers['pricing.getPreferenceForPlugin'] = ['market' => 'amarr', 'admin_overridden' => true];
        $setting = $this->setting(98000001);
        $this->rule($setting, 'item', 34);

        $this->mc->subscribeRuleItems($setting);

        $this->assertSame(['amarr' => [34]], $this->ledgerByMarket());
    }

    public function test_the_ledger_is_sent_again_once_a_day_at_the_markets_in_use(): void
    {
        $this->setting(98000001);
        $this->ledger('jita', 34, '2026-11-20 11:00:00');   // 25 hours ago
        $this->ledger('jita', 35, null);
        $this->ledger('jita', 36, '2026-11-21 11:00:00');   // an hour ago
        $this->ledger('amarr', 37, null);                    // no programme prices there

        $this->assertSame(2, $this->mc->resendManagerCoreLedger());
        $this->assertSame([['buyback-manager', [34, 35], 'jita', 1, true]], $this->mc->callsTo('pricing.subscribeTypes'));
        $this->assertSame(
            ['2026-11-21 12:00:00', '2026-11-21 12:00:00', '2026-11-21 11:00:00'],
            BuybackSubscribedType::where('market', 'jita')->orderBy('type_id')->get()
                ->map(fn ($row) => $row->subscribed_at->format('Y-m-d H:i:s'))->all()
        );

        $this->assertSame(0, $this->mc->resendManagerCoreLedger(), 'nothing is due again the same day');
    }

    public function test_a_resend_manager_core_refused_is_due_again_on_the_next_sync(): void
    {
        $this->setting(98000001);
        $this->ledger('jita', 34, null);
        $this->mc->answers['pricing.subscribeTypes'] = new \RuntimeException('Manager Core is migrating');

        $this->assertSame(0, $this->mc->resendManagerCoreLedger());
        $this->assertNull(BuybackSubscribedType::first()->subscribed_at);

        unset($this->mc->answers['pricing.subscribeTypes']);
        $this->assertSame(1, $this->mc->resendManagerCoreLedger());
    }

    public function test_a_market_no_programme_prices_at_is_dropped_and_one_in_use_is_kept(): void
    {
        $this->setting(98000001);
        $this->setting(98000002, ['manager_core_market' => 'amarr', 'enabled' => false]);
        $this->setting(98000003, ['manager_core_market' => 'dodixie', 'price_provider' => 'fuzzwork']);
        $this->ledger('jita', 34, null);
        $this->ledger('amarr', 34, null);
        $this->ledger('dodixie', 34, null);

        $dropped = $this->mc->dropUnusedManagerCoreMarkets();
        sort($dropped);

        $this->assertSame(['amarr', 'dodixie'], $dropped);
        $this->assertEqualsCanonicalizing(
            [['buyback-manager', 'amarr'], ['buyback-manager', 'dodixie']],
            $this->mc->callsTo('pricing.unsubscribeTypes')
        );
        $this->assertSame(['jita' => [34]], $this->ledgerByMarket());
    }

    public function test_with_no_programme_on_manager_core_every_subscription_goes(): void
    {
        $this->setting(98000001, ['price_provider' => 'fuzzwork']);
        $this->ledger('jita', 34, null);

        $this->mc->dropUnusedManagerCoreMarkets();

        // Every market, including any the ledger lost track of.
        $this->assertSame([['buyback-manager', null]], $this->mc->callsTo('pricing.unsubscribeTypes'));
        $this->assertSame([], $this->ledgerByMarket());
    }

    public function test_an_admins_market_is_kept_and_the_corporations_own_is_dropped(): void
    {
        $this->mc->answers['pricing.getPreferenceForPlugin'] = ['market' => 'amarr', 'admin_overridden' => true];
        $this->setting(98000001);
        $this->ledger('jita', 34, null);
        $this->ledger('amarr', 34, null);

        $this->assertSame(['jita'], $this->mc->dropUnusedManagerCoreMarkets());
        $this->assertSame(['amarr' => [34]], $this->ledgerByMarket());
    }

    public function test_nothing_is_dropped_when_the_preference_cannot_be_read(): void
    {
        $this->mc->answers['pricing.getPreferenceForPlugin'] = new \RuntimeException('connection lost');
        $this->setting(98000001);
        $this->ledger('jita', 34, null);
        $this->ledger('amarr', 34, null);

        $this->assertSame([], $this->mc->dropUnusedManagerCoreMarkets());
        $this->assertSame([], $this->mc->callsTo('pricing.unsubscribeTypes'));
        $this->assertSame(['amarr' => [34], 'jita' => [34]], $this->ledgerByMarket());
    }

    public function test_the_sync_cycle_subscribes_the_rules_and_sends_the_ledger_again(): void
    {
        $setting = $this->setting(98000001);
        $this->rule($setting, 'item', 34);
        $this->ledger('jita', 35, '2026-11-19 12:00:00');

        $this->assertSame(['from_rules' => 1, 'resent' => 1], $this->mc->maintainManagerCoreSubscriptions());
        $this->assertSame(
            [['buyback-manager', [34], 'jita', 1, true], ['buyback-manager', [35], 'jita', 1, true]],
            $this->mc->callsTo('pricing.subscribeTypes')
        );
    }

    public function test_the_market_list_comes_from_list_markets_with_its_place_notes(): void
    {
        $this->mc->answers['pricing.listMarkets'] = [
            'keepstar' => ['key' => 'keepstar', 'name' => '1DQ Keepstar', 'market_type' => 'citadel', 'prices_own_place' => false,
                'place_reason' => 'Fuzzwork prices only the five trade hubs, so it prices this market at Jita 4-4.'],
            'jita' => ['key' => 'jita', 'name' => 'Jita', 'market_type' => 'hub', 'prices_own_place' => true, 'place_reason' => null],
            'broken' => ['name' => 'No key'],
        ];

        $this->assertSame([
            ['key' => 'keepstar', 'label' => '1DQ Keepstar', 'type' => 'citadel',
                'note' => 'Fuzzwork prices only the five trade hubs, so it prices this market at Jita 4-4.', 'providers' => []],
            ['key' => 'jita', 'label' => 'Jita', 'type' => 'hub', 'note' => null, 'providers' => []],
        ], $this->mc->managerCoreMarkets());
        $this->assertSame([[true]], $this->mc->callsTo('pricing.listMarkets'), 'enabled markets only');
        $this->assertSame('1DQ Keepstar', $this->mc->marketName('keepstar'));
    }

    // ------------------------------------------------------------
    // A provider named for a corporation's market (price variants)
    // ------------------------------------------------------------

    private const JANICE_AT_A_CITADEL = 'Janice prices only the five trade hubs and this market names none of them, so it is priced at Jita 4-4.';

    /** Manager Core's markets, listed with what each provider can do there. */
    private function withVariants(): void
    {
        $provider = fn (string $label, bool $own, bool $available = true, ?string $placeReason = null) => [
            'label' => $label, 'own' => $own, 'available' => $available, 'prices_place' => $placeReason === null, 'place_reason' => $placeReason,
        ];
        $this->mc->answers['pricing.listMarkets'] = [
            'jita' => ['key' => 'jita', 'name' => 'Jita', 'market_type' => 'hub', 'prices_own_place' => true, 'place_reason' => null,
                'providers' => [
                    'fuzzwork' => $provider('Fuzzwork', true),
                    'janice' => $provider('Janice', false),
                    'goonpraisal' => $provider('Goonpraisal', false, false),
                ]],
            'amarr' => ['key' => 'amarr', 'name' => 'Amarr', 'market_type' => 'hub', 'prices_own_place' => true, 'place_reason' => null,
                'providers' => ['fuzzwork' => $provider('Fuzzwork', true), 'janice' => $provider('Janice', false)]],
            'keepstar' => ['key' => 'keepstar', 'name' => '1DQ Keepstar', 'market_type' => 'citadel', 'prices_own_place' => true, 'place_reason' => null,
                'providers' => ['esi' => $provider('MCPraisal', true), 'janice' => $provider('Janice', false, true, self::JANICE_AT_A_CITADEL)]],
        ];
        $this->mc->answers['pricing.subscribeVariants'] = ['subscribed' => 1, 'reason' => null, 'place_reason' => null];
    }

    private function variantLedger(string $market, string $provider, int $typeId): void
    {
        DB::table('buyback_manager_subscribed_types')->insert([
            'type_id' => $typeId,
            'market' => $market,
            'provider' => $provider,
            'subscribed_at' => null,
            'created_at' => '2026-11-01 00:00:00',
            'updated_at' => '2026-11-01 00:00:00',
        ]);
    }

    /** @return array<string, int[]> keyed by market, or market@provider */
    private function ledgerBySource(): array
    {
        $rows = [];
        foreach (BuybackSubscribedType::orderBy('market')->orderBy('provider')->orderBy('type_id')->get() as $row) {
            $rows[$row->provider === '' ? $row->market : $row->market . '@' . $row->provider][] = $row->type_id;
        }

        return $rows;
    }

    private static function stats(?float $buy, ?float $sell): array
    {
        return [
            'buy' => $buy === null ? null : ['max' => $buy, 'updated_at' => '2026-11-21 11:00:00'],
            'sell' => $sell === null ? null : ['min' => $sell, 'updated_at' => '2026-11-21 11:00:00'],
        ];
    }

    public function test_a_corporation_that_names_a_provider_reads_and_keeps_its_prices_through_it(): void
    {
        $this->withVariants();
        $setting = $this->setting(98000001, ['manager_core_provider' => 'janice', 'fallback_provider' => 'none']);
        $this->mc->answers['pricing.getVariantPrices'] = [34 => self::stats(5.0, 6.0), 35 => self::stats(null, null)];

        $result = $this->mc->pricesFor($setting, [34, 35]);

        $this->assertEquals([34 => ['buy' => 5.0, 'sell' => 6.0]], $result['prices']);
        $this->assertSame('janice', $result['via']);
        $this->assertSame([[[34, 35], 'jita', 'janice', 'both']], $this->mc->callsTo('pricing.getVariantPrices'));
        $this->assertSame([], $this->mc->callsTo('pricing.getPrices'), "the market's own prices are not read");
        $this->assertSame([['buyback-manager', [34, 35], 'jita', 'janice', 1, true]], $this->mc->callsTo('pricing.subscribeVariants'));
        $this->assertSame([], $this->mc->callsTo('pricing.subscribeTypes'));
        $this->assertSame(['jita@janice' => [34, 35]], $this->ledgerBySource());
        $this->assertSame('Manager Core had no Janice price yet for 1 item. Manager Core is fetching it for the next quote.', $result['note']);
        $this->assertSame('Manager Core at Jita (Janice)', $this->mc->currentSourceLabel($setting));
    }

    public function test_an_admins_override_sets_the_named_provider_aside(): void
    {
        $this->withVariants();
        $this->mc->answers['pricing.getPreferenceForPlugin'] = ['market' => 'amarr', 'admin_overridden' => true, 'provider_override' => 'esi'];
        $this->mc->answers['pricing.getPrices'] = [34 => self::stats(5.0, 6.0)];
        $setting = $this->setting(98000001, ['manager_core_provider' => 'janice', 'fallback_provider' => 'none']);

        $this->mc->pricesFor($setting, [34]);

        // Manager Core applies the override's provider to a read under Buyback's name.
        $this->assertSame([[[34], 'amarr', 'both', 'buyback-manager']], $this->mc->callsTo('pricing.getPrices'));
        $this->assertSame([], $this->mc->callsTo('pricing.getVariantPrices'));
        $this->assertSame(['market' => 'amarr', 'provider' => '', 'overridden' => true], $this->mc->managerCoreSourceFor($setting));
    }

    public function test_the_jita_fill_reads_jitas_own_prices_since_nothing_subscribes_the_provider_there(): void
    {
        $this->withVariants();
        $setting = $this->setting(98000001, [
            'manager_core_market' => 'amarr', 'manager_core_provider' => 'janice', 'fallback_provider' => 'none', 'fallback_to_jita' => true,
        ]);
        $this->mc->answers['pricing.getVariantPrices'] = [34 => self::stats(null, 6.0)];
        $this->mc->answers['pricing.getPrices'] = [34 => self::stats(5.0, 5.5)];

        $result = $this->mc->pricesFor($setting, [34]);

        $this->assertEquals([34 => ['buy' => 5.0, 'sell' => 6.0]], $result['prices']);
        $this->assertSame([34], $result['from_jita']);
        $this->assertSame([[[34], 'amarr', 'janice', 'both']], $this->mc->callsTo('pricing.getVariantPrices'));
        $this->assertSame([[[34], 'jita', 'both', 'buyback-manager']], $this->mc->callsTo('pricing.getPrices'));
        $this->assertSame(['amarr@janice' => [34]], $this->ledgerBySource());
    }

    public function test_a_provider_manager_core_refuses_to_keep_is_asked_for_again_rather_than_ledgered(): void
    {
        $this->withVariants();
        $this->mc->answers['pricing.subscribeVariants'] = ['subscribed' => 0, 'reason' => 'cannot_price_place', 'place_reason' => self::JANICE_AT_A_CITADEL];
        $setting = $this->setting(98000001, ['manager_core_market' => 'keepstar', 'manager_core_provider' => 'janice']);
        $this->rule($setting, 'item', 34);

        $this->assertSame(0, $this->mc->subscribeRuleItems($setting));
        $this->assertSame(0, $this->mc->subscribeRuleItems($setting));

        $this->assertCount(2, $this->mc->callsTo('pricing.subscribeVariants'));
        $this->assertSame([], $this->ledgerBySource());
        $this->assertSame(self::JANICE_AT_A_CITADEL, $this->mc->managerCoreProviderProblem($setting));
    }

    public function test_what_no_programme_prices_through_any_more_is_dropped_by_market_and_provider(): void
    {
        $this->withVariants();
        $this->setting(98000001, ['manager_core_provider' => 'janice']);
        $this->setting(98000002, ['manager_core_market' => 'amarr']);
        $this->ledger('jita', 34, null);            // nobody reads Jita's own any more
        $this->variantLedger('jita', 'janice', 34);
        $this->ledger('amarr', 34, null);
        $this->variantLedger('amarr', 'janice', 34); // nobody reads Amarr through Janice

        $dropped = $this->mc->dropUnusedManagerCoreMarkets();
        sort($dropped);

        $this->assertSame(['amarr@janice', 'jita'], $dropped);
        $this->assertSame([['buyback-manager', 'jita']], $this->mc->callsTo('pricing.unsubscribeTypes'));
        $this->assertSame([['buyback-manager', 'amarr', 'janice']], $this->mc->callsTo('pricing.unsubscribeVariants'));
        $this->assertSame(['amarr' => [34], 'jita@janice' => [34]], $this->ledgerBySource());
    }

    public function test_with_no_programme_on_manager_core_the_variants_go_too(): void
    {
        $this->withVariants();
        $this->setting(98000001, ['price_provider' => 'fuzzwork']);
        $this->variantLedger('jita', 'janice', 34);

        $this->assertSame(['jita@janice'], $this->mc->dropUnusedManagerCoreMarkets());
        $this->assertSame([['buyback-manager', null]], $this->mc->callsTo('pricing.unsubscribeTypes'));
        $this->assertSame([['buyback-manager']], $this->mc->callsTo('pricing.unsubscribeVariants'));
        $this->assertSame([], $this->ledgerBySource());
    }

    public function test_the_ledger_is_sent_again_through_the_provider_it_was_kept_through(): void
    {
        $this->withVariants();
        $this->setting(98000001, ['manager_core_provider' => 'janice']);
        $this->variantLedger('jita', 'janice', 34);
        $this->ledger('jita', 35, null); // Jita's own, which nobody reads now

        $this->assertSame(1, $this->mc->resendManagerCoreLedger());
        $this->assertSame([['buyback-manager', [34], 'jita', 'janice', 1, true]], $this->mc->callsTo('pricing.subscribeVariants'));
        $this->assertSame([], $this->mc->callsTo('pricing.subscribeTypes'));

        $this->mc->answers['pricing.subscribeVariants'] = ['subscribed' => 0, 'reason' => 'error', 'place_reason' => null];
        Carbon::setTestNow('2026-11-22 13:00:00');
        $this->assertSame(0, $this->mc->resendManagerCoreLedger(), 'a refused resend is not stamped');
        $this->assertSame('2026-11-21 12:00:00', BuybackSubscribedType::where('provider', 'janice')->first()->subscribed_at->format('Y-m-d H:i:s'));
    }

    public function test_what_manager_core_can_price_each_market_through(): void
    {
        $this->withVariants();
        $markets = collect($this->mc->managerCoreMarkets())->keyBy('key');

        $this->assertSame([
            'esi' => ['label' => 'MCPraisal', 'own' => true, 'ok' => true, 'reason' => null],
            'janice' => ['label' => 'Janice', 'own' => false, 'ok' => false, 'reason' => self::JANICE_AT_A_CITADEL],
        ], $markets['keepstar']['providers']);
        $this->assertSame(['label' => 'Goonpraisal', 'own' => false, 'ok' => false, 'reason' => 'Goonpraisal is not set up in Manager Core.'],
            $markets['jita']['providers']['goonpraisal']);

        $problem = fn (?string $provider, string $market = 'jita') => $this->mc->managerCoreProviderProblem(
            new BuybackSetting(['manager_core_market' => $market, 'manager_core_provider' => $provider])
        );
        $this->assertNull($problem(null));
        $this->assertNull($problem('janice'));
        $this->assertNull($problem('Janice '), 'written as Manager Core writes it');
        $this->assertSame(self::JANICE_AT_A_CITADEL, $problem('janice', 'keepstar'));
        $this->assertSame('Goonpraisal is not set up in Manager Core.', $problem('goonpraisal'));
        $this->assertSame("Manager Core has no enabled market 'dodixie'.", $problem('janice', 'dodixie'));
        $this->assertSame("Manager Core has no provider called 'tycoon'.", $problem('tycoon'));
        $this->assertTrue($this->mc->managerCoreProviderChoice('jita', 'fuzzwork')['own']);
    }
}
