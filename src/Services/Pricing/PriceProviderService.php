<?php

namespace BuybackManager\Services\Pricing;

use BuybackManager\Integrations\ManagerCoreIntegration;
use BuybackManager\Models\BuybackPriceCache;
use BuybackManager\Models\BuybackPriceStatus;
use BuybackManager\Models\BuybackSetting;
use BuybackManager\Models\BuybackSubscribedType;
use BuybackManager\Services\WebhookDispatcher;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Prices for a quote, both sides of the spread for every item, through the
 * same chain Mining Manager uses:
 *
 *   1. The corporation's provider at its market. Fuzzwork and Janice answers
 *      are reused for the setting's cache TTL. Manager Core is a cache of its
 *      own and is read every time, so a price it has just fetched shows up on
 *      the next quote.
 *   2. When the provider fails outright, the fallback provider prices the same
 *      market, or Jita when it cannot price there. Items the provider could
 *      not answer for, because a request failed or Manager Core has not
 *      fetched them yet, go to the fallback provider too.
 *   3. Whatever is still missing keeps the last price this install saw.
 *
 * With "fall back to Jita" on and a market other than Jita, a side with no
 * orders at the market takes Jita's price. A side nothing could price stays
 * null and the quote reports the item as unpriced instead of valuing it at
 * zero.
 */
class PriceProviderService
{
    public const PROVIDER_FUZZWORK = 'fuzzwork';
    public const PROVIDER_JANICE = 'janice';
    public const PROVIDER_MANAGER_CORE = 'manager-core';

    /** What each provider is called on a page or in an alert. */
    public const PROVIDER_LABELS = [
        self::PROVIDER_FUZZWORK => 'Fuzzwork',
        self::PROVIDER_JANICE => 'Janice',
        self::PROVIDER_MANAGER_CORE => 'Manager Core',
    ];

    /** The fallback setting's value for no fallback provider. */
    public const FALLBACK_NONE = 'none';

    /**
     * The trade hubs the providers have in common, with the region Fuzzwork
     * prices each one in.
     */
    public const HUB_REGIONS = [
        'jita' => 10000002,
        'amarr' => 10000043,
        'dodixie' => 10000032,
        'hek' => 10000042,
        'rens' => 10000030,
    ];

    /** Janice's market id for each hub it prices. */
    public const JANICE_MARKETS = [
        'jita' => 2,
        'amarr' => 1,
    ];

    public const DEFAULT_CACHE_TTL_MINUTES = 60;

    /**
     * Manager Core refreshes every 4 hours by default, so a price older than
     * twice that means its refresh has stopped. It is still used, and logged.
     */
    public const MC_PRICE_STALENESS_HOURS = 8;

    /**
     * How many of the asked items must already have a cached price before an
     * answer with no prices at all counts as the provider being down, rather
     * than a list of items nobody trades.
     */
    public const PROVIDER_DOWN_MIN_IDS = 5;

    private const FUZZWORK_URL = 'https://market.fuzzwork.co.uk/aggregates/';
    private const FUZZWORK_BATCH_SIZE = 200;
    private const FUZZWORK_TIMEOUT_SECONDS = 10;

    /**
     * One request per batch of ids, with a pause between batches: Janice's
     * owner blocks keys for excessive traffic. Shorter than Mining Manager's
     * pause because a quote is waited on in the browser, and there is no
     * staged retry for the same reason. A failed batch goes to the fallback.
     */
    private const JANICE_PRICER_URL = 'https://janice.e-351.com/api/rest/v2/pricer';
    private const JANICE_BATCH_SIZE = 100;
    private const JANICE_BATCH_PAUSE_US = 1000000;
    private const JANICE_TIMEOUT_SECONDS = 20;

    /**
     * Two failed requests with nothing answered means the provider is down.
     * Asking the rest, each on its own timeout, would hold the quote up for
     * minutes to reach the same answer.
     */
    private const FAIL_FAST_AFTER = 2;

    private const MC_PLUGIN_KEY = 'buyback-manager';

    /** How long Manager Core's preference for Buyback is trusted once read. */
    private const MC_PREFERENCE_SECONDS = 60;

    protected ?array $mcPreference = null;

    protected ?int $mcPreferenceReadAt = null;

    /** @var array<string, string> */
    protected array $marketNames = [];

    // ============================================================
    // THE CHAIN
    // ============================================================

