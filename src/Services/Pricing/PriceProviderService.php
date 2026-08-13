<?php

namespace BuybackManager\Services\Pricing;

use BuybackManager\Integrations\ManagerCoreIntegration;
use BuybackManager\Models\BuybackPriceCache;
use BuybackManager\Models\BuybackSetting;
use BuybackManager\Models\BuybackSubscribedType;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-provider pricing service for Buyback Manager.
 *
 * Three providers share a uniform input shape (typeIds + BuybackSetting)
 * and output shape ([typeId => float price]):
 *
 *   - Fuzzwork: free public market aggregator (default). Region-based.
 *   - Janice: appraisal service, requires API key. Hub markets (jita/amarr).
 *     Also exposes a raw-text appraisal endpoint for the manual paste UI
 *     which handles fitted ships, EFT fits, BPCs, and contract pastes.
 *   - Manager Core: shared market price cache via PluginBridge. Supports
 *     regional + citadel markets when the operator has added them in MC.
 *
 * Three layers of resilience under each provider:
 *   1. Configured-market fetch (whatever provider + market the corp picked).
 *   2. Jita fallback: retry zero-price items at Jita when the primary
 *      market isn't Jita and fallback_to_jita is enabled.
 *   3. Local cache fallback (buyback_price_cache table) when the upstream
 *      provider throws — last resort so a contract sync doesn't crater
 *      on a network blip.
 *
 * Manager Core path also does lazy subscribe-on-encounter: the first time
 * BB asks MC about a typeId, we register it via pricing.subscribeTypes so
 * MC's scheduled cron keeps it warm thereafter. Tracked in the local
 * buyback_subscribed_types ledger to avoid duplicate bridge calls.
 *
 * Returns [typeId => float] keyed map. Zero indicates no price available
 * (caller decides whether to skip, log, or substitute).
 */
class PriceProviderService
{
    public const PROVIDER_FUZZWORK = 'fuzzwork';
    public const PROVIDER_JANICE = 'janice';
    public const PROVIDER_MANAGER_CORE = 'manager-core';

    public const DEFAULT_REGION_ID = 10000002; // The Forge (Jita)

    private const FUZZWORK_URL = 'https://market.fuzzwork.co.uk/aggregates/';
    private const JANICE_PRICER_URL = 'https://janice.e-351.com/api/rest/v2/pricer';
    private const JANICE_APPRAISAL_URL = 'https://janice.e-351.com/api/rest/v2/appraisal';

    private const HTTP_TIMEOUT = 15;

    /**
     * Transient upstream blips (a dropped connection, a brief 5xx) should not
     * zero a whole batch of prices, so each request gets one retry after a
     * short pause before it counts as failed.
     */
    private const HTTP_RETRIES = 2;
    private const HTTP_RETRY_DELAY_MS = 300;
    private const BATCH_SIZE = 100;

    /**
     * Default TTL for the primary cache layer when a corp setting has
     * no explicit price_cache_ttl_minutes value (e.g. legacy rows
     * created before migration 000016). 60 minutes mirrors Mining
     * Manager's default for the same cache. Applies to Fuzzwork and
     * Janice providers only — MC always bypasses BB's cache.
     */
    public const DEFAULT_CACHE_TTL_MINUTES = 60;
    private const JANICE_RATE_LIMIT_MICROSECONDS = 50000; // 50ms between requests

    /**
     * MC staleness threshold. MC's update-prices cron runs every 4 hours;
     * anything older than 2x that interval (8h) almost certainly means
     * the cron is broken or paused. Stale prices are still returned, but
     * we log a warning so operators can spot it.
     */
    public const MC_PRICE_STALENESS_HOURS = 8;

    /**
     * Type IDs that used Jita fallback in the last getPrices() call.
     */
    protected array $lastJitaFallbackTypeIds = [];

    /**
     * Last fallback dispatch summary (null when no fallback fired).
     */
    protected ?array $lastFallbackSummary = null;

    /**
     * True when the most recent price fetch could not reach its provider and
     * fell through to cached or zero prices. Callers use this to refuse to
     * quote rather than publish a valuation built on a failed fetch.
     */
    protected bool $lastFetchDegraded = false;

    /**
     * Whether the most recent fetch ran degraded (provider unreachable).
     */
    public function wasLastFetchDegraded(): bool
    {
        return $this->lastFetchDegraded;
    }

    // ============================================================
    // PUBLIC API
    // ============================================================

    /**
     * Fetch prices for a list of type IDs against the corp's configured
     * provider. Returns [typeId => price] keyed map; zero means no price
     * available.
     *
     * @param int[] $typeIds
     * @return array<int, float>
     */
    public function getPrices(array $typeIds, BuybackSetting $setting): array
    {
        $typeIds = array_values(array_unique(array_filter(array_map('intval', $typeIds))));
        if (empty($typeIds)) {
            return [];
        }

        // Delegate to the both-sides path so cache writes always carry
        // distinct buy/sell/avg columns (the previous single-side cache
        // wrote the same scalar to all three columns, losing fidelity
        // for any future read on the opposite side). Then reduce to the
        // setting's configured side.
        $bothSides = $this->getPricesBothSides($typeIds, $setting);
        $side = $this->resolveSidePreference($setting);

        $out = [];
        foreach ($typeIds as $tid) {
            $sides = $bothSides[$tid] ?? ['buy' => 0, 'sell' => 0];
            $buy = (float) ($sides['buy'] ?? 0);
            $sell = (float) ($sides['sell'] ?? 0);
            $out[$tid] = match ($side) {
                'buy' => $buy,
                'split' => ($buy > 0 && $sell > 0) ? ($buy + $sell) / 2.0 : max($buy, $sell),
                default => $sell,
            };
        }
        return $out;
    }

    public function getPrice(int $typeId, BuybackSetting $setting): ?float
    {
        $prices = $this->getPrices([$typeId], $setting);
        return $prices[$typeId] ?? null;
    }

