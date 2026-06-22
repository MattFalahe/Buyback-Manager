<?php

namespace BuybackManager\Tests\Unit;

use BuybackManager\Models\BuybackSetting;
use BuybackManager\Services\Pricing\PriceProviderService;
use BuybackManager\Tests\TestCase;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use ReflectionClass;

/**
 * Unit tests for the PriceProviderService surface area. Focused on the
 * dispatcher + helper logic that doesn't require a live MC install or
 * a full DB. Tests that need the cache table / subscribed-types ledger
 * (which require migrations) are noted as future work.
 */
class PriceProviderServiceTest extends TestCase
{
    private PriceProviderService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PriceProviderService();
    }

    // ============================================================
    // getAvailableProviders
    // ============================================================

    public function test_available_providers_lists_three_backends(): void
    {
        $providers = $this->service->getAvailableProviders();

        $this->assertArrayHasKey('fuzzwork', $providers);
        $this->assertArrayHasKey('janice', $providers);
        $this->assertArrayHasKey('manager-core', $providers);
    }

    public function test_fuzzwork_provider_requires_no_config(): void
    {
        $providers = $this->service->getAvailableProviders();
        $this->assertFalse($providers['fuzzwork']['requires_config']);
    }

    public function test_janice_provider_requires_api_key_config(): void
    {
        $providers = $this->service->getAvailableProviders();
        $this->assertTrue($providers['janice']['requires_config']);
        $this->assertContains('janice_api_key', $providers['janice']['config_fields']);
    }

    public function test_manager_core_provider_reports_unavailable_when_class_missing(): void
    {
        // MC is not installed in the test environment, so the availability
        // flag should be false. The provider entry still appears in the
        // listing so the UI can show it as disabled.
        $providers = $this->service->getAvailableProviders();
        $this->assertFalse($providers['manager-core']['available']);
    }

    // ============================================================
    // validateProviderConfig
    // ============================================================

    public function test_fuzzwork_validation_always_passes(): void
    {
        $setting = $this->makeSetting(['price_provider' => 'fuzzwork']);
        $this->assertTrue($this->service->validateProviderConfig('fuzzwork', $setting));
    }

    public function test_janice_validation_fails_without_api_key(): void
    {
        $setting = $this->makeSetting([
            'price_provider' => 'janice',
            'janice_api_key' => null,
        ]);
        $this->assertFalse($this->service->validateProviderConfig('janice', $setting));
    }

    public function test_janice_validation_passes_with_api_key(): void
    {
        $setting = $this->makeSetting([
            'price_provider' => 'janice',
            'janice_api_key' => 'fake-key-for-testing',
        ]);
        $this->assertTrue($this->service->validateProviderConfig('janice', $setting));
    }

    public function test_manager_core_validation_fails_when_mc_absent(): void
    {
        $setting = $this->makeSetting(['price_provider' => 'manager-core']);
        // MC is not installed in test env, so even with config present
        // validation should reject it (the real precondition is class
        // existence, not config fields).
        $this->assertFalse($this->service->validateProviderConfig('manager-core', $setting));
    }

    public function test_unknown_provider_fails_validation(): void
    {
        $setting = $this->makeSetting();
        $this->assertFalse($this->service->validateProviderConfig('not-a-real-provider', $setting));
    }

    // ============================================================
    // Pure helper methods (via reflection — they're protected)
    // ============================================================

    public function test_extract_variant_returns_min_by_default(): void
    {
        $stats = ['min' => 100.0, 'max' => 200.0, 'avg' => 150.0];
        $this->assertSame(100.0, $this->invokeProtected('extractVariant', [$stats, 'min']));
        $this->assertSame(100.0, $this->invokeProtected('extractVariant', [$stats, 'unknown-variant']));
    }

    public function test_extract_variant_selects_named_variant(): void
    {
        $stats = ['min' => 100.0, 'max' => 200.0, 'avg' => 150.0, 'median' => 175.0, 'percentile' => 120.0];
        $this->assertSame(200.0, $this->invokeProtected('extractVariant', [$stats, 'max']));
        $this->assertSame(150.0, $this->invokeProtected('extractVariant', [$stats, 'avg']));
        $this->assertSame(175.0, $this->invokeProtected('extractVariant', [$stats, 'median']));
        $this->assertSame(120.0, $this->invokeProtected('extractVariant', [$stats, 'percentile']));
    }

    public function test_is_stats_stale_handles_missing_updated_at(): void
    {
        $threshold = Carbon::now()->subHours(8);
        $this->assertFalse($this->invokeProtected('isStatsStale', [[], $threshold]));
    }

    public function test_is_stats_stale_returns_true_for_old_updated_at(): void
    {
        $threshold = Carbon::now()->subHours(8);
        $stats = ['updated_at' => Carbon::now()->subHours(12)->toIso8601String()];
        $this->assertTrue($this->invokeProtected('isStatsStale', [$stats, $threshold]));
    }

    public function test_is_stats_stale_returns_false_for_fresh_updated_at(): void
    {
        $threshold = Carbon::now()->subHours(8);
        $stats = ['updated_at' => Carbon::now()->subMinutes(30)->toIso8601String()];
        $this->assertFalse($this->invokeProtected('isStatsStale', [$stats, $threshold]));
    }

    public function test_normalise_bridge_shape_passes_through_typeid_keyed(): void
    {
        $input = [34 => ['min' => 5.0], 35 => ['min' => 10.0]];
        $result = $this->invokeProtected('normaliseBridgeShape', [$input, [34, 35]]);
        $this->assertSame($input, $result);
    }

    public function test_normalise_bridge_shape_rewraps_single_element_collapse(): void
    {
        // MC's single-element collapse: when only one typeId is requested,
        // the bridge returns the inner stats shape directly. The helper
        // re-wraps it as [typeId => shape] for uniform downstream handling.
        $collapsedInput = ['min' => 5.0, 'max' => 10.0];
        $result = $this->invokeProtected('normaliseBridgeShape', [$collapsedInput, [34]]);
        $this->assertSame([34 => $collapsedInput], $result);
    }

    public function test_normalise_bridge_shape_returns_empty_on_unknown_shape(): void
    {
        $unknownShape = ['weird' => 'data', 'not-typeid-keys' => 'either'];
        $result = $this->invokeProtected('normaliseBridgeShape', [$unknownShape, [34, 35]]);
        $this->assertSame([], $result);
    }

    public function test_resolve_janice_market_param_maps_to_janice_codes(): void
    {
        $jitaSetting = $this->makeSetting(['janice_market' => 'jita']);
        $amarrSetting = $this->makeSetting(['janice_market' => 'amarr']);
        $unsetSetting = $this->makeSetting();

        $this->assertSame('2', $this->invokeProtected('resolveJaniceMarketParam', [$jitaSetting]));
        $this->assertSame('1', $this->invokeProtected('resolveJaniceMarketParam', [$amarrSetting]));
        $this->assertSame('2', $this->invokeProtected('resolveJaniceMarketParam', [$unsetSetting]));
    }

    public function test_resolve_side_preference_defaults_to_sell(): void
    {
        $setting = $this->makeSetting(['price_provider' => 'fuzzwork']);
        $this->assertSame('sell', $this->invokeProtected('resolveSidePreference', [$setting]));
    }

    public function test_resolve_side_preference_honors_janice_method(): void
    {
        $buySetting = $this->makeSetting([
            'price_provider' => 'janice',
            'janice_price_method' => 'buy',
        ]);
        $sellSetting = $this->makeSetting([
            'price_provider' => 'janice',
            'janice_price_method' => 'sell',
        ]);
        $splitSetting = $this->makeSetting([
            'price_provider' => 'janice',
            'janice_price_method' => 'split',
        ]);

        $this->assertSame('buy', $this->invokeProtected('resolveSidePreference', [$buySetting]));
        $this->assertSame('sell', $this->invokeProtected('resolveSidePreference', [$sellSetting]));
        // 'split' maps to 'average' for the underlying fetch.
        $this->assertSame('average', $this->invokeProtected('resolveSidePreference', [$splitSetting]));
    }

    // ============================================================
    // testProvider — end-to-end smoke against a single type
    // ============================================================

    public function test_test_provider_returns_false_when_fuzzwork_unreachable(): void
    {
        Http::fake([
            'market.fuzzwork.co.uk/*' => Http::response('', 500),
        ]);

        $setting = $this->makeSetting(['price_provider' => 'fuzzwork']);
        $this->assertFalse($this->service->testProvider('fuzzwork', $setting));
    }

    public function test_test_provider_returns_false_for_janice_without_api_key(): void
    {
        $setting = $this->makeSetting([
            'price_provider' => 'janice',
            'janice_api_key' => null,
        ]);
        // Without API key, getPricesFromJanice throws, getPrices catches
        // and routes through local cache (empty in tests) — testProvider
        // sees zero price and returns false.
        $this->assertFalse($this->service->testProvider('janice', $setting));
    }

    public function test_last_fallback_summary_is_null_before_any_call(): void
    {
        $service = new PriceProviderService();
        $this->assertNull($service->getLastFallbackSummary());
        $this->assertSame([], $service->getLastJitaFallbackTypeIds());
    }

    // ============================================================
    // helpers
    // ============================================================

    private function makeSetting(array $attrs = []): BuybackSetting
    {
        $setting = new BuybackSetting(array_merge([
            'corporation_id' => 1234,
            'enabled' => true,
            'base_percentage' => 90,
            'price_source' => 'jita',
            'price_provider' => 'fuzzwork',
            'fallback_to_jita' => true,
        ], $attrs));
        return $setting;
    }

    private function invokeProtected(string $method, array $args = [])
    {
        $reflection = new ReflectionClass($this->service);
        $m = $reflection->getMethod($method);
        $m->setAccessible(true);
        return $m->invokeArgs($this->service, $args);
    }
}