    /**
     * Both sides' prices for these items, for one corporation's quote.
     *
     * @param  int[]  $typeIds
     * @return array{
     *     prices: array<int, array{buy: ?float, sell: ?float}>,
     *     provider: string,
     *     market: string,
     *     stood_in: bool,
     *     fallback: ?array,
     *     from_fallback: int[],
     *     from_cache: int[],
     *     from_jita: int[],
     *     unanswered: int[],
     *     note: ?string
     * }
     */
    public function pricesFor(BuybackSetting $setting, array $typeIds): array
    {
        $typeIds = $this->cleanIds($typeIds);
        $provider = $this->providerOf($setting);
        $market = $this->marketFor($setting, $provider);

        $result = [
            'prices' => [],
            'provider' => $provider,
            'market' => $market,
            'stood_in' => false,
            'fallback' => null,
            'from_fallback' => [],
            'from_cache' => [],
            'from_jita' => [],
            'unanswered' => [],
            'note' => null,
        ];

        if (empty($typeIds)) {
            return $result;
        }

        $fresh = $provider === self::PROVIDER_MANAGER_CORE
            ? []
            : $this->freshCachedPrices($provider, $market, $typeIds, $this->ttlMinutes($setting));
        $toAsk = array_values(array_diff($typeIds, array_keys($fresh)));
        $prices = array_filter($fresh);

        if (! empty($toAsk)) {
            try {
                $answer = $this->fetchFrom($provider, $market, $toAsk, $setting);
            } catch (\Throwable $e) {
                Log::warning('[Buyback Manager] ' . $this->providerLabel($provider) . ' failed: ' . $e->getMessage(), [
                    'corporation_id' => $setting->corporation_id,
                ]);

                return $this->whileProviderIsDown($setting, $provider, $market, $toAsk, $prices, $e->getMessage(), $result);
            }

            $priced = count($answer['prices']);

            if ($priced === 0 && $answer['errors'] > 0) {
                return $this->whileProviderIsDown($setting, $provider, $market, $toAsk, $prices, $answer['error'] ?? 'The provider did not answer', $result);
            }

            if ($priced === 0 && $this->countPricedInCache($provider, $market, $toAsk) >= self::PROVIDER_DOWN_MIN_IDS) {
                return $this->whileProviderIsDown($setting, $provider, $market, $toAsk, $prices, 'The provider answered, but with no prices at all', $result);
            }

            if ($priced > 0) {
                $this->recordOutcome($setting, $provider, $market, true);
            }

            $this->rememberPrices($provider, $market, $answer);
            $prices += $answer['prices'];

            if (! empty($answer['unanswered'])) {
                $result['unanswered'] = $answer['unanswered'];
                $fallback = $this->fallbackFor($setting, $provider, $market);
                $result['fallback'] = $fallback;

                $fromFallback = [];
                if ($fallback['provider'] !== null) {
                    [$fromFallback] = $this->askFallback($setting, $fallback, $answer['unanswered']);
                }
                $result['from_fallback'] = array_keys($fromFallback);
                $prices += $fromFallback;

                // A failed request leaves the cache as the last word. An item
                // Manager Core has never priced has nothing cached anyway.
                $kept = $this->lastCachedPrices($provider, $market, array_diff($answer['unanswered'], array_keys($fromFallback)));
                $result['from_cache'] = array_keys($kept);
                $prices += $kept;
            }
        }

        if ($provider === self::PROVIDER_MANAGER_CORE) {
            $this->subscribeToManagerCore($typeIds, $market, true);
        }

        [$prices, $result['from_jita']] = $this->fillFromJita($setting, $provider, $market, $prices);

        $result['prices'] = $prices;
        $result['note'] = $this->describe($result);

        return $result;
    }

    /**
     * The provider has failed outright. Ask the fallback provider for the
     * same market, keep the cached price of anything it cannot price either,
     * and record the failure so the alert fires once.
     */
    protected function whileProviderIsDown(BuybackSetting $setting, string $provider, string $market, array $toAsk, array $prices, string $reason, array $result): array
    {
        $result['stood_in'] = true;

        $fallback = $this->fallbackFor($setting, $provider, $market);
        $fromFallback = [];

        if ($fallback['provider'] !== null) {
            [$fromFallback, $down, $fallback['error']] = $this->askFallback($setting, $fallback, $toAsk);
            $fallback['ok'] = ! $down;
        }

        $result['fallback'] = $fallback;
        $result['from_fallback'] = array_keys($fromFallback);
        $prices += $fromFallback;

        $this->recordOutcome($setting, $provider, $market, false, $reason, $fallback);

        $kept = $this->lastCachedPrices($provider, $market, array_diff($toAsk, array_keys($fromFallback)));
        $result['from_cache'] = array_keys($kept);
        $prices += $kept;

        $result['prices'] = $prices;
        $result['note'] = $this->describe($result);

        return $result;
    }

    /**
     * The fallback's prices for these items, whether it is down itself, and
     * what it said if it is. Judged the way the provider is: no price for an
     * item nobody trades is not a fault, no price for anything is.
     *
     * @return array{0: array<int, array{buy: ?float, sell: ?float}>, 1: bool, 2: ?string}
     */
    protected function askFallback(BuybackSetting $setting, array $fallback, array $typeIds): array
    {
        $typeIds = $this->cleanIds($typeIds);
        if (empty($typeIds)) {
            return [[], false, null];
        }

        try {
            $answer = $this->fetchFrom($fallback['provider'], $fallback['market'], $typeIds, $setting);
            $this->rememberPrices($fallback['provider'], $fallback['market'], $answer);

            $prices = $answer['prices'];
            $down = empty($prices)
                && ($answer['errors'] > 0 || $this->countPricedInCache($fallback['provider'], $fallback['market'], $typeIds) >= self::PROVIDER_DOWN_MIN_IDS);
            $error = $down ? ($answer['error'] ?? 'It answered, but with no prices at all') : null;
        } catch (\Throwable $e) {
            $prices = [];
            $down = true;
            $error = $e->getMessage();
        }

        return [$prices, $down, $error];
    }

    /**
     * The provider to ask when this one cannot answer, with the market to ask
     * it for, or why there is none. A fallback that cannot price at the
     * market prices at Jita, and the note says so.
     *
     * @return array{provider: ?string, market: string, note: ?string, why: ?string, ok: bool, error: ?string}
     */
    public function fallbackFor(BuybackSetting $setting, string $provider, string $market): array
    {
        $choice = (string) ($setting->fallback_provider ?? self::PROVIDER_FUZZWORK);
        $none = fn (string $why) => ['provider' => null, 'market' => $market, 'note' => null, 'why' => $why, 'ok' => false, 'error' => null];

        if ($choice === '' || $choice === self::FALLBACK_NONE) {
            return $none('No fallback provider is set.');
        }

        if ($choice === $provider) {
            return $none('The fallback provider is the price provider itself.');
        }

        $unavailable = $this->unavailableAsFallback($setting, $choice);
        if ($unavailable !== null) {
            return $none('The fallback provider cannot run: ' . $unavailable);
        }

        $note = null;
        $at = $market;
        if (! $this->canPriceAt($choice, $market)) {
            $note = $this->providerLabel($choice) . ' cannot price at ' . $this->marketName($market) . ', so it prices at Jita.';
            $at = 'jita';
        }

        return ['provider' => $choice, 'market' => $at, 'note' => $note, 'why' => null, 'ok' => false, 'error' => null];
    }