    /**
     * Fetch BOTH sides of the market spread for the given type IDs.
     * Returns a uniform shape so callers (e.g. AppraisalService) can
     * pick the side per-rule based on each rule's price_side override:
     *
     *   [typeId => ['buy' => float, 'sell' => float]]
     *
     * Required for the per-rule price-side feature (rule columns:
     * price_side = buy|sell|split). The 'split' option is computed at
     * pick time as (buy + sell) / 2 — both sides are needed in cache.
     *
     * On provider exception, falls back to the local price cache the
     * same way getPrices() does. Empty input returns an empty array.
     */
    public function getPricesBothSides(array $typeIds, BuybackSetting $setting): array
    {
        $typeIds = array_values(array_unique(array_filter(array_map('intval', $typeIds))));
        if (empty($typeIds)) {
            return [];
        }

        $provider = $setting->price_provider ?? self::PROVIDER_FUZZWORK;
        $this->lastFetchDegraded = false;

        // Manager Core has its own cache layer (manager_core_market_prices)
        // refreshed by a 4h cron. Layering BB's cache on top of MC would
        // only delay propagation of MC's refresh cycle without saving any
        // HTTP calls (the read goes through the bridge to MC's table either
        // way). Skip BB's cache for MC and rely on MC's cache directly.
        if ($provider === self::PROVIDER_MANAGER_CORE) {
            return $this->fetchAndCacheBothSides($typeIds, $setting, $provider);
        }

        // Fuzzwork / Janice: primary cache with per-corp TTL.
        $regionId = $this->resolveRegionId($setting);
        $ttlMinutes = (int) ($setting->price_cache_ttl_minutes ?? self::DEFAULT_CACHE_TTL_MINUTES);

        $cached = $this->readFromCacheIfFresh($typeIds, $regionId, $ttlMinutes);
        $stale = array_values(array_diff($typeIds, array_keys($cached)));

        // All cache hits — return without touching the network.
        if (empty($stale)) {
            Log::info('[Buyback Manager] Price cache hit (' . count($cached) . '/' . count($typeIds) . ')', [
                'provider' => $provider,
                'corporation_id' => $setting->corporation_id,
                'ttl_minutes' => $ttlMinutes,
            ]);
            return $this->fillMissingPairWithZero($cached, $typeIds);
        }

        Log::info('[Buyback Manager] Fetching prices', [
            'provider' => $provider,
            'corporation_id' => $setting->corporation_id,
            'cached' => count($cached),
            'fetching' => count($stale),
            'ttl_minutes' => $ttlMinutes,
        ]);

        try {
            $fetched = $this->fetchAndCacheBothSides($stale, $setting, $provider);
            // Merge cached (fresh) + fetched (just refreshed). The fetched
            // map already contains the requested $stale typeIds with their
            // new values; merging is straightforward.
            $merged = array_replace($cached, $fetched);
            return $this->fillMissingPairWithZero($merged, $typeIds);
        } catch (\Throwable $e) {
            // The provider could not be reached. Fall back to cached prices
            // at ANY age: a price from a few hours ago beats quoting zero.
            // The degraded flag travels with it so the appraisal layer can
            // refuse to issue a quote built on a failed fetch.
            $this->lastFetchDegraded = true;

            $lastResort = $this->readFromCacheAnyAge($stale, $regionId);
            $recovered = array_replace($cached, $lastResort);

            Log::warning('[Buyback Manager] Upstream fetch failed; serving cached prices where possible', [
                'provider' => $provider,
                'error' => $e->getMessage(),
                'requested' => count($typeIds),
                'fresh_cache' => count($cached),
                'stale_cache_used' => count($lastResort),
                'unpriced' => count($typeIds) - count($recovered),
            ]);

            return $this->fillMissingPairWithZero($recovered, $typeIds);
        }
    }

    /**
     * Fetch from upstream, apply Jita fallback, write-through to cache,
     * and return [typeId => ['buy', 'sell']]. Shared between the cache-
     * miss path (Fuzzwork/Janice) and the cache-bypass path (MC).
     */
    protected function fetchAndCacheBothSides(array $typeIds, BuybackSetting $setting, string $provider): array
    {
        $prices = match ($provider) {
            self::PROVIDER_JANICE => $this->janiceFetchBoth($typeIds, $setting),
            self::PROVIDER_MANAGER_CORE => $this->mcFetchBoth($typeIds, $setting),
            default => $this->fuzzworkFetchBoth($typeIds, $this->resolveRegionId($setting)),
        };

        $prices = $this->applyJitaFallbackBoth($provider, $prices, $typeIds, $setting);
        $this->writeCacheBoth($prices, $this->resolveRegionId($setting));

        return $prices;
    }

    /**
     * Read cached prices that are within the freshness window. Missing
     * or stale entries are absent from the result (caller treats them
     * as cache misses and fetches them upstream).
     *
     * TTL ≤ 0 disables the cache entirely (always-miss, always live).
     *
     * @return array<int, array{buy: float, sell: float}>
     */
    protected function readFromCacheIfFresh(array $typeIds, int $regionId, int $ttlMinutes): array
    {
        if ($ttlMinutes <= 0) {
            return [];
        }

        $cutoff = Carbon::now()->subMinutes($ttlMinutes);

        $rows = BuybackPriceCache::where('region_id', $regionId)
            ->whereIn('type_id', $typeIds)
            ->where('cached_at', '>=', $cutoff)
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->type_id] = [
                'buy' => (float) $row->buy_price,
                'sell' => (float) $row->sell_price,
            ];
        }
        return $out;
    }

    /**
     * Last-resort cache read that ignores the TTL.
     *
     * Only used when the provider could not be reached at all. A price from
     * some hours ago is a far better answer than zero, and the caller marks
     * the fetch as degraded so the valuation can be refused or flagged
     * rather than silently trusted.
     */
    protected function readFromCacheAnyAge(array $typeIds, int $regionId): array
    {
        $rows = BuybackPriceCache::where('region_id', $regionId)
            ->whereIn('type_id', $typeIds)
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->type_id] = [
                'buy' => (float) $row->buy_price,
                'sell' => (float) $row->sell_price,
            ];
        }
        return $out;
    }

    /**
     * Write a both-sides price map to the cache. Skips zero-value rows
     * (a fetch that returned ['buy' => 0, 'sell' => 0] usually means the
     * type isn't traded; caching that would prevent any future fetch
     * from ever recovering when the item starts trading again).
     */
    protected function writeCacheBoth(array $prices, int $regionId): void
    {
        $now = Carbon::now();
        foreach ($prices as $tid => $sides) {
            $buy = (float) ($sides['buy'] ?? 0);
            $sell = (float) ($sides['sell'] ?? 0);
            $avg = ($buy + $sell) / 2;
            if ($avg <= 0) {
                continue;
            }
            try {
                BuybackPriceCache::updateOrCreate(
                    ['type_id' => (int) $tid, 'region_id' => $regionId],
                    [
                        'sell_price' => $sell,
                        'buy_price' => $buy,
                        'average_price' => $avg,
                        'cached_at' => $now,
                    ]
                );
            } catch (\Throwable $e) {
                // Best-effort. Cache failures never block the price read.
            }
        }
    }

    /**
     * Fill missing typeIds in a both-sides map with zero pairs.
     */
    protected function fillMissingPairWithZero(array $prices, array $typeIds): array
    {
        foreach ($typeIds as $tid) {
            if (! isset($prices[$tid])) {
                $prices[$tid] = ['buy' => 0.0, 'sell' => 0.0];
            }
        }
        return $prices;
    }

    /**
     * Raw-text appraisal via Janice's appraisal endpoint. Hands Janice the
     * full paste; Janice parses + prices it in one round trip.
     *
     * Used by AppraisalService::createAppraisal when the corp's provider
     * is set to Janice. Handles formats StandaloneParserService can't:
     * fitted ships, EFT fits, BPCs, contract pastes with cargo, killmails.
     *
     * Returns:
     *   ['success' => true, 'items' => [stdClass{type_id, type_name, ...}, ...]]
     * or:
     *   ['success' => false, 'message' => string]
     */
    public function appraiseRawViaJanice(string $rawText, BuybackSetting $setting): array
    {
        $apiKey = $setting->janice_api_key ?? null;
        if (empty($apiKey)) {
            return ['success' => false, 'message' => 'Janice API key not configured for this corporation'];
        }

        $market = $this->resolveJaniceMarketParam($setting);
        $url = self::JANICE_APPRAISAL_URL
            . '?market=' . $market
            . '&designation=appraisal'
            . '&pricing=split'
            . '&pricingVariant=split'
            . '&persist=true'
            . '&compactize=true'
            . '&pricePercentage=1';

        try {
            $response = Http::timeout(self::HTTP_TIMEOUT)
                ->withHeaders([
                    'X-ApiKey' => $apiKey,
                    'Content-Type' => 'text/plain',
                    'accept' => 'application/json',
                ])
                ->withBody($rawText, 'text/plain')
                ->post($url);

            if (!$response->successful()) {
                Log::warning('[Buyback Manager] Janice appraisal failed', [
                    'status' => $response->status(),
                    'body' => substr($response->body(), 0, 500),
                ]);
                return ['success' => false, 'message' => 'Janice appraisal failed (HTTP ' . $response->status() . ')'];
            }

            $data = $response->json();
            if (!is_array($data) || empty($data['items'])) {
                return ['success' => false, 'message' => 'Janice returned no items — check your paste format'];
            }

            $items = [];
            foreach ($data['items'] as $row) {
                $typeId = (int) ($row['itemType']['eid'] ?? $row['typeID'] ?? 0);
                if (!$typeId) {
                    continue;
                }

                $imm = $row['immediatePrices'] ?? [];

                // Expose BOTH sides on each item so applyBuybackRules
                // can pick per the rule's price_side. The previous
                // "chosen single price" reduction is now deferred to
                // the rules layer.
                $sellPrice = (float) ($imm['sellPrice'] ?? 0);
                $buyPrice = (float) ($imm['buyPrice'] ?? 0);

                $qty = (int) ($row['amount'] ?? 1);
                $items[] = (object) [
                    'type_id' => $typeId,
                    'type_name' => (string) ($row['itemType']['name'] ?? 'Unknown'),
                    'quantity' => $qty,
                    'sell_price' => $sellPrice,
                    'buy_price' => $buyPrice,
                    'group_id' => ((int) ($row['itemType']['groupId'] ?? 0)) ?: null,
                    'category_id' => ((int) ($row['itemType']['categoryId'] ?? 0)) ?: null,
                    'total_volume' => (float) ($row['itemType']['volume'] ?? 0) * $qty,
                ];
            }

            if (empty($items)) {
                return ['success' => false, 'message' => 'Janice could not resolve any items from input'];
            }

            return ['success' => true, 'items' => $items];
        } catch (Exception $e) {
            Log::error('[Buyback Manager] Janice appraisal exception: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Janice request error: ' . $e->getMessage()];
        }
    }

    /**
     * Subscribe a list of typeIds to Manager Core's pricing cache.
     * Idempotent via the local buyback_subscribed_types ledger — only
     * never-before-seen typeIds trigger an actual bridge call.
     *
     * @param int[] $typeIds
     * @return int  Count of newly-subscribed types (0 when all known).
     */
    public function subscribeToManagerCore(array $typeIds, string $market = 'jita', bool $immediateRefresh = false): int
    {
        if (!ManagerCoreIntegration::isAvailable()) {
            return 0;
        }

        $newTypeIds = BuybackSubscribedType::missingFrom($typeIds, $market);
        if (empty($newTypeIds)) {
            return 0;
        }

        try {
            $bridge = ManagerCoreIntegration::bridge();
            $bridge->call(
                'ManagerCore',
                'pricing.subscribeTypes',
                'buyback-manager',
                $newTypeIds,
                $market,
                1,
                $immediateRefresh
            );
            BuybackSubscribedType::markSubscribed($newTypeIds, $market);

            Log::info('[Buyback Manager] Subscribed ' . count($newTypeIds) . " new types to MC market '{$market}'");
            return count($newTypeIds);
        } catch (Exception $e) {
            Log::warning('[Buyback Manager] MC subscribe failed: ' . $e->getMessage());
            return 0;
        }
    }

    public function unsubscribeFromManagerCore(): int
    {
        if (!ManagerCoreIntegration::isAvailable()) {
            return 0;
        }

        try {
            $bridge = ManagerCoreIntegration::bridge();
            $count = $bridge->call('ManagerCore', 'pricing.unsubscribeTypes', 'buyback-manager', null);
            BuybackSubscribedType::query()->delete();
            Log::info('[Buyback Manager] Unsubscribed all types from Manager Core (count=' . (int) $count . ')');
            return (int) $count;
        } catch (Exception $e) {
            Log::warning('[Buyback Manager] MC unsubscribe failed: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Live-test a provider's configuration by fetching Tritanium (typeID 34).
     *
     * Clones the input setting so the caller's BuybackSetting instance is
     * never mutated. Previously this method temporarily flipped the
     * caller's setting and relied on a finally-block to restore it, which
     * is correct under normal control flow but fragile if a future
     * refactor moves error handling around the try-finally boundary.
     */
    public function testProvider(string $provider, BuybackSetting $setting): bool
    {
        $testSetting = clone $setting;
        $testSetting->price_provider = $provider;

        try {
            $price = $this->getPrice(34, $testSetting);
            return $price !== null && $price > 0;
        } catch (Exception $e) {
            Log::warning('[Buyback Manager] Provider test failed', [
                'provider' => $provider,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function getAvailableProviders(): array
    {
        return [
            self::PROVIDER_FUZZWORK => [
                'name' => 'Fuzzwork',
                'description' => 'Community market aggregator (free, region-based, no API key)',
                'requires_config' => false,
                'available' => true,
            ],
            self::PROVIDER_JANICE => [
                'name' => 'Janice',
                'description' => 'Janice appraisal service. Handles fitted ships and rich paste formats (requires API key)',
                'requires_config' => true,
                'config_fields' => ['janice_api_key'],
                'available' => true,
            ],
            self::PROVIDER_MANAGER_CORE => [
                'name' => 'Manager Core',
                'description' => 'Shared MC price cache. Required for citadel and regional markets',
                'requires_config' => false,
                'available' => ManagerCoreIntegration::isAvailable(),
            ],
        ];
    }

    public function validateProviderConfig(string $provider, BuybackSetting $setting): bool
    {
        $providers = $this->getAvailableProviders();
        if (!isset($providers[$provider])) {
            return false;
        }

        // MC's real precondition is class existence, not a config field.
        if ($provider === self::PROVIDER_MANAGER_CORE) {
            return ManagerCoreIntegration::isAvailable();
        }

        $cfg = $providers[$provider];
        if (empty($cfg['requires_config'])) {
            return true;
        }

        foreach ($cfg['config_fields'] ?? [] as $field) {
            if (empty($setting->{$field})) {
                return false;
            }
        }

        return true;
    }

    public function getLastFallbackSummary(): ?array
    {
        return $this->lastFallbackSummary;
    }

    public function getLastJitaFallbackTypeIds(): array
    {
        return $this->lastJitaFallbackTypeIds;
    }

    // ============================================================
    // FUZZWORK PROVIDER
    // ============================================================

    protected function getPricesFromFuzzwork(array $typeIds, BuybackSetting $setting): array
    {
        $regionId = $this->resolveRegionId($setting);
        $side = $this->resolveSidePreference($setting);
        return $this->fuzzworkFetch($typeIds, $regionId, $side);
    }

    /**
     * Direct Fuzzwork fetch with explicit region. Used by both the primary
     * Fuzzwork path and the Jita-fallback layer (region=DEFAULT_REGION_ID).
     */
    protected function fuzzworkFetch(array $typeIds, int $regionId, string $side): array
    {
        $prices = [];
        foreach (array_chunk($typeIds, self::BATCH_SIZE) as $chunk) {
            try {
                $response = Http::timeout(self::HTTP_TIMEOUT)
                    ->acceptJson()
                    ->get(self::FUZZWORK_URL, [
                        'region' => $regionId,
                        'types' => implode(',', $chunk),
                    ]);

                if (!$response->successful()) {
                    Log::warning('[Buyback Manager] Fuzzwork batch failed', [
                        'status' => $response->status(),
                        'region' => $regionId,
                    ]);
                    foreach ($chunk as $tid) {
                        $prices[$tid] = 0;
                    }
                    continue;
                }

                $data = $response->json() ?: [];
                foreach ($chunk as $tid) {
                    $row = $data[$tid] ?? null;
                    $prices[$tid] = $this->fuzzworkExtract(is_array($row) ? $row : null, $side);
                }
            } catch (Exception $e) {
                Log::error('[Buyback Manager] Fuzzwork exception: ' . $e->getMessage());
                foreach ($chunk as $tid) {
                    $prices[$tid] = 0;
                }
            }
        }
        return $prices;
    }

    protected function fuzzworkExtract(?array $row, string $side): float
    {
        if (!$row) {
            return 0;
        }
        switch ($side) {
            case 'buy':
                return (float) ($row['buy']['max'] ?? 0);
            case 'sell':
                return (float) ($row['sell']['min'] ?? 0);
            case 'split':
            default:
                $buy = (float) ($row['buy']['max'] ?? 0);
                $sell = (float) ($row['sell']['min'] ?? 0);
                return ($buy + $sell) / 2;
        }
    }

    // ============================================================
    // JANICE PROVIDER (pricer endpoint, used for structured/contract sync)
    // ============================================================

    protected function getPricesFromJanice(array $typeIds, BuybackSetting $setting): array
    {
        $apiKey = $setting->janice_api_key ?? null;
        if (empty($apiKey)) {
            throw new Exception('Janice API key not configured for corporation ' . $setting->corporation_id);
        }

        return $this->janiceFetch(
            $typeIds,
            $this->resolveJaniceMarketParam($setting),
            $apiKey,
            $setting->janice_price_method ?? 'buy'
        );
    }

    protected function janiceFetch(array $typeIds, string $marketParam, string $apiKey, string $method): array
    {
        $prices = [];
        foreach ($typeIds as $typeId) {
            try {
                $url = sprintf('%s/%d?market=%s', self::JANICE_PRICER_URL, $typeId, $marketParam);
                $response = Http::timeout(self::HTTP_TIMEOUT)
                    ->withHeaders([
                        'X-ApiKey' => $apiKey,
                        'accept' => 'application/json',
                    ])
                    ->get($url);

                if (!$response->successful()) {
                    Log::warning('[Buyback Manager] Janice pricer failed', [
                        'type_id' => $typeId,
                        'status' => $response->status(),
                    ]);
                    $prices[$typeId] = 0;
                    usleep(self::JANICE_RATE_LIMIT_MICROSECONDS);
                    continue;
                }

                $data = $response->json();
                $imm = $data['immediatePrices'] ?? null;
                $eff = $data['effectivePrices'] ?? null;
                $prices[$typeId] = match ($method) {
                    'sell' => (float) ($imm['sellPrice'] ?? 0),
                    'split' => (float) ($eff['splitPrice'] ?? 0),
                    default => (float) ($imm['buyPrice'] ?? 0),
                };

                usleep(self::JANICE_RATE_LIMIT_MICROSECONDS);
            } catch (Exception $e) {
                Log::error('[Buyback Manager] Janice fetch exception', [
                    'type_id' => $typeId,
                    'error' => $e->getMessage(),
                ]);
                $prices[$typeId] = 0;
            }
        }
        return $prices;
    }

    // ============================================================
    // MANAGER CORE PROVIDER
    // ============================================================

    protected function getPricesFromManagerCore(array $typeIds, BuybackSetting $setting): array
    {
        if (!ManagerCoreIntegration::isAvailable()) {
            throw new Exception('Manager Core is not installed');
        }

        $market = $setting->manager_core_market ?? 'jita';
        $variant = $setting->manager_core_variant ?? 'min';
        $side = $this->resolveSidePreference($setting);

        // Lazy subscribe-on-encounter: first-time types get subscribed so
        // MC's scheduled cron keeps them warm thereafter. immediateRefresh
        // is false because (a) this is the hot path during contract sync
        // where queue dispatches per-type would flood, (b) MC's scheduled
        // pricing cron (every 4h) will populate the new types organically,
        // (c) Jita fallback handles the cold-start gap. Matches Mining
        // Manager's boot-time-subscribe pattern.
        $this->subscribeToManagerCore($typeIds, $market, false);

        try {
            $bridge = ManagerCoreIntegration::bridge();
            $rawResult = $bridge->call(
                'ManagerCore',
                'pricing.getPrices',
                $typeIds,
                $market,
                $side === 'split' ? 'both' : $side
            );
        } catch (Exception $e) {
            Log::warning('[Buyback Manager] MC pricing.getPrices threw: ' . $e->getMessage());
            return array_fill_keys($typeIds, 0.0);
        }

        if (!is_array($rawResult)) {
            return array_fill_keys($typeIds, 0.0);
        }

        $resultByType = $this->normaliseBridgeShape($rawResult, $typeIds);
        $prices = [];
        $stalenessThreshold = Carbon::now()->subHours(self::MC_PRICE_STALENESS_HOURS);
        $staleCount = 0;

        foreach ($typeIds as $tid) {
            $entry = $resultByType[$tid] ?? null;
            if ($entry === null) {
                $prices[$tid] = 0;
                continue;
            }

            $hasBuySell = is_array($entry) && (array_key_exists('buy', $entry) || array_key_exists('sell', $entry));

            if ($side === 'split') {
                $sellStats = $hasBuySell ? ($entry['sell'] ?? null) : null;
                $buyStats = $hasBuySell ? ($entry['buy'] ?? null) : null;
                $sellVal = $sellStats ? $this->extractVariant($sellStats, $variant) : 0;
                $buyVal = $buyStats ? $this->extractVariant($buyStats, $variant) : 0;

                if ($sellStats && $buyStats) {
                    $prices[$tid] = ($sellVal + $buyVal) / 2;
                } elseif ($sellStats) {
                    $prices[$tid] = $sellVal;
                } elseif ($buyStats) {
                    $prices[$tid] = $buyVal;
                } else {
                    $prices[$tid] = 0;
                }

                $usedStats = $sellStats ?? $buyStats;
                if ($usedStats && $this->isStatsStale($usedStats, $stalenessThreshold)) {
                    $staleCount++;
                }
            } else {
                $stats = $hasBuySell ? ($entry[$side] ?? null) : $entry;
                $prices[$tid] = $stats ? $this->extractVariant($stats, $variant) : 0;
                if ($stats && $this->isStatsStale($stats, $stalenessThreshold)) {
                    $staleCount++;
                }
            }
        }

        if ($staleCount > 0) {
            Log::warning("[Buyback Manager] {$staleCount}/" . count($typeIds) . ' MC prices older than '
                . self::MC_PRICE_STALENESS_HOURS . 'h. Check manager-core:update-prices cron.');
        }

        return $prices;
    }

    protected function extractVariant(array $stats, string $variant): float
    {
        return match ($variant) {
            'max' => (float) ($stats['max'] ?? 0),
            'avg' => (float) ($stats['avg'] ?? 0),
            'median' => (float) ($stats['median'] ?? 0),
            'percentile' => (float) ($stats['percentile'] ?? 0),
            default => (float) ($stats['min'] ?? 0),
        };
    }

    protected function isStatsStale(array $stats, Carbon $threshold): bool
    {
        $updatedAt = $stats['updated_at'] ?? null;
        if ($updatedAt === null) {
            return false;
        }
        try {
            $carbon = $updatedAt instanceof Carbon ? $updatedAt : Carbon::parse((string) $updatedAt);
        } catch (Exception $e) {
            return false;
        }
        return $carbon->lt($threshold);
    }

    /**
     * Insulate against MC's getPrice single-element-collapse quirk: if the
     * bridge returned the inner stats shape instead of [typeId => stats],
     * re-wrap. See PricingService::getPrice docblock for the history.
     */
    protected function normaliseBridgeShape($result, array $typeIds): array
    {
        if (!is_array($result)) {
            return [];
        }

        $typeIdSet = array_flip($typeIds);
        $keys = array_keys($result);
        $allKeysAreTypeIds = !empty($keys) && array_reduce(
            $keys,
            fn($carry, $k) => $carry && isset($typeIdSet[$k]),
            true
        );

        if ($allKeysAreTypeIds) {
            return $result;
        }

        if (count($typeIds) === 1) {
            return [$typeIds[0] => $result];
        }

        return [];
    }

    // ============================================================
    // JITA FALLBACK
    // ============================================================

    protected function applyJitaFallback(string $provider, array $prices, array $typeIds, BuybackSetting $setting): array
    {
        if (!($setting->fallback_to_jita ?? true)) {
            return $prices;
        }

        $currentMarket = match ($provider) {
            self::PROVIDER_JANICE => $this->resolveJaniceMarketParam($setting) === '2' ? 'jita' : 'amarr',
            self::PROVIDER_MANAGER_CORE => $setting->manager_core_market ?? 'jita',
            default => $this->resolveRegionId($setting) === self::DEFAULT_REGION_ID ? 'jita' : 'other',
        };

        if ($currentMarket === 'jita') {
            return $prices;
        }

        $zeroTypeIds = array_keys(array_filter($prices, fn($p) => $p <= 0));
        $zeroCount = count($zeroTypeIds);
        if ($zeroCount === 0) {
            return $prices;
        }

        Log::info('[Buyback Manager] Jita fallback dispatched', [
            'provider' => $provider,
            'configured_market' => $currentMarket,
            'zero_count' => $zeroCount,
            'sample_zero_type_ids' => array_slice($zeroTypeIds, 0, 10),
        ]);

        $side = $this->resolveSidePreference($setting);
        $recovered = 0;
        $fallbackError = null;
        $jitaPrices = [];

        try {
            $jitaPrices = match ($provider) {
                self::PROVIDER_JANICE => $this->janiceFetch(
                    $zeroTypeIds,
                    '2',
                    $setting->janice_api_key,
                    $setting->janice_price_method ?? 'buy'
                ),
                self::PROVIDER_MANAGER_CORE => $this->mcFetchAtMarket($zeroTypeIds, 'jita', $setting),
                default => $this->fuzzworkFetch($zeroTypeIds, self::DEFAULT_REGION_ID, $side),
            };
        } catch (Exception $e) {
            $fallbackError = $e->getMessage();
        }

        foreach ($jitaPrices as $tid => $p) {
            if ($p > 0 && ($prices[$tid] ?? 0) <= 0) {
                $prices[$tid] = $p;
                $this->lastJitaFallbackTypeIds[] = $tid;
                $recovered++;
            }
        }

        $unrecovered = $zeroCount - $recovered;
        $recoveryPct = $zeroCount > 0 ? round($recovered / $zeroCount * 100, 1) : 0;

        $this->lastFallbackSummary = [
            'provider' => $provider,
            'configured_market' => $currentMarket,
            'requested_count' => count($typeIds),
            'zero_count' => $zeroCount,
            'fallback_recovered_count' => $recovered,
            'fallback_unrecovered_count' => $unrecovered,
            'recovery_pct' => $recoveryPct,
            'fallback_error' => $fallbackError,
            'timestamp' => Carbon::now()->toIso8601String(),
        ];

        if ($fallbackError !== null) {
            Log::warning('[Buyback Manager] Jita fallback request failed', $this->lastFallbackSummary);
        } elseif ($recovered > 0 && $recoveryPct < 50) {
            Log::warning('[Buyback Manager] Jita fallback recovered <50% of missing prices', $this->lastFallbackSummary);
        } elseif ($recovered > 0) {
            Log::info('[Buyback Manager] Jita fallback completed', $this->lastFallbackSummary);
        }

        return $prices;
    }

    protected function mcFetchAtMarket(array $typeIds, string $market, BuybackSetting $setting): array
    {
        $variant = $setting->manager_core_variant ?? 'min';
        $side = $this->resolveSidePreference($setting);
        $bridgeSide = $side === 'split' ? 'sell' : $side;

        try {
            $bridge = ManagerCoreIntegration::bridge();
            $rawResult = $bridge->call(
                'ManagerCore',
                'pricing.getPrices',
                $typeIds,
                $market,
                $bridgeSide
            );
        } catch (Exception $e) {
            return array_fill_keys($typeIds, 0.0);
        }

        if (!is_array($rawResult)) {
            return array_fill_keys($typeIds, 0.0);
        }

        $resultByType = $this->normaliseBridgeShape($rawResult, $typeIds);
        $prices = [];
        foreach ($typeIds as $tid) {
            $entry = $resultByType[$tid] ?? null;
            if ($entry === null) {
                $prices[$tid] = 0;
                continue;
            }
            $hasBuySell = is_array($entry) && (array_key_exists('buy', $entry) || array_key_exists('sell', $entry));
            $stats = $hasBuySell ? ($entry[$bridgeSide] ?? null) : $entry;
            $prices[$tid] = $stats ? $this->extractVariant($stats, $variant) : 0;
        }
        return $prices;
    }

    // ============================================================
    // BOTH-SIDES FETCH (per-rule price_side support)
    // ============================================================

    /**
     * Fuzzwork batch fetch that returns BOTH sides per type. The single-
     * side fuzzworkFetch retains its existing API for callers that only
     * need one side; this variant feeds the per-rule price_side feature.
     */
    protected function fuzzworkFetchBoth(array $typeIds, int $regionId): array
    {
        $prices = [];
        $batches = 0;
        $failed = 0;
        $succeeded = 0;
        $lastError = '';

        foreach (array_chunk($typeIds, self::BATCH_SIZE) as $chunk) {
            // Fail fast. Once two batches have failed with nothing getting
            // through, the provider is down, and grinding through the rest
            // (each with its own retries and timeout) would stall the request
            // for minutes before reaching the same conclusion.
            if ($failed >= 2 && $succeeded === 0) {
                throw new PriceFetchException('Fuzzwork unreachable, abandoning remaining batches: ' . $lastError);
            }

            $batches++;
            try {
                $response = Http::timeout(self::HTTP_TIMEOUT)
                    ->retry(self::HTTP_RETRIES, self::HTTP_RETRY_DELAY_MS, throw: false)
                    ->acceptJson()
                    ->get(self::FUZZWORK_URL, [
                        'region' => $regionId,
                        'types' => implode(',', $chunk),
                    ]);

                if (! $response->successful()) {
                    $failed++;
                    $lastError = 'HTTP ' . $response->status();
                    Log::warning('[Buyback Manager] Fuzzwork (both) batch failed', [
                        'status' => $response->status(),
                        'region' => $regionId,
                        'types' => count($chunk),
                    ]);
                    foreach ($chunk as $tid) {
                        $prices[$tid] = ['buy' => 0.0, 'sell' => 0.0];
                    }
                    continue;
                }

                $succeeded++;
                $data = $response->json() ?: [];
                foreach ($chunk as $tid) {
                    $row = is_array($data[$tid] ?? null) ? $data[$tid] : null;
                    $prices[$tid] = [
                        'buy' => (float) ($row['buy']['max'] ?? 0),
                        'sell' => (float) ($row['sell']['min'] ?? 0),
                    ];
                }
            } catch (PriceFetchException $e) {
                throw $e;
            } catch (\Throwable $e) {
                $failed++;
                $lastError = $e->getMessage();
                Log::error('[Buyback Manager] Fuzzwork (both) exception: ' . $e->getMessage());
                foreach ($chunk as $tid) {
                    $prices[$tid] = ['buy' => 0.0, 'sell' => 0.0];
                }
            }
        }

        // Nothing got through: the provider is unreachable, not "these items
        // are worthless". Throw so the caller can fall back to cache or
        // refuse to quote instead of publishing a page of zeroes.
        if ($batches > 0 && $failed === $batches) {
            throw new PriceFetchException('Fuzzwork unreachable for all ' . $batches . ' batch(es): ' . $lastError);
        }

        return $prices;
    }

    /**
     * Janice per-type fetch that returns BOTH sides. immediatePrices
     * carries both buyPrice + sellPrice in the same response, so this
     * just preserves both instead of reducing.
     */
    protected function janiceFetchBoth(array $typeIds, BuybackSetting $setting): array
    {
        $apiKey = $setting->janice_api_key ?? null;
        if (empty($apiKey)) {
            throw new Exception('Janice API key not configured for corporation ' . $setting->corporation_id);
        }
        return $this->janiceFetchBothRaw(
            $typeIds,
            $this->resolveJaniceMarketParam($setting),
            $apiKey
        );
    }

    /**
     * Janice both-sides fetch with explicit market + key — used by the
     * primary path AND the Jita-fallback path (which needs to override
     * the market to '2' regardless of the corp's configured market).
     */
    protected function janiceFetchBothRaw(array $typeIds, string $marketParam, string $apiKey): array
    {
        $prices = [];
        $attempted = 0;
        $failed = 0;
        $succeeded = 0;
        $lastError = '';

        foreach ($typeIds as $typeId) {
            // Janice is queried one type at a time, so a dead endpoint would
            // otherwise burn a timeout per item. Give up after three straight
            // failures with nothing succeeding.
            if ($failed >= 3 && $succeeded === 0) {
                throw new PriceFetchException('Janice unreachable, abandoning remaining lookups: ' . $lastError);
            }

            $attempted++;
            try {
                $url = sprintf('%s/%d?market=%s', self::JANICE_PRICER_URL, $typeId, $marketParam);
                $response = Http::timeout(self::HTTP_TIMEOUT)
                    ->retry(self::HTTP_RETRIES, self::HTTP_RETRY_DELAY_MS, throw: false)
                    ->withHeaders([
                        'X-ApiKey' => $apiKey,
                        'accept' => 'application/json',
                    ])
                    ->get($url);

                // A rejected key is a configuration fault, not a missing
                // price. Fail immediately and say so, rather than quietly
                // pricing the whole list at zero.
                if (in_array($response->status(), [401, 403], true)) {
                    throw new PriceFetchException(
                        'Janice rejected the API key (HTTP ' . $response->status() . '). Check the key in the corporation settings.'
                    );
                }

                if (! $response->successful()) {
                    $failed++;
                    $lastError = 'HTTP ' . $response->status();
                    Log::warning('[Buyback Manager] Janice (both) failed for type ' . $typeId . ': ' . $lastError);
                    $prices[$typeId] = ['buy' => 0.0, 'sell' => 0.0];
                    usleep(self::JANICE_RATE_LIMIT_MICROSECONDS);
                    continue;
                }

                $succeeded++;
                $data = $response->json();
                $imm = $data['immediatePrices'] ?? [];
                $prices[$typeId] = [
                    'buy' => (float) ($imm['buyPrice'] ?? 0),
                    'sell' => (float) ($imm['sellPrice'] ?? 0),
                ];
                usleep(self::JANICE_RATE_LIMIT_MICROSECONDS);
            } catch (PriceFetchException $e) {
                throw $e;
            } catch (\Throwable $e) {
                $failed++;
                $lastError = $e->getMessage();
                Log::error('[Buyback Manager] Janice (both) fetch exception for ' . $typeId . ': ' . $e->getMessage());
                $prices[$typeId] = ['buy' => 0.0, 'sell' => 0.0];
            }
        }

        // Every single lookup failed: treat it as the provider being down.
        if ($attempted > 0 && $failed === $attempted) {
            throw new PriceFetchException('Janice unreachable for all ' . $attempted . ' lookup(s): ' . $lastError);
        }

        return $prices;
    }

    /**
     * Manager Core both-sides fetch. Uses priceType='both' on the
     * pricing.getPrices bridge capability so we get buy + sell stats
     * in one round-trip. Lazy subscribe-on-encounter still fires.
     */
    protected function mcFetchBoth(array $typeIds, BuybackSetting $setting): array
    {
        if (! ManagerCoreIntegration::isAvailable()) {
            throw new Exception('Manager Core is not installed');
        }

        $market = $this->resolveMcMarket($setting);
        // immediateRefresh=false matches the single-side path above —
        // avoid queue-flooding RefreshMarketPricesJob during contract
        // sync. MC's 4h cron + Jita fallback cover the cold-start gap.
        $this->subscribeToManagerCore($typeIds, $market, false);

        return $this->mcFetchBothAtMarket(
            $typeIds,
            $market,
            $setting->manager_core_variant ?? 'min'
        );
    }

    /**
     * Resolve the effective MC market for this corp.
     *
     * Precedence:
     *   1. An ADMIN override in MC's Pricing Preferences UI
     *      (manager_core_pricing_preferences.admin_overridden = true).
     *      This is the cross-plugin admin override the operator asked
     *      for: set the market once in MC and it wins over BB's setting.
     *   2. BB's own manager_core_market setting.
     *   3. 'jita'.
     *
     * Read directly from MC's table (guarded by Schema::hasTable),
     * consistent with how loadManagerCoreMarkets reads
     * manager_core_markets. We only honour the row when admin_overridden
     * is true — a plugin-default registration (admin_overridden=false)
     * mirrors BB's own setting, so deferring to it would be a no-op at
     * best and stale at worst.
     */
    protected function resolveMcMarket(BuybackSetting $setting): string
    {
        $market = $setting->manager_core_market ?: 'jita';

        try {
            if (Schema::hasTable('manager_core_pricing_preferences')) {
                $pref = DB::table('manager_core_pricing_preferences')
                    ->where('plugin_key', 'buyback-manager')
                    ->first();
                if ($pref && (int) ($pref->admin_overridden ?? 0) === 1 && ! empty($pref->market)) {
                    return (string) $pref->market;
                }
            }
        } catch (\Throwable $e) {
            // Fall through to BB's own setting.
        }

        return $market;
    }

    /**
     * MC both-sides fetch with explicit market + variant — shared
     * between the primary path and the Jita-fallback path.
     */
    protected function mcFetchBothAtMarket(array $typeIds, string $market, string $variant): array
    {
        try {
            $bridge = ManagerCoreIntegration::bridge();
            $rawResult = $bridge->call(
                'ManagerCore',
                'pricing.getPrices',
                $typeIds,
                $market,
                'both'
            );
        } catch (Exception $e) {
            Log::warning('[Buyback Manager] MC mcFetchBoth threw: ' . $e->getMessage());
            return array_fill_keys($typeIds, ['buy' => 0.0, 'sell' => 0.0]);
        }

        if (! is_array($rawResult)) {
            return array_fill_keys($typeIds, ['buy' => 0.0, 'sell' => 0.0]);
        }

        $resultByType = $this->normaliseBridgeShape($rawResult, $typeIds);
        $prices = [];
        foreach ($typeIds as $tid) {
            $entry = $resultByType[$tid] ?? null;
            if ($entry === null) {
                $prices[$tid] = ['buy' => 0.0, 'sell' => 0.0];
                continue;
            }

            $hasBuySell = is_array($entry) && (array_key_exists('buy', $entry) || array_key_exists('sell', $entry));
            if (! $hasBuySell) {
                $prices[$tid] = ['buy' => 0.0, 'sell' => 0.0];
                continue;
            }

            $buyStats = $entry['buy'] ?? null;
            $sellStats = $entry['sell'] ?? null;
            $prices[$tid] = [
                'buy' => $buyStats ? $this->extractVariant($buyStats, $variant) : 0.0,
                'sell' => $sellStats ? $this->extractVariant($sellStats, $variant) : 0.0,
            ];
        }
        return $prices;
    }

    /**
     * Jita fallback for the both-sides path. Retries Jita for any type
     * where BOTH sides came back zero from the configured market.
     */
    protected function applyJitaFallbackBoth(string $provider, array $prices, array $typeIds, BuybackSetting $setting): array
    {
        if (! ($setting->fallback_to_jita ?? true)) {
            return $prices;
        }

        $currentMarket = match ($provider) {
            self::PROVIDER_JANICE => $this->resolveJaniceMarketParam($setting) === '2' ? 'jita' : 'amarr',
            self::PROVIDER_MANAGER_CORE => $setting->manager_core_market ?? 'jita',
            default => $this->resolveRegionId($setting) === self::DEFAULT_REGION_ID ? 'jita' : 'other',
        };
        if ($currentMarket === 'jita') {
            return $prices;
        }

        $zeroTypeIds = [];
        foreach ($typeIds as $tid) {
            $entry = $prices[$tid] ?? ['buy' => 0, 'sell' => 0];
            if (($entry['buy'] ?? 0) <= 0 && ($entry['sell'] ?? 0) <= 0) {
                $zeroTypeIds[] = $tid;
            }
        }
        if (empty($zeroTypeIds)) {
            return $prices;
        }

        $jitaPrices = [];
        try {
            $jitaPrices = match ($provider) {
                self::PROVIDER_JANICE => $this->janiceFetchBothRaw($zeroTypeIds, '2', $setting->janice_api_key),
                self::PROVIDER_MANAGER_CORE => $this->mcFetchBothAtMarket($zeroTypeIds, 'jita', $setting->manager_core_variant ?? 'min'),
                default => $this->fuzzworkFetchBoth($zeroTypeIds, self::DEFAULT_REGION_ID),
            };
        } catch (Exception $e) {
            Log::warning('[Buyback Manager] Jita fallback (both sides) failed: ' . $e->getMessage());
        }

        $recovered = 0;
        foreach ($jitaPrices as $tid => $sides) {
            if (($sides['buy'] ?? 0) > 0 || ($sides['sell'] ?? 0) > 0) {
                $prices[$tid] = $sides;
                $recovered++;
            }
        }

        // Record every fallback, not just the ones that error. A quiet
        // fallback still means the configured market returned nothing, which
        // is exactly what an operator needs to see in the SeAT log before it
        // becomes a pricing complaint.
        $summary = [
            'provider' => $provider,
            'market' => $currentMarket,
            'corporation_id' => $setting->corporation_id,
            'missing_at_configured_market' => count($zeroTypeIds),
            'recovered_at_jita' => $recovered,
        ];

        if ($recovered === 0) {
            Log::warning('[Buyback Manager] Jita fallback (both sides) recovered nothing', $summary);
        } elseif ($recovered < count($zeroTypeIds)) {
            Log::warning('[Buyback Manager] Jita fallback (both sides) recovered only some prices', $summary);
        } else {
            Log::info('[Buyback Manager] Jita fallback (both sides) recovered all missing prices', $summary);
        }

        return $prices;
    }

    // ============================================================
    // LOCAL CACHE
    // ============================================================

    /**
     * Local-cache fallback for the both-sides path. Called by
     * getPricesBothSides when the upstream provider throws. Returns
     * the shape [typeId => ['buy' => float, 'sell' => float]] using
     * whichever of the cache's buy_price / sell_price columns has
     * data. Missing rows become zero pairs.
     *
     * The legacy single-side cachePrices/getPricesFromLocalCache pair
     * was removed in the v1.0.0 audit because the single-side write
     * stored the same scalar in all three columns (buy_price,
     * sell_price, average_price), corrupting the side-distinction
     * the cache table was meant to preserve. All cache writes now
     * happen inside getPricesBothSides which has access to both
     * sides authoritatively.
     */
    protected function getPricesFromLocalCacheBothSides(array $typeIds, BuybackSetting $setting): array
    {
        $regionId = $this->resolveRegionId($setting);
        $rows = BuybackPriceCache::where('region_id', $regionId)
            ->whereIn('type_id', $typeIds)
            ->get()
            ->keyBy('type_id');

        $out = [];
        foreach ($typeIds as $tid) {
            $row = $rows->get($tid);
            $out[$tid] = $row ? [
                'buy' => (float) $row->buy_price,
                'sell' => (float) $row->sell_price,
            ] : ['buy' => 0.0, 'sell' => 0.0];
        }
        return $out;
    }

    // ============================================================
    // HELPERS
    // ============================================================

    protected function resolveRegionId(BuybackSetting $setting): int
    {
        if ($setting->price_source === 'region' && $setting->region_id) {
            return (int) $setting->region_id;
        }
        return self::DEFAULT_REGION_ID;
    }

    /**
     * Translate the corp's chosen Janice market to Janice's URL parameter:
     *   '2' = Jita (default), '1' = Amarr.
     */
    protected function resolveJaniceMarketParam(BuybackSetting $setting): string
    {
        $market = $setting->janice_market ?? 'jita';
        return $market === 'amarr' ? '1' : '2';
    }

    /**
     * Side preference is derived from janice_price_method when provider is
     * Janice; otherwise defaults to 'sell' (cheapest sell-side is the right
     * conservative valuation for a buyback program: what the operator
     * could rebuy the item for in the open market).
     *
     * Returns one of: 'buy', 'sell', 'split'. AppraisalService::
     * resolveDefaultSide uses the same vocabulary so per-rule price_side
     * overrides and setting-wide defaults compose cleanly.
     */
    protected function resolveSidePreference(BuybackSetting $setting): string
    {
        if ($setting->price_provider === self::PROVIDER_JANICE) {
            return $setting->janice_price_method ?? 'buy';
        }
        return 'sell';
    }

    protected function fillMissingWithZero(array $prices, array $typeIds): array
    {
        foreach ($typeIds as $tid) {
            if (!isset($prices[$tid])) {
                $prices[$tid] = 0;
            }
        }
        return $prices;
    }
}