    /**
     * Why a provider cannot stand in for another, or null if it can.
     */
    public function unavailableAsFallback(BuybackSetting $setting, string $candidate): ?string
    {
        if (! isset(self::PROVIDER_LABELS[$candidate])) {
            return $candidate . ' is not a price provider.';
        }

        if ($candidate === self::PROVIDER_JANICE && empty($setting->janice_api_key)) {
            return 'Janice has no API key.';
        }

        if ($candidate === self::PROVIDER_MANAGER_CORE && ! $this->managerCoreInstalled()) {
            return 'Manager Core is not installed.';
        }

        return null;
    }

    /**
     * Whether a provider can price at this market: a hub, or for Fuzzwork any
     * region id. Manager Core's own markets are only known to Manager Core,
     * so as a fallback it is asked for hubs only.
     */
    public function canPriceAt(string $provider, string $market): bool
    {
        return match ($provider) {
            self::PROVIDER_JANICE => isset(self::JANICE_MARKETS[$market]),
            self::PROVIDER_MANAGER_CORE => isset(self::HUB_REGIONS[$market]),
            self::PROVIDER_FUZZWORK => isset(self::HUB_REGIONS[$market]) || ctype_digit($market),
            default => false,
        };
    }

    /**
     * With "fall back to Jita" on and a market other than Jita, a side with
     * no orders at the market takes Jita's price from the same provider.
     *
     * @return array{0: array<int, array{buy: ?float, sell: ?float}>, 1: int[]}
     */
    protected function fillFromJita(BuybackSetting $setting, string $provider, string $market, array $prices): array
    {
        if (! ($setting->fallback_to_jita ?? true) || $market === 'jita') {
            return [$prices, []];
        }

        $short = [];
        foreach ($prices as $typeId => $sides) {
            if ($sides['buy'] === null || $sides['sell'] === null) {
                $short[] = (int) $typeId;
            }
        }

        if (empty($short)) {
            return [$prices, []];
        }

        $jita = $provider === self::PROVIDER_MANAGER_CORE
            ? []
            : $this->freshCachedPrices($provider, 'jita', $short, $this->ttlMinutes($setting));
        $toAsk = array_values(array_diff($short, array_keys($jita)));
        $jita = array_filter($jita);

        if (! empty($toAsk)) {
            try {
                $answer = $this->fetchFrom($provider, 'jita', $toAsk, $setting);
                $this->rememberPrices($provider, 'jita', $answer);
                $jita += $answer['prices'];
            } catch (\Throwable $e) {
                Log::warning('[Buyback Manager] Jita prices for missing sides were not available: ' . $e->getMessage());
            }
        }

        $filled = [];
        foreach ($short as $typeId) {
            if (! isset($jita[$typeId])) {
                continue;
            }

            $sides = $prices[$typeId];
            $changed = false;
            foreach (['buy', 'sell'] as $side) {
                if ($sides[$side] === null && $jita[$typeId][$side] !== null) {
                    $sides[$side] = $jita[$typeId][$side];
                    $changed = true;
                }
            }

            if ($changed) {
                $prices[$typeId] = $sides;
                $filled[] = $typeId;
            }
        }

        return [$prices, $filled];
    }

    /**
     * One line for the appraisal saying where prices came from, when they
     * did not all come from the provider. Null when they did.
     */
    protected function describe(array $result): ?string
    {
        $provider = $this->providerLabel($result['provider']);
        $fallback = $result['fallback'];
        $fallbackName = $fallback && $fallback['provider'] !== null ? $this->providerLabel($fallback['provider']) : null;
        $fallbackAt = $fallback && $fallback['provider'] !== null && $fallback['market'] !== $result['market']
            ? ' at ' . $this->marketName($fallback['market'])
            : '';
        $fromFallback = count($result['from_fallback']);
        $fromCache = count($result['from_cache']);

        $parts = [];

        if ($result['stood_in']) {
            $parts[] = $fromFallback > 0
                ? "{$provider} did not answer, so {$fallbackName} priced {$fromFallback} " . Str::plural('item', $fromFallback) . "{$fallbackAt}."
                : "{$provider} did not answer.";
        } elseif (! empty($result['unanswered'])) {
            $gaps = count($result['unanswered']);
            $why = $result['provider'] === self::PROVIDER_MANAGER_CORE
                ? "Manager Core had no price yet for {$gaps} " . Str::plural('item', $gaps)
                : "{$provider} could not answer for {$gaps} " . Str::plural('item', $gaps);
            $parts[] = $fromFallback > 0
                ? "{$why}, so {$fallbackName} priced {$fromFallback}{$fallbackAt}."
                : "{$why}.";
        }

        if ($fromCache > 0) {
            $parts[] = "{$fromCache} " . Str::plural('item', $fromCache) . ' kept the last cached price.';
        }

        $fromJita = count($result['from_jita']);
        if ($fromJita > 0) {
            $parts[] = "{$fromJita} " . Str::plural('item', $fromJita) . ' with no orders on one side at '
                . $this->marketName($result['market']) . " took Jita's price for that side.";
        }

        return empty($parts) ? null : Str::limit(implode(' ', $parts), 250, '');
    }

    // ============================================================
    // PROVIDERS
    // ============================================================

    /**
     * One provider's answer at one market. Items in a request that failed,
     * and items Manager Core holds no price for, come back unanswered so the
     * fallback can price them.
     *
     * @return array{prices: array<int, array{buy: ?float, sell: ?float}>, answered: int[], unanswered: int[], errors: int, error: ?string}
     * @throws Exception when the provider cannot be asked at all
     */
    protected function fetchFrom(string $provider, string $market, array $typeIds, BuybackSetting $setting): array
    {
        return match ($provider) {
            self::PROVIDER_JANICE => $this->fetchFromJanice($market, $typeIds, (string) $setting->janice_api_key),
            self::PROVIDER_MANAGER_CORE => $this->fetchFromManagerCore($market, $typeIds),
            self::PROVIDER_FUZZWORK => $this->fetchFromFuzzwork($market, $typeIds),
            default => throw new Exception($provider . ' is not a price provider.'),
        };
    }

    protected function fetchFromFuzzwork(string $market, array $typeIds): array
    {
        $regionId = self::HUB_REGIONS[$market] ?? (ctype_digit($market) ? (int) $market : null);
        if (! $regionId) {
            throw new Exception('Fuzzwork cannot price at ' . $this->marketName($market) . '.');
        }

        $answer = $this->emptyAnswer();

        foreach (array_chunk($typeIds, self::FUZZWORK_BATCH_SIZE) as $batch) {
            if ($answer['errors'] >= self::FAIL_FAST_AFTER && empty($answer['answered'])) {
                array_push($answer['unanswered'], ...$batch);
                continue;
            }

            try {
                $response = Http::timeout(self::FUZZWORK_TIMEOUT_SECONDS)
                    ->acceptJson()
                    ->get(self::FUZZWORK_URL, [
                        'region' => $regionId,
                        'types' => implode(',', $batch),
                    ]);

                if (! $response->successful()) {
                    throw new Exception('Fuzzwork returned HTTP ' . $response->status());
                }

                $data = $response->json();
                if (! is_array($data)) {
                    throw new Exception('Fuzzwork returned something other than a list of prices');
                }
            } catch (\Throwable $e) {
                $this->noteRequestError($answer, $batch, $e->getMessage());
                continue;
            }

            array_push($answer['answered'], ...$batch);
            $answer['prices'] += self::fuzzworkPrices($data, $batch);
        }

        return $answer;
    }

    /**
     * Fuzzwork's aggregates as both sides: the highest buy order and the
     * lowest sell order. It answers every id asked, with zeros for an item
     * nobody trades.
     *
     * @return array<int, array{buy: ?float, sell: ?float}>
     */
    public static function fuzzworkPrices(array $data, array $typeIds): array
    {
        $prices = [];
        foreach ($typeIds as $typeId) {
            $row = $data[$typeId] ?? $data[(string) $typeId] ?? null;
            if (! is_array($row)) {
                continue;
            }

            $sides = self::sides((float) ($row['buy']['max'] ?? 0), (float) ($row['sell']['min'] ?? 0));
            if ($sides !== null) {
                $prices[(int) $typeId] = $sides;
            }
        }

        return $prices;
    }

    protected function fetchFromJanice(string $market, array $typeIds, string $apiKey): array
    {
        if ($apiKey === '') {
            throw new Exception('Janice has no API key.');
        }

        $marketId = self::JANICE_MARKETS[$market] ?? null;
        if ($marketId === null) {
            throw new Exception('Janice cannot price at ' . $this->marketName($market) . '.');
        }

        $answer = $this->emptyAnswer();

        foreach (array_chunk($typeIds, self::JANICE_BATCH_SIZE) as $index => $batch) {
            if ($answer['errors'] >= self::FAIL_FAST_AFTER && empty($answer['answered'])) {
                array_push($answer['unanswered'], ...$batch);
                continue;
            }

            if ($index > 0) {
                usleep(self::JANICE_BATCH_PAUSE_US);
            }

            try {
                $response = Http::timeout(self::JANICE_TIMEOUT_SECONDS)
                    ->withHeaders([
                        'X-ApiKey' => $apiKey,
                        'accept' => 'application/json',
                    ])
                    ->withBody(implode("\n", $batch), 'text/plain')
                    ->post(self::JANICE_PRICER_URL . '?market=' . $marketId);
            } catch (\Throwable $e) {
                $this->noteRequestError($answer, $batch, $e->getMessage());
                continue;
            }

            // A refused key stops the lot. Asking again in other batches is
            // exactly the traffic that gets a key blocked.
            if (in_array($response->status(), [401, 403, 429], true)) {
                throw new Exception('Janice refused the request with HTTP ' . $response->status()
                    . ($response->status() === 429 ? ' (too many requests)' : ' (check the API key)'));
            }

            $items = $response->successful() ? $response->json() : null;
            if (! is_array($items)) {
                $this->noteRequestError($answer, $batch, $response->successful()
                    ? 'Janice returned something other than a list of prices'
                    : 'Janice returned HTTP ' . $response->status());
                continue;
            }

            // Janice leaves out ids it does not know, which is an answer too.
            array_push($answer['answered'], ...$batch);
            $answer['prices'] += self::janicePrices($items, $batch);
        }

        return $answer;
    }

    /**
     * Janice's pricer answer as both sides, from its immediate prices: the
     * highest buy order and the lowest sell order.
     *
     * @return array<int, array{buy: ?float, sell: ?float}>
     */
    public static function janicePrices(array $items, array $typeIds): array
    {
        $asked = array_flip(array_map('intval', $typeIds));
        $prices = [];

        foreach ($items as $item) {
            $typeId = is_array($item) ? (int) ($item['itemType']['eid'] ?? 0) : 0;
            if (! isset($asked[$typeId])) {
                continue;
            }

            $sides = self::sides(
                (float) ($item['immediatePrices']['buyPrice'] ?? 0),
                (float) ($item['immediatePrices']['sellPrice'] ?? 0)
            );
            if ($sides !== null) {
                $prices[$typeId] = $sides;
            }
        }

        return $prices;
    }

    protected function fetchFromManagerCore(string $market, array $typeIds): array
    {
        if (! $this->managerCoreInstalled()) {
            throw new Exception('Manager Core is not installed.');
        }

        // The plugin key lets Manager Core honour a provider override an
        // admin set for Buyback in its Pricing Preferences. Without one it
        // answers from its own cache.
        $raw = ManagerCoreIntegration::bridge()->call(
            'ManagerCore',
            'pricing.getPrices',
            $typeIds,
            $market,
            'both',
            self::MC_PLUGIN_KEY
        );

        if (! is_array($raw)) {
            throw new Exception('Manager Core did not answer pricing.getPrices.');
        }

        $read = self::managerCorePrices($raw, $typeIds, Carbon::now()->subHours(self::MC_PRICE_STALENESS_HOURS));

        if ($read['stale'] > 0) {
            Log::warning("[Buyback Manager] {$read['stale']} Manager Core prices at '{$market}' are older than " . self::MC_PRICE_STALENESS_HOURS . ' hours; check that its price refresh is running');
        }

        $answer = $this->emptyAnswer();
        $answer['prices'] = $read['prices'];
        $answer['answered'] = array_keys($read['prices']);
        $answer['unanswered'] = $read['unanswered'];

        return $answer;
    }

    /**
     * Manager Core's [typeId => ['buy' => stats, 'sell' => stats]] as both
     * sides: the highest buy order and the lowest sell order, the same prices
     * Fuzzwork and Janice give. An item with no price on either side is
     * unanswered, not unpriced: Manager Core only holds items it has been
     * asked to fetch, so no price there is no proof the item has no market.
     *
     * @return array{prices: array<int, array{buy: ?float, sell: ?float}>, unanswered: int[], stale: int}
     */
    public static function managerCorePrices(array $raw, array $typeIds, Carbon $staleBefore): array
    {
        $typeIds = array_map('intval', $typeIds);
        $byType = self::normaliseBridgeShape($raw, $typeIds);
        $out = ['prices' => [], 'unanswered' => [], 'stale' => 0];

        foreach ($typeIds as $typeId) {
            $entry = is_array($byType[$typeId] ?? null) ? $byType[$typeId] : [];
            $buy = is_array($entry['buy'] ?? null) ? $entry['buy'] : null;
            $sell = is_array($entry['sell'] ?? null) ? $entry['sell'] : null;

            $sides = self::sides((float) ($buy['max'] ?? 0), (float) ($sell['min'] ?? 0));
            if ($sides === null) {
                $out['unanswered'][] = $typeId;
                continue;
            }

            $out['prices'][$typeId] = $sides;
            if (self::isStale($sell ?? $buy, $staleBefore)) {
                $out['stale']++;
            }
        }

        return $out;
    }

    /**
     * One provider's prices for a test, changing nothing: no fallback, no
     * cached prices, no status and no alert. The error says why the provider
     * gave nothing, when it gave nothing.
     *
     * @return array{prices: array<int, array{buy: ?float, sell: ?float}>, market: string, unanswered: int[], error: ?string}
     */
    public function tryProvider(string $provider, BuybackSetting $setting, array $typeIds): array
    {
        $market = $this->marketFor($setting, $provider);

        try {
            $answer = $this->fetchFrom($provider, $market, $this->cleanIds($typeIds), $setting);
        } catch (\Throwable $e) {
            return ['prices' => [], 'market' => $market, 'unanswered' => [], 'error' => $e->getMessage()];
        }

        return [
            'prices' => $answer['prices'],
            'market' => $market,
            'unanswered' => $answer['unanswered'],
            'error' => empty($answer['prices']) && $answer['errors'] > 0
                ? ($answer['error'] ?? 'The provider did not answer')
                : null,
        ];
    }

    // ============================================================
    // SETTINGS
    // ============================================================

    public function providerOf(BuybackSetting $setting): string
    {
        $provider = (string) ($setting->price_provider ?: self::PROVIDER_FUZZWORK);

        return isset(self::PROVIDER_LABELS[$provider]) ? $provider : self::PROVIDER_FUZZWORK;
    }

    /**
     * Where a provider prices for this corporation: a hub, a region id for
     * Fuzzwork's custom region, or a Manager Core market key.
     */
    public function marketFor(BuybackSetting $setting, ?string $provider = null): string
    {
        $provider ??= $this->providerOf($setting);

        if ($provider === self::PROVIDER_JANICE) {
            $market = (string) ($setting->janice_market ?: 'jita');

            return isset(self::JANICE_MARKETS[$market]) ? $market : 'jita';
        }

        if ($provider === self::PROVIDER_MANAGER_CORE) {
            return $this->managerCoreMarketFor($setting);
        }

        $source = (string) ($setting->price_source ?: 'jita');
        if (isset(self::HUB_REGIONS[$source])) {
            return $source;
        }

        if ($source === 'region' && (int) $setting->region_id > 0) {
            $hub = array_search((int) $setting->region_id, self::HUB_REGIONS, true);

            return $hub === false ? (string) (int) $setting->region_id : $hub;
        }

        return 'jita';
    }

    /**
     * The Manager Core market for this corporation. A market an admin set
     * for Buyback in Manager Core's Pricing Preferences wins over the
     * corporation's own choice; a preference Buyback registered itself does
     * not, since it only mirrors one corporation's setting.
     */
    public function managerCoreMarketFor(BuybackSetting $setting): string
    {
        $pref = $this->managerCorePreference();
        if ($pref && ! empty($pref['admin_overridden']) && ! empty($pref['market'])) {
            return (string) $pref['market'];
        }

        return (string) ($setting->manager_core_market ?: 'jita');
    }

    /**
     * Manager Core's preference row for Buyback, or null when Manager Core is
     * not installed, has none, or cannot say.
     */
    public function managerCorePreference(): ?array
    {
        if ($this->mcPreferenceReadAt !== null && time() - $this->mcPreferenceReadAt < self::MC_PREFERENCE_SECONDS) {
            return $this->mcPreference;
        }

        $this->mcPreferenceReadAt = time();
        $this->mcPreference = null;

        if (! $this->managerCoreInstalled()) {
            return null;
        }

        try {
            $pref = ManagerCoreIntegration::bridge()->call('ManagerCore', 'pricing.getPreferenceForPlugin', self::MC_PLUGIN_KEY);
            $this->mcPreference = is_array($pref) ? $pref : null;
        } catch (\Throwable $e) {
            Log::warning('[Buyback Manager] Could not read the Manager Core pricing preference: ' . $e->getMessage());
        }

        return $this->mcPreference;
    }

    protected function managerCoreInstalled(): bool
    {
        return ManagerCoreIntegration::isAvailable();
    }

    protected function ttlMinutes(BuybackSetting $setting): int
    {
        return max(0, (int) ($setting->price_cache_ttl_minutes ?? self::DEFAULT_CACHE_TTL_MINUTES));
    }

    public function providerLabel(string $provider): string
    {
        return self::PROVIDER_LABELS[$provider] ?? ucfirst($provider);
    }

    /**
     * A market as people read it: "Jita", a region's name for a region id,
     * or the name Manager Core gives one of its markets.
     */
    public function marketName(string $market): string
    {
        if (isset($this->marketNames[$market])) {
            return $this->marketNames[$market];
        }

        $name = isset(self::HUB_REGIONS[$market]) ? ucfirst($market) : null;

        try {
            if ($name === null && ctype_digit($market)) {
                $region = DB::table('mapDenormalize')->where('itemID', (int) $market)->value('itemName');
                $name = $region ? (string) $region : 'region ' . $market;
            }

            if ($name === null && Schema::hasTable('manager_core_markets')) {
                $mc = DB::table('manager_core_markets')->where('key', $market)->value('name');
                $name = $mc ? (string) $mc : null;
            }
        } catch (\Throwable $e) {
            // A label is not worth failing a quote over.
        }

        return $this->marketNames[$market] = $name ?? $market;
    }

    public function getAvailableProviders(): array
    {
        return [
            self::PROVIDER_FUZZWORK => [
                'name' => 'Fuzzwork',
                'description' => 'Free market aggregates, no API key. Prices at a trade hub or any region.',
                'requires_config' => false,
                'available' => true,
            ],
            self::PROVIDER_JANICE => [
                'name' => 'Janice',
                'description' => 'Needs a Janice API key. Prices at Jita or Amarr.',
                'requires_config' => true,
                'config_fields' => ['janice_api_key'],
                'available' => true,
            ],
            self::PROVIDER_MANAGER_CORE => [
                'name' => 'Manager Core',
                'description' => "Reads Manager Core's own prices, including citadel markets added there.",
                'requires_config' => false,
                'available' => $this->managerCoreInstalled(),
            ],
        ];
    }

    public function validateProviderConfig(string $provider, BuybackSetting $setting): bool
    {
        $providers = $this->getAvailableProviders();
        if (! isset($providers[$provider])) {
            return false;
        }

        if ($provider === self::PROVIDER_MANAGER_CORE) {
            return $this->managerCoreInstalled();
        }

        foreach ($providers[$provider]['config_fields'] ?? [] as $field) {
            if (empty($setting->{$field})) {
                return false;
            }
        }

        return true;
    }

    // ============================================================
    // CACHE
    // ============================================================

    /**
     * Prices this provider gave at this market within the TTL. An item it
     * answered with no price is in the result as null, so it is not asked
     * again until the TTL runs out either.
     *
     * @return array<int, array{buy: ?float, sell: ?float}|null>
     */
    protected function freshCachedPrices(string $provider, string $market, array $typeIds, int $ttlMinutes): array
    {
        if ($ttlMinutes <= 0 || empty($typeIds)) {
            return [];
        }

        try {
            $rows = BuybackPriceCache::where('market', $this->cacheMarket($provider, $market))
                ->where('provider', $provider)
                ->whereIn('type_id', $typeIds)
                ->where('checked_at', '>=', Carbon::now()->subMinutes($ttlMinutes))
                ->get();
        } catch (\Throwable $e) {
            Log::warning('[Buyback Manager] Could not read cached prices: ' . $e->getMessage());

            return [];
        }

        $prices = [];
        foreach ($rows as $row) {
            // A side last seen before the latest answer had no orders in it.
            $buy = $row->buy_seen_at && $row->buy_seen_at->gte($row->checked_at) ? (float) $row->buy_price : 0.0;
            $sell = $row->sell_seen_at && $row->sell_seen_at->gte($row->checked_at) ? (float) $row->sell_price : 0.0;
            $prices[(int) $row->type_id] = self::sides($buy, $sell);
        }

        return $prices;
    }

    /**
     * The last real price of each side, however old, for standing in when
     * nothing can be asked.
     *
     * @return array<int, array{buy: ?float, sell: ?float}>
     */
    protected function lastCachedPrices(string $provider, string $market, array $typeIds): array
    {
        $typeIds = $this->cleanIds($typeIds);
        if (empty($typeIds)) {
            return [];
        }

        try {
            $rows = BuybackPriceCache::where('market', $this->cacheMarket($provider, $market))
                ->whereIn('type_id', $typeIds)
                ->get();
        } catch (\Throwable $e) {
            Log::warning('[Buyback Manager] Could not read cached prices: ' . $e->getMessage());

            return [];
        }

        $prices = [];
        foreach ($rows as $row) {
            $sides = self::sides((float) ($row->buy_price ?? 0), (float) ($row->sell_price ?? 0));
            if ($sides !== null) {
                $prices[(int) $row->type_id] = $sides;
            }
        }

        return $prices;
    }

    protected function countPricedInCache(string $provider, string $market, array $typeIds): int
    {
        if (empty($typeIds)) {
            return 0;
        }

        try {
            return BuybackPriceCache::where('market', $this->cacheMarket($provider, $market))
                ->whereIn('type_id', $typeIds)
                ->where(function ($q) {
                    $q->whereNotNull('buy_price')->orWhereNotNull('sell_price');
                })
                ->count();
        } catch (\Throwable $e) {
            // Counting nothing leaves the provider unblamed, which is the
            // safe side for a status note.
            return 0;
        }
    }

    /**
     * Write an answer to the cache. A side only changes when it has a real
     * price, so an answer with no orders on one side never wipes the last
     * price there; it only moves checked_at past it.
     */
    protected function rememberPrices(string $provider, string $market, array $answer): void
    {
        if (empty($answer['answered'])) {
            return;
        }

        $key = $this->cacheMarket($provider, $market);
        $now = Carbon::now()->toDateTimeString();
        $groups = [];

        foreach ($answer['answered'] as $typeId) {
            $sides = $answer['prices'][$typeId] ?? ['buy' => null, 'sell' => null];
            $row = ['market' => $key, 'type_id' => (int) $typeId, 'provider' => $provider, 'checked_at' => $now];
            $columns = ['provider', 'checked_at'];

            foreach (['buy', 'sell'] as $side) {
                if ($sides[$side] !== null) {
                    $row[$side . '_price'] = $sides[$side];
                    $row[$side . '_seen_at'] = $now;
                    array_push($columns, $side . '_price', $side . '_seen_at');
                }
            }

            // upsert needs the same columns on every row, so rows are grouped
            // by which sides they carry.
            $groupKey = implode(',', $columns);
            $groups[$groupKey]['columns'] = $columns;
            $groups[$groupKey]['rows'][] = $row;
        }

        try {
            foreach ($groups as $group) {
                foreach (array_chunk($group['rows'], 500) as $chunk) {
                    BuybackPriceCache::upsert($chunk, ['market', 'type_id'], $group['columns']);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[Buyback Manager] Could not cache prices: ' . $e->getMessage());
        }
    }

    /**
     * The cache key for a market. Manager Core's own market keys are kept
     * apart from hubs and region ids, which every provider shares.
     */
    public function cacheMarket(string $provider, string $market): string
    {
        if ($provider === self::PROVIDER_MANAGER_CORE && ! isset(self::HUB_REGIONS[$market])) {
            return 'mc:' . $market;
        }

        return $market;
    }

    // ============================================================
    // STATUS AND ALERTS
    // ============================================================

    /**
     * How the corporation's provider is doing, for the settings and
     * Diagnostics pages.
     */
    public function providerStatus(BuybackSetting $setting): ?BuybackPriceStatus
    {
        try {
            return BuybackPriceStatus::where('setting_id', $setting->id)->first();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Whether an outcome is news. A provider that is down stays down for a
     * while and a message on every quote would be noise nobody reads, so only
     * the change is announced: failing, answering again, or while it is down
     * its fallback starting or stopping answering.
     */
    public static function statusChanged(bool $wasFailing, ?string $previousFallback, bool $previousFallbackOk, bool $ok, ?string $fallback, bool $fallbackOk): bool
    {
        if ($ok) {
            return $wasFailing;
        }

        return ! $wasFailing || $previousFallback !== $fallback || $previousFallbackOk !== $fallbackOk;
    }

    /**
     * Remember how the provider did, and say so once when that changes. An
     * item with no price is not a failure and never gets here.
     */
    protected function recordOutcome(BuybackSetting $setting, string $provider, string $market, bool $ok, ?string $error = null, array $fallback = []): void
    {
        if (! $setting->exists) {
            return;
        }

        try {
            Cache::lock('buyback-manager:price-status:' . $setting->id, 10)->get(function () use ($setting, $provider, $market, $ok, $error, $fallback) {
                $status = BuybackPriceStatus::firstOrNew(['setting_id' => $setting->id]);
                $wasFailing = $status->exists && $status->failing;

                $fallbackKey = $ok ? null : ($fallback['provider'] ?? null);
                $fallbackOk = $fallbackKey !== null && ! empty($fallback['ok']);
                $fallbackError = $ok ? null : ($fallbackKey !== null ? ($fallback['error'] ?? null) : ($fallback['why'] ?? null));

                $changed = self::statusChanged($wasFailing, $status->fallback, (bool) $status->fallback_ok, $ok, $fallbackKey, $fallbackOk);

                $now = Carbon::now();
                $since = $ok ? null : ($wasFailing && $status->since ? $status->since : $now);
                $failedSince = $status->since;
                $lastSuccess = $ok ? $now : $status->last_success;

                $status->fill([
                    'provider' => $provider,
                    'market' => $market,
                    'failing' => ! $ok,
                    'error' => $ok ? null : Str::limit((string) $error, 1000),
                    'since' => $since,
                    'last_success' => $lastSuccess,
                    'fallback' => $fallbackKey,
                    'fallback_ok' => $fallbackOk,
                    'fallback_error' => $fallbackError !== null ? Str::limit((string) $fallbackError, 1000) : null,
                ])->save();

                if (! $changed) {
                    return;
                }

                $this->announce($ok ? 'buyback.pricing.recovered' : 'buyback.pricing.failing', [
                    'corporation_id' => (int) $setting->corporation_id,
                    'corporation_name' => optional($setting->corporation)->name,
                    'provider' => $provider,
                    'market' => $this->marketName($market),
                    'error' => $ok ? null : $error,
                    'since' => ($ok ? $failedSince : $since)?->toIso8601String(),
                    'last_success' => $lastSuccess?->toIso8601String(),
                    'fallback' => $fallbackKey !== null
                        ? $this->providerLabel($fallbackKey) . ', ' . $this->marketName((string) $fallback['market'])
                        : null,
                    'fallback_ok' => $fallbackOk,
                    'fallback_error' => $fallbackError,
                    'fallback_note' => $fallbackKey !== null ? ($fallback['note'] ?? null) : null,
                    'advice' => $ok ? null : $this->advice($provider),
                    'at' => $now->toIso8601String(),
                ]);
            });
        } catch (\Throwable $e) {
            // Pricing a quote must not fall over because a status note or an
            // alert did.
            Log::warning('[Buyback Manager] Could not record the price provider status: ' . $e->getMessage());
        }
    }

    protected function advice(string $provider): string
    {
        if ($provider === self::PROVIDER_MANAGER_CORE && ! $this->managerCoreInstalled()) {
            return 'Manager Core is set as the price provider but is not installed. Install it again, or pick another provider under Buyback Settings.';
        }

        return 'Test the provider under Buyback Settings, or pick another one. The Diagnostics page shows the last error.';
    }

    protected function announce(string $eventName, array $payload): void
    {
        try {
            app(WebhookDispatcher::class)->dispatch($eventName, $payload);
        } catch (\Throwable $e) {
            Log::warning('[Buyback Manager] Price provider alert could not be sent: ' . $e->getMessage());
        }
    }

    // ============================================================
    // MANAGER CORE SUBSCRIPTIONS
    // ============================================================

    /**
     * Ask Manager Core to keep these items priced at this market. Only items
     * the local ledger has not seen reach the bridge, so a busy quote page
     * does not call it on every quote.
     *
     * @param  int[]  $typeIds
     * @return int  Count of newly subscribed items
     */
    public function subscribeToManagerCore(array $typeIds, string $market = 'jita', bool $immediateRefresh = false): int
    {
        if (! $this->managerCoreInstalled()) {
            return 0;
        }

        try {
            $newTypeIds = BuybackSubscribedType::missingFrom($typeIds, $market);
            if (empty($newTypeIds)) {
                return 0;
            }

            ManagerCoreIntegration::bridge()->call(
                'ManagerCore',
                'pricing.subscribeTypes',
                self::MC_PLUGIN_KEY,
                $newTypeIds,
                $market,
                1,
                $immediateRefresh
            );
            BuybackSubscribedType::markSubscribed($newTypeIds, $market);

            Log::info('[Buyback Manager] Subscribed ' . count($newTypeIds) . " new types to Manager Core market '{$market}'");

            return count($newTypeIds);
        } catch (\Throwable $e) {
            Log::warning('[Buyback Manager] Manager Core subscribe failed: ' . $e->getMessage());

            return 0;
        }
    }

    public function unsubscribeFromManagerCore(): int
    {
        if (! $this->managerCoreInstalled()) {
            return 0;
        }

        try {
            $count = ManagerCoreIntegration::bridge()->call('ManagerCore', 'pricing.unsubscribeTypes', self::MC_PLUGIN_KEY, null);
            BuybackSubscribedType::query()->delete();
            Log::info('[Buyback Manager] Unsubscribed all types from Manager Core (count=' . (int) $count . ')');

            return (int) $count;
        } catch (\Throwable $e) {
            Log::warning('[Buyback Manager] Manager Core unsubscribe failed: ' . $e->getMessage());

            return 0;
        }
    }

    // ============================================================
    // HELPERS
    // ============================================================

    /**
     * Both sides of an answer, null for a side with no orders, or null when
     * neither side has a price.
     *
     * @return array{buy: ?float, sell: ?float}|null
     */
    public static function sides(float $buy, float $sell): ?array
    {
        if ($buy <= 0 && $sell <= 0) {
            return null;
        }

        return [
            'buy' => $buy > 0 ? $buy : null,
            'sell' => $sell > 0 ? $sell : null,
        ];
    }

    protected function emptyAnswer(): array
    {
        return ['prices' => [], 'answered' => [], 'unanswered' => [], 'errors' => 0, 'error' => null];
    }

    protected function noteRequestError(array &$answer, array $batch, string $error): void
    {
        $answer['errors']++;
        $answer['error'] = $error;
        array_push($answer['unanswered'], ...$batch);

        Log::warning('[Buyback Manager] Price request failed: ' . $error, ['types' => count($batch)]);
    }

    /**
     * @return int[]
     */
    protected function cleanIds(array $typeIds): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $typeIds), fn ($id) => $id > 0)));
    }

    /**
     * Manager Core answers [typeId => stats]. An older Manager Core collapsed
     * a one-item answer to the bare stats, so that is wrapped back up.
     */
    protected static function normaliseBridgeShape(array $result, array $typeIds): array
    {
        $asked = array_flip($typeIds);
        foreach (array_keys($result) as $key) {
            if (! isset($asked[$key])) {
                return count($typeIds) === 1 ? [$typeIds[0] => $result] : [];
            }
        }

        return $result;
    }

    protected static function isStale(?array $stats, Carbon $threshold): bool
    {
        $updatedAt = $stats['updated_at'] ?? null;
        if ($updatedAt === null) {
            return false;
        }

        try {
            return ($updatedAt instanceof \DateTimeInterface ? Carbon::instance($updatedAt) : Carbon::parse((string) $updatedAt))->lt($threshold);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
