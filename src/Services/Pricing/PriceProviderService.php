<?php

namespace BuybackManager\Services\Pricing;

use BuybackManager\Integrations\ManagerCoreIntegration;
use BuybackManager\Models\BuybackPriceCache;
use BuybackManager\Models\BuybackPriceStatus;
use BuybackManager\Models\BuybackPricingRule;
use BuybackManager\Models\BuybackSetting;
use BuybackManager\Models\BuybackSubscribedType;
use BuybackManager\Services\VolumeLookup;
use BuybackManager\Services\WebhookDispatcher;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
     * The trade hubs the providers have in common, with the region each one
     * is in.
     */
    public const HUB_REGIONS = [
        'jita' => 10000002,
        'amarr' => 10000043,
        'dodixie' => 10000032,
        'hek' => 10000042,
        'rens' => 10000030,
    ];

    /**
     * The station each hub trades at. Fuzzwork is asked for the station:
     * asked for the region, it mixes in every other order there, and a
     * region's buy can then sit above its own sell.
     */
    public const HUB_STATIONS = [
        'jita' => 60003760,    // Jita IV - Moon 4 - Caldari Navy Assembly Plant
        'amarr' => 60008494,   // Amarr VIII (Oris) - Emperor Family Academy
        'dodixie' => 60011866, // Dodixie IX - Moon 20 - Federation Navy Assembly Plant
        'hek' => 60005686,     // Hek VIII - Moon 12 - Boundless Creation Factory
        'rens' => 60004588,    // Rens VI - Moon 8 - Brutor Tribe Treasury
    ];

    /**
     * Janice's market id for each hub. Janice's 1 is The Forge, a region,
     * not Amarr.
     */
    public const JANICE_MARKETS = [
        'jita' => 2,
        'amarr' => 115,
        'rens' => 116,
        'dodixie' => 117,
        'hek' => 118,
    ];

    public const DEFAULT_CACHE_TTL_MINUTES = 60;

    /**
     * Manager Core refreshes every 4 hours by default, so a price older than
     * twice that means a refresh was missed. Such a price is left to the
     * fallback, as if Manager Core had none, and logged, and is still used
     * when the fallback has nothing. Waiting would not help: Manager Core
     * only fetches at once what has no price at all.
     */
    public const MC_PRICE_STALENESS_HOURS = 8;

    /**
     * How long after an item is subscribed its first fetch may still be on
     * the queue. Manager Core holds a market's first fetch for up to 30
     * seconds when another went out just before it, and SeAT's queue is
     * shared with everything else.
     */
    public const FIRST_FETCH_IN_FLIGHT_SECONDS = 120;

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

    /** How often each ledger item is sent to Manager Core again. */
    private const LEDGER_RESEND_HOURS = 24;

    /** Items per subscribe call when the ledger is sent again. */
    private const LEDGER_RESEND_BATCH = 500;

    protected ?array $mcPreference = null;

    protected ?int $mcPreferenceReadAt = null;

    /** Whether the last preference read failed, as opposed to finding none. */
    protected bool $mcPreferenceFailed = false;

    /** @var array<string, string> */
    protected array $marketNames = [];

    /** @var array|null Manager Core's markets, as managerCoreMarkets() lists them */
    protected ?array $mcMarketList = null;

    protected ?int $mcMarketListReadAt = null;

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
     *     via: string,
     *     stood_in: bool,
     *     fallback: ?array,
     *     from_fallback: int[],
     *     from_cache: int[],
     *     from_jita: int[],
     *     unanswered: int[],
     *     stale: int[],
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
            // The provider Manager Core prices the market through for this
            // corporation, or '' for the market's own.
            'via' => $this->managerCoreProviderAt($setting, $market),
            'stood_in' => false,
            'fallback' => null,
            'from_fallback' => [],
            'from_cache' => [],
            'from_jita' => [],
            'unanswered' => [],
            // Of the unanswered, those Manager Core had only an old price for.
            'stale' => [],
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
                $result['stale'] = array_values(array_intersect($answer['stale'] ?? [], $answer['unanswered']));
                $fallback = $this->fallbackFor($setting, $provider, $market);

                $fromFallback = [];
                if ($fallback['provider'] !== null) {
                    [$fromFallback, $down, $fallback['error']] = $this->askFallback($setting, $fallback, $answer['unanswered']);
                    $fallback['ok'] = ! $down;
                }
                $result['fallback'] = $fallback;
                $result['from_fallback'] = array_keys($fromFallback);
                $prices += $fromFallback;

                // What the fallback could not price keeps its last price:
                // Manager Core's own old one, which is the newest there is,
                // or else the cache. An item Manager Core has never priced
                // has nothing cached.
                $left = array_diff($answer['unanswered'], array_keys($fromFallback));
                $old = array_intersect_key($answer['old_prices'] ?? [], array_flip($left));
                $kept = $old + $this->lastCachedPrices($provider, $market, array_diff($left, array_keys($old)));
                $result['from_cache'] = array_keys($kept);
                $prices += $kept;
            }
        }

        if ($provider === self::PROVIDER_MANAGER_CORE) {
            $this->subscribeToManagerCore($typeIds, $market, true, $result['via']);
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
            $old = count($result['stale'] ?? []);
            $missing = $gaps - $old;
            $through = ($result['via'] ?? '') !== '' ? $this->managerCoreProviderLabel($result['via']) . ' ' : '';
            $tooOld = 'over ' . self::MC_PRICE_STALENESS_HOURS . ' hours old';
            $why = match (true) {
                $result['provider'] !== self::PROVIDER_MANAGER_CORE => "{$provider} could not answer for {$gaps} " . Str::plural('item', $gaps),
                $old === 0 => "Manager Core had no {$through}price yet for {$gaps} " . Str::plural('item', $gaps),
                $missing === 0 => "Manager Core's {$through}price for {$old} " . Str::plural('item', $old) . " was {$tooOld}",
                default => "Manager Core had no {$through}price yet for {$missing} " . Str::plural('item', $missing) . " and one {$tooOld} for {$old}",
            };
            $parts[] = $fromFallback > 0
                ? "{$why}, so {$fallbackName} priced {$fromFallback}{$fallbackAt}."
                : "{$why}.";

            // The quote subscribed them, so a quote shortly after differs.
            if ($result['provider'] === self::PROVIDER_MANAGER_CORE && $missing > 0) {
                $parts[] = 'Manager Core is fetching ' . ($missing === 1 ? 'it' : 'them') . ' for the next quote.';
            }
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
            self::PROVIDER_MANAGER_CORE => $this->fetchFromManagerCore($market, $typeIds, $this->managerCoreProviderAt($setting, $market)),
            self::PROVIDER_FUZZWORK => $this->fetchFromFuzzwork($market, $typeIds),
            default => throw new Exception($provider . ' is not a price provider.'),
        };
    }

    protected function fetchFromFuzzwork(string $market, array $typeIds): array
    {
        $where = self::fuzzworkPlace($market);
        if ($where === null) {
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
                    ->get(self::FUZZWORK_URL, $where + [
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
     * What Fuzzwork is asked for at a market: a hub's station, or a region
     * by its id. Null where it cannot price.
     *
     * @return array{station: int}|array{region: int}|null
     */
    public static function fuzzworkPlace(string $market): ?array
    {
        if (isset(self::HUB_STATIONS[$market])) {
            return ['station' => self::HUB_STATIONS[$market]];
        }

        return ctype_digit($market) && (int) $market > 0 ? ['region' => (int) $market] : null;
    }

    /**
     * Fuzzwork's aggregates as both sides: the highest buy order and the
     * lowest sell order, with the average of the top 5% of buy orders for the
     * thin-market guard. It answers every id asked, with zeros for an item
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

            $sides = self::sides((float) ($row['buy']['max'] ?? 0), (float) ($row['sell']['min'] ?? 0), (float) ($row['buy']['percentile'] ?? 0));
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
            VolumeLookup::remember(self::janicePackagedVolumes($items, $batch));
        }

        return $answer;
    }

    /**
     * Janice's pricer answer as both sides, from its immediate prices: the
     * highest buy order and the lowest sell order. Its top-5% buy average
     * stays out of the thin-market guard: for some items it sits far under
     * the market (PLEX at 2% of its best buy), and the guard would price
     * them down to it.
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

    /**
     * The packaged volumes in a Janice answer, where they differ from the
     * assembled ones. The rest add nothing the SDE does not already say.
     *
     * @return array<int, float>
     */
    public static function janicePackagedVolumes(array $items, array $typeIds): array
    {
        $asked = array_flip(array_map('intval', $typeIds));
        $volumes = [];

        foreach ($items as $item) {
            $typeId = is_array($item) ? (int) ($item['itemType']['eid'] ?? 0) : 0;
            $packaged = (float) ($item['itemType']['packagedVolume'] ?? 0);
            if (isset($asked[$typeId]) && $packaged > 0 && $packaged != (float) ($item['itemType']['volume'] ?? 0)) {
                $volumes[$typeId] = $packaged;
            }
        }

        return $volumes;
    }

    /**
     * Manager Core's prices at a market: the market's own, or with $via the
     * prices it keeps there through that provider for Buyback.
     */
    protected function fetchFromManagerCore(string $market, array $typeIds, string $via = '', bool $warnStale = true): array
    {
        if (! $this->managerCoreInstalled()) {
            throw new Exception('Manager Core is not installed.');
        }

        if ($via !== '') {
            // Only what Manager Core has fetched is answered. An item it has
            // not fetched through the provider yet is unanswered, so the
            // fallback prices it and nothing stands in silently.
            $raw = $this->mcBridge()->call('ManagerCore', 'pricing.getVariantPrices', $typeIds, $market, $via, 'both');
        } else {
            // The plugin key lets Manager Core honour a provider override an
            // admin set for Buyback in its Pricing Preferences. Without one it
            // answers from its own cache.
            $raw = $this->mcBridge()->call('ManagerCore', 'pricing.getPrices', $typeIds, $market, 'both', self::MC_PLUGIN_KEY);
        }

        if (! is_array($raw)) {
            throw new Exception('Manager Core did not answer ' . ($via !== '' ? 'pricing.getVariantPrices.' : 'pricing.getPrices.'));
        }

        $read = self::managerCorePrices($raw, $typeIds, Carbon::now()->subHours(self::MC_PRICE_STALENESS_HOURS));

        if ($warnStale && ! empty($read['stale'])) {
            Log::warning('[Buyback Manager] ' . count($read['stale']) . " Manager Core prices at '{$market}'" . $this->throughText($via) . ' are older than ' . self::MC_PRICE_STALENESS_HOURS . ' hours, so the fallback prices them; check that its price refresh is running');
        }

        $answer = $this->emptyAnswer();
        $answer['prices'] = $read['prices'];
        $answer['answered'] = array_keys($read['prices']);
        $answer['unanswered'] = $read['unanswered'];
        $answer['stale'] = $read['stale'];
        $answer['old_prices'] = $read['old_prices'];

        return $answer;
    }

    /**
     * Manager Core's [typeId => ['buy' => stats, 'sell' => stats]] as both
     * sides: the highest buy order and the lowest sell order, the same prices
     * Fuzzwork and Janice give. An item with no price on either side is
     * unanswered, not unpriced: Manager Core only holds items it has been
     * asked to fetch, so no price there is no proof the item has no market.
     * A price older than $staleBefore is unanswered too, listed as stale and
     * kept in old_prices for when nothing newer can be found.
     *
     * @return array{prices: array<int, array{buy: ?float, sell: ?float}>, unanswered: int[], stale: int[], old_prices: array<int, array{buy: ?float, sell: ?float}>}
     */
    public static function managerCorePrices(array $raw, array $typeIds, Carbon $staleBefore): array
    {
        $typeIds = array_map('intval', $typeIds);
        $byType = self::normaliseBridgeShape($raw, $typeIds);
        $out = ['prices' => [], 'unanswered' => [], 'stale' => [], 'old_prices' => []];

        foreach ($typeIds as $typeId) {
            $entry = is_array($byType[$typeId] ?? null) ? $byType[$typeId] : [];
            $buy = is_array($entry['buy'] ?? null) ? $entry['buy'] : null;
            $sell = is_array($entry['sell'] ?? null) ? $entry['sell'] : null;

            // Each provider fills Manager Core's percentile with a figure of
            // its own, and only Fuzzwork's is the top-5% average the guard
            // needs. Goonpraisal's is weighted by volume, so one 0.01 ISK
            // order for millions of units sets it.
            $average = self::fromFuzzwork($buy) ? (float) ($buy['percentile'] ?? 0) : 0.0;

            $sides = self::sides((float) ($buy['max'] ?? 0), (float) ($sell['min'] ?? 0), $average);
            if ($sides === null) {
                $out['unanswered'][] = $typeId;
                continue;
            }

            if (self::isStale($sell ?? $buy, $staleBefore)) {
                $out['stale'][] = $typeId;
                $out['unanswered'][] = $typeId;
                $out['old_prices'][$typeId] = $sides;
                continue;
            }

            $out['prices'][$typeId] = $sides;
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

        // A region stays a region, even a hub's: Fuzzwork prices a hub at its
        // station, and whoever typed in a region id asked for all of it.
        if ($source === 'region' && (int) $setting->region_id > 0) {
            return (string) (int) $setting->region_id;
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
        return $this->managerCoreSourceFor($setting)['market'];
    }

    /**
     * Where and through what Manager Core prices this corporation's quotes:
     * its market, and the provider it named there or '' for the market's
     * own. An admin's preference for Buyback in Manager Core's Pricing
     * Preferences wins over both, and its provider override, if it has one,
     * is applied by Manager Core itself.
     *
     * @return array{market: string, provider: string, overridden: bool}
     */
    public function managerCoreSourceFor(BuybackSetting $setting): array
    {
        $pref = $this->managerCorePreference();
        if ($pref && ! empty($pref['admin_overridden']) && ! empty($pref['market'])) {
            return ['market' => (string) $pref['market'], 'provider' => '', 'overridden' => true];
        }

        return [
            'market' => (string) ($setting->manager_core_market ?: 'jita'),
            'provider' => self::providerKey($setting->manager_core_provider),
            'overridden' => false,
        ];
    }

    /**
     * The provider Manager Core reads at this market for this corporation,
     * or '' for the market's own. Only the corporation's own Manager Core
     * market reads through the provider it named: Manager Core as a fallback
     * at a hub, and the Jita fill, read the market's own, since nothing
     * subscribes the named provider there.
     */
    public function managerCoreProviderAt(BuybackSetting $setting, string $market): string
    {
        if ($this->providerOf($setting) !== self::PROVIDER_MANAGER_CORE || ! $this->managerCoreInstalled()) {
            return '';
        }

        $source = $this->managerCoreSourceFor($setting);

        return $source['market'] === $market ? $source['provider'] : '';
    }

    /**
     * Why the provider a corporation named for its Manager Core market cannot
     * price its quotes, or null when it can or none is named.
     */
    public function managerCoreProviderProblem(BuybackSetting $setting): ?string
    {
        $via = self::providerKey($setting->manager_core_provider);
        if ($via === '') {
            return null;
        }

        if (! $this->managerCoreInstalled()) {
            return ManagerCoreIntegration::unavailableReason() ?? 'Manager Core is not installed.';
        }

        $market = (string) ($setting->manager_core_market ?: 'jita');
        if (collect($this->mcMarkets())->firstWhere('key', $market) === null) {
            return "Manager Core has no enabled market '{$market}'.";
        }

        $choice = $this->managerCoreProviderChoice($market, $via);
        if ($choice === null) {
            return "Manager Core has no provider called '{$via}'.";
        }

        return $choice['ok'] ? null : $choice['reason'];
    }

    /**
     * What Manager Core can do at one of its markets through a provider, as
     * managerCoreMarkets() lists it, or null when it lists no such market or
     * provider.
     *
     * @return array{label: string, own: bool, ok: bool, reason: ?string}|null
     */
    public function managerCoreProviderChoice(string $market, string $provider): ?array
    {
        $entry = collect($this->mcMarkets())->firstWhere('key', $market);

        return $entry['providers'][self::providerKey($provider)] ?? null;
    }

    /** What Manager Core calls one of its providers. */
    public function managerCoreProviderLabel(string $provider): string
    {
        foreach ($this->mcMarkets() as $market) {
            if (isset($market['providers'][$provider]['label'])) {
                return (string) $market['providers'][$provider]['label'];
            }
        }

        return ucfirst($provider);
    }

    /** A provider key as Manager Core stores it. */
    protected static function providerKey(?string $provider): string
    {
        return strtolower(trim((string) $provider));
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
        $this->mcPreferenceFailed = false;

        if (! $this->managerCoreInstalled()) {
            return null;
        }

        try {
            $pref = $this->mcBridge()->call('ManagerCore', 'pricing.getPreferenceForPlugin', self::MC_PLUGIN_KEY);
            $this->mcPreference = is_array($pref) ? $pref : null;
        } catch (\Throwable $e) {
            $this->mcPreferenceFailed = true;
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
     * "Janice at Jita": who priced a quote and where, from what the
     * appraisal recorded. Quotes store the market by name.
     */
    public function sourceLabel(?string $provider, ?string $market): ?string
    {
        if (! $provider) {
            return null;
        }

        return $this->providerLabel($provider) . ($market ? ' at ' . $market : '');
    }

    /**
     * Where this corporation's next quote is priced, worked out the way the
     * quote itself works it out, so a page cannot name a market the quotes
     * do not use.
     */
    public function currentSourceLabel(BuybackSetting $setting): string
    {
        $provider = $this->providerOf($setting);
        $market = $this->marketFor($setting, $provider);

        return (string) $this->sourceLabel($provider, $this->pricedAtName($market, $this->managerCoreProviderAt($setting, $market)));
    }

    /**
     * Where a quote is priced, as people read it: the market's name, and the
     * provider Manager Core reads it through when the corporation named one,
     * "Jita (Janice)".
     */
    public function pricedAtName(string $market, string $via = ''): string
    {
        $name = $this->marketName($market);

        return $via === '' ? $name : $name . ' (' . $this->managerCoreProviderLabel($via) . ')';
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

            if ($name === null) {
                $name = array_column($this->mcMarkets(), 'label', 'key')[$market] ?? null;
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
                'description' => 'Needs a Janice API key. Prices at Jita, Amarr, Dodixie, Hek or Rens.',
                'requires_config' => true,
                'config_fields' => ['janice_api_key'],
                'available' => true,
            ],
            self::PROVIDER_MANAGER_CORE => [
                'name' => 'Manager Core',
                'description' => "Reads Manager Core's own prices, including citadel markets added there.",
                'requires_config' => false,
                'available' => $this->managerCoreInstalled(),
                'unavailable_label' => ManagerCoreIntegration::isTooOld()
                    ? 'needs ' . ManagerCoreIntegration::MINIMUM_VERSION . ' or later'
                    : 'not installed',
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
            $prices[(int) $row->type_id] = self::sides($buy, $sell, $buy > 0 ? (float) ($row->buy_percentile ?? 0) : 0.0);
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
            $sides = self::sides((float) ($row->buy_price ?? 0), (float) ($row->sell_price ?? 0), (float) ($row->buy_percentile ?? 0));
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

            // Seen with the buy price, so a provider that gives no average
            // clears an old one rather than leaving it to guard a new price.
            if ($sides['buy'] !== null) {
                $row['buy_percentile'] = $sides['buy_percentile'] ?? null;
                $columns[] = 'buy_percentile';
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
     * The items a quote for this corporation should wait on before it is
     * priced: those Manager Core holds no price for at the corporation's
     * market, whose first fetch this call has just asked for or which were
     * subscribed so recently that theirs may still be on the queue. An item
     * Manager Core was asked for long ago and still has no price for has no
     * orders to be found, and an old price will not be fetched again before
     * the next refresh, so neither is waited on: the fallback prices them.
     *
     * @param  int[]  $typeIds
     * @return int[]
     */
    public function managerCoreFirstFetches(BuybackSetting $setting, array $typeIds): array
    {
        if ($this->providerOf($setting) !== self::PROVIDER_MANAGER_CORE || ! $this->managerCoreInstalled()) {
            return [];
        }

        $market = $this->marketFor($setting, self::PROVIDER_MANAGER_CORE);
        $via = $this->managerCoreProviderAt($setting, $market);
        $missing = $this->managerCoreStillWithoutPrice($setting, $this->cleanIds($typeIds));
        if (empty($missing)) {
            return [];
        }

        $new = BuybackSubscribedType::missingFrom($missing, $market, $via);
        $this->subscribeToManagerCore($missing, $market, true, $via);
        // A subscription Manager Core refused stays out of the ledger, and
        // nothing is coming for it.
        $asked = array_diff($new, BuybackSubscribedType::missingFrom($new, $market, $via));

        $inFlight = BuybackSubscribedType::where('market', $market)
            ->where('provider', $via)
            ->whereIn('type_id', array_diff($missing, $new))
            ->where('subscribed_at', '>=', Carbon::now()->subSeconds(self::FIRST_FETCH_IN_FLIGHT_SECONDS))
            ->pluck('type_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return array_values(array_unique(array_merge(array_map('intval', $asked), $inFlight)));
    }

    /**
     * Of these items, the ones Manager Core holds no price for at all at
     * this corporation's market. An old price counts as a price here: it is
     * the fallback's to replace, not something to wait for.
     *
     * @param  int[]  $typeIds
     * @return int[]
     */
    public function managerCoreStillWithoutPrice(BuybackSetting $setting, array $typeIds): array
    {
        $typeIds = $this->cleanIds($typeIds);
        if (empty($typeIds) || ! $this->managerCoreInstalled()) {
            return [];
        }

        $market = $this->marketFor($setting, self::PROVIDER_MANAGER_CORE);

        try {
            // The quote that follows logs any old prices; a page checking
            // back every few seconds would log them on every check.
            $answer = $this->fetchFromManagerCore($market, $typeIds, $this->managerCoreProviderAt($setting, $market), false);
        } catch (\Throwable $e) {
            // Nothing to wait for from a Manager Core that does not answer.
            return [];
        }

        return array_values(array_diff($answer['unanswered'], $answer['stale'] ?? []));
    }

    /**
     * Whether a quote got no price at all because the providers did not
     * answer, as opposed to a list of items nobody trades: the provider
     * failed with no working fallback, or the fallback failed for what the
     * provider left.
     */
    public static function nothingAnswered(array $priced): bool
    {
        if (! empty($priced['prices'])) {
            return false;
        }

        $fallback = $priced['fallback'] ?? null;
        $fallbackFailed = is_array($fallback) && ($fallback['provider'] ?? null) !== null && empty($fallback['ok']);

        if (! empty($priced['stood_in'])) {
            return ! is_array($fallback) || ($fallback['provider'] ?? null) === null || $fallbackFailed;
        }

        return ! empty($priced['unanswered']) && $fallbackFailed;
    }

    /**
     * Ask Manager Core to keep these items priced at this market, through
     * the provider a corporation named there or, with $via '', the market's
     * own. Only items the local ledger has not seen reach the bridge, so a
     * busy quote page does not call it on every quote.
     *
     * @param  int[]  $typeIds
     * @return int  Count of newly subscribed items
     */
    public function subscribeToManagerCore(array $typeIds, string $market = 'jita', bool $immediateRefresh = false, string $via = ''): int
    {
        if (! $this->managerCoreInstalled()) {
            return 0;
        }

        try {
            $newTypeIds = BuybackSubscribedType::missingFrom($typeIds, $market, $via);
            if (empty($newTypeIds) || ! $this->askManagerCoreToKeep($newTypeIds, $market, $via, $immediateRefresh)) {
                return 0;
            }

            BuybackSubscribedType::markSubscribed($newTypeIds, $market, $via);

            Log::info('[Buyback Manager] Subscribed ' . count($newTypeIds) . " new types to Manager Core market '{$market}'" . $this->throughText($via));

            return count($newTypeIds);
        } catch (\Throwable $e) {
            Log::warning('[Buyback Manager] Manager Core subscribe failed: ' . $e->getMessage());

            return 0;
        }
    }

    /**
     * One subscribe call. Prices through a provider Manager Core refuses to
     * keep (one it does not know, or one that cannot price the market's
     * place) are not taken as subscribed, so they are asked for again.
     */
    protected function askManagerCoreToKeep(array $typeIds, string $market, string $via, bool $immediateRefresh): bool
    {
        if ($via === '') {
            $this->mcBridge()->call('ManagerCore', 'pricing.subscribeTypes', self::MC_PLUGIN_KEY, $typeIds, $market, 1, $immediateRefresh);

            return true;
        }

        $answer = $this->mcBridge()->call('ManagerCore', 'pricing.subscribeVariants', self::MC_PLUGIN_KEY, $typeIds, $market, $via, 1, $immediateRefresh);
        if (! is_array($answer) || ($answer['reason'] ?? null) !== null) {
            $why = is_array($answer) ? ($answer['place_reason'] ?? $answer['reason']) : 'no answer';
            Log::warning("[Buyback Manager] Manager Core would not keep prices at '{$market}'" . $this->throughText($via) . ': ' . $why);

            return false;
        }

        return true;
    }

    /**
     * Drop every subscription Buyback holds in Manager Core, at every market
     * and through every provider, including any the ledger has lost track of
     * and the ones Manager Core keeps for an admin's provider override. For
     * when no corporation prices through Manager Core any more.
     */
    public function unsubscribeFromManagerCore(): int
    {
        if (! $this->managerCoreInstalled()) {
            return 0;
        }

        try {
            $count = (int) $this->mcBridge()->call('ManagerCore', 'pricing.unsubscribeTypes', self::MC_PLUGIN_KEY, null);
            $count += (int) $this->mcBridge()->call('ManagerCore', 'pricing.unsubscribeVariants', self::MC_PLUGIN_KEY);
            BuybackSubscribedType::query()->delete();
            Log::info('[Buyback Manager] Unsubscribed all types from Manager Core (count=' . $count . ')');

            return $count;
        } catch (\Throwable $e) {
            Log::warning('[Buyback Manager] Manager Core unsubscribe failed: ' . $e->getMessage());

            return 0;
        }
    }

    /**
     * Where enabled corporations price through Manager Core, as Buyback asks
     * for it, so with an admin's override in force: each market with the
     * provider read there, '' for the market's own.
     *
     * @return array<string, array{market: string, provider: string}> keyed as sourceKey() names them
     */
    public function managerCoreSourcesInUse(): array
    {
        $sources = [];
        $settings = BuybackSetting::where('enabled', true)
            ->where('price_provider', self::PROVIDER_MANAGER_CORE)
            ->get();

        foreach ($settings as $setting) {
            $source = $this->managerCoreSourceFor($setting);
            $sources[self::sourceKey($source['market'], $source['provider'])] = ['market' => $source['market'], 'provider' => $source['provider']];
        }

        return $sources;
    }

    /**
     * A market, or a market and the provider read there written the way
     * Manager Core writes a variant: "jita@janice".
     */
    public static function sourceKey(string $market, string $provider): string
    {
        return $provider === '' ? $market : $market . '@' . $provider;
    }

    /**
     * Unsubscribe what no enabled corporation prices through Manager Core any
     * more, one market and provider at a time, so what another corporation
     * still uses keeps its items. Run when an operator changes a programme
     * rather than on a schedule, so a subscription is only ever dropped
     * because someone moved off it.
     *
     * @return string[] what was dropped, as sourceKey() names it
     */
    public function dropUnusedManagerCoreMarkets(): array
    {
        if (! $this->managerCoreInstalled()) {
            return [];
        }

        $inUse = $this->managerCoreSourcesInUse();
        $known = [];
        foreach (BuybackSubscribedType::query()->select(['market', 'provider'])->distinct()->get() as $row) {
            $known[self::sourceKey((string) $row->market, (string) $row->provider)] = ['market' => (string) $row->market, 'provider' => (string) $row->provider];
        }

        if (empty($inUse)) {
            $this->unsubscribeFromManagerCore();

            return array_keys($known);
        }

        // Without the preference, an admin's market reads as unused.
        if ($this->mcPreferenceFailed) {
            Log::info('[Buyback Manager] Left Manager Core subscriptions as they are, since the pricing preference could not be read');

            return [];
        }

        $dropped = [];
        foreach (array_diff_key($known, $inUse) as $key => $source) {
            try {
                if ($source['provider'] === '') {
                    $this->mcBridge()->call('ManagerCore', 'pricing.unsubscribeTypes', self::MC_PLUGIN_KEY, $source['market']);
                } else {
                    $this->mcBridge()->call('ManagerCore', 'pricing.unsubscribeVariants', self::MC_PLUGIN_KEY, $source['market'], $source['provider']);
                }

                BuybackSubscribedType::where('market', $source['market'])->where('provider', $source['provider'])->delete();
                $dropped[] = $key;
                Log::info("[Buyback Manager] Unsubscribed Manager Core market '{$source['market']}'" . $this->throughText($source['provider']) . ', which no corporation prices at any more');
            } catch (\Throwable $e) {
                Log::warning("[Buyback Manager] Manager Core unsubscribe at '{$key}' failed: " . $e->getMessage());
            }
        }

        return $dropped;
    }

    /** " through Janice" for a provider a corporation named, nothing for the market's own. */
    protected function throughText(string $via): string
    {
        return $via === '' ? '' : ' through ' . $this->managerCoreProviderLabel($via);
    }

    /**
     * Subscribe the items a corporation's rules name, so the first quote for
     * one is Manager Core's price and not the fallback's. A group rule stands
     * for every published item in it. A category rule is left to the quotes,
     * since one can hold thousands of items, and so is an exclusion, which is
     * never priced. A refining rule, whatever it covers, names the materials
     * its items refine into instead of the items, since those are what a
     * quote prices.
     *
     * @return int items newly subscribed
     */
    public function subscribeRuleItems(BuybackSetting $setting): int
    {
        if (! $setting->enabled
            || $this->providerOf($setting) !== self::PROVIDER_MANAGER_CORE
            || ! $this->managerCoreInstalled()) {
            return 0;
        }

        try {
            $rules = BuybackPricingRule::where('setting_id', $setting->id)
                ->where('excluded', false)
                ->whereIn('type', ['item', 'group'])
                ->where('refined', false)
                ->get(['type', 'type_id']);

            $typeIds = $rules->where('type', 'item')->pluck('type_id')->all();
            $groupIds = $rules->where('type', 'group')->pluck('type_id')->all();

            if (! empty($groupIds)) {
                $typeIds = array_merge($typeIds, DB::table('invTypes')
                    ->whereIn('groupID', $groupIds)
                    ->where('published', 1)
                    ->pluck('typeID')
                    ->all());
            }

            $typeIds = array_merge($typeIds, (new Reprocessing())->ruleMaterials($setting));
        } catch (\Throwable $e) {
            Log::warning('[Buyback Manager] Could not read the rule items to subscribe: ' . $e->getMessage());

            return 0;
        }

        $source = $this->managerCoreSourceFor($setting);

        return $this->subscribeToManagerCore($this->cleanIds($typeIds), $source['market'], true, $source['provider']);
    }

    /**
     * Send Manager Core every item in the ledger again, once a day per item.
     * The ledger only stops Buyback asking twice. When Manager Core loses a
     * subscription (an admin deleted it, a reinstall, another plugin's
     * unsubscribe), nothing would ever send it again. Manager Core keeps what
     * it already has and fetches only what has no price, so this is cheap.
     *
     * @return int items sent
     */
    public function resendManagerCoreLedger(): int
    {
        if (! $this->managerCoreInstalled()) {
            return 0;
        }

        $due = Carbon::now()->subHours(self::LEDGER_RESEND_HOURS);
        $sent = 0;

        foreach ($this->managerCoreSourcesInUse() as $key => ['market' => $market, 'provider' => $via]) {
            try {
                BuybackSubscribedType::where('market', $market)
                    ->where('provider', $via)
                    ->where(function ($query) use ($due) {
                        $query->whereNull('subscribed_at')->orWhere('subscribed_at', '<', $due);
                    })
                    ->chunkById(self::LEDGER_RESEND_BATCH, function ($rows) use ($market, $via, &$sent) {
                        $typeIds = $rows->pluck('type_id')->map(fn ($id) => (int) $id)->all();
                        if (! $this->askManagerCoreToKeep($typeIds, $market, $via, true)) {
                            return false;
                        }

                        BuybackSubscribedType::whereIn('id', $rows->pluck('id')->all())->update(['subscribed_at' => Carbon::now()]);
                        $sent += count($typeIds);
                    });
            } catch (\Throwable $e) {
                // What was not stamped goes again on the next sync.
                Log::warning("[Buyback Manager] Resending the Manager Core ledger at '{$key}' failed: " . $e->getMessage());
            }
        }

        if ($sent > 0) {
            Log::info("[Buyback Manager] Sent {$sent} ledger items to Manager Core again");
        }

        return $sent;
    }

    /**
     * The sync cycle's share of the subscription upkeep: what the rules name,
     * then the daily resend.
     *
     * @return array{from_rules: int, resent: int}
     */
    public function maintainManagerCoreSubscriptions(): array
    {
        $done = ['from_rules' => 0, 'resent' => 0];
        if (! $this->managerCoreInstalled()) {
            return $done;
        }

        $settings = BuybackSetting::where('enabled', true)
            ->where('price_provider', self::PROVIDER_MANAGER_CORE)
            ->get();
        foreach ($settings as $setting) {
            $done['from_rules'] += $this->subscribeRuleItems($setting);
        }

        $done['resent'] = $this->resendManagerCoreLedger();

        return $done;
    }

    /**
     * Manager Core's markets for the settings form, enabled ones only. Each
     * lists what every provider could do there for a corporation that names
     * one: ok when it is set up in Manager Core and prices the market's own
     * place, with the reason when not.
     *
     * @return array<int, array{key: string, label: string, type: string, note: ?string,
     *     providers: array<string, array{label: string, own: bool, ok: bool, reason: ?string}>}>
     */
    public function managerCoreMarkets(): array
    {
        if (! $this->managerCoreInstalled()) {
            return [];
        }

        try {
            $markets = $this->mcBridge()->call('ManagerCore', 'pricing.listMarkets', true);

            return collect(is_array($markets) ? $markets : [])
                ->filter(fn ($market) => is_array($market) && ! empty($market['key']))
                ->map(fn (array $market) => [
                    'key' => (string) $market['key'],
                    'label' => (string) ($market['name'] ?? $market['key']),
                    'type' => (string) ($market['market_type'] ?? 'hub'),
                    // Fuzzwork and Janice price a market they do not
                    // track at Jita 4-4 without saying so.
                    'note' => ($market['prices_own_place'] ?? true) ? null : ($market['place_reason'] ?? null),
                    'providers' => self::providerChoices(is_array($market['providers'] ?? null) ? $market['providers'] : []),
                ])
                ->sortBy(fn (array $market) => $market['type'] . '|' . $market['label'])
                ->values()
                ->all();
        } catch (\Throwable $e) {
            Log::warning('[Buyback Manager] Could not list Manager Core markets: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * Manager Core's markets, read again after a minute like the preference:
     * the service lives as long as a queue worker does, and an admin adds
     * markets and sets providers up in the meantime.
     */
    protected function mcMarkets(): array
    {
        if ($this->mcMarketList === null || time() - (int) $this->mcMarketListReadAt >= self::MC_PREFERENCE_SECONDS) {
            $this->mcMarketList = $this->managerCoreMarkets();
            $this->mcMarketListReadAt = time();
        }

        return $this->mcMarketList;
    }

    /**
     * Manager Core's plugin bridge, or null without it. Every call names its
     * capability where it is made, so the manifest check can read them.
     */
    protected function mcBridge(): ?object
    {
        return ManagerCoreIntegration::bridge();
    }

    // ============================================================
    // HELPERS
    // ============================================================

    /**
     * Both sides of an answer, null for a side with no orders, or null when
     * neither side has a price. buy_percentile, the average of the top 5% of
     * buy orders, is there only with a buy price from Fuzzwork, asked
     * directly or through Manager Core.
     *
     * @return array{buy: ?float, sell: ?float, buy_percentile?: float}|null
     */
    public static function sides(float $buy, float $sell, float $buyPercentile = 0.0): ?array
    {
        if ($buy <= 0 && $sell <= 0) {
            return null;
        }

        $sides = [
            'buy' => $buy > 0 ? $buy : null,
            'sell' => $sell > 0 ? $sell : null,
        ];
        if ($buy > 0 && $buyPercentile > 0) {
            $sides['buy_percentile'] = $buyPercentile;
        }

        return $sides;
    }

    /**
     * listMarkets' providers at one market, as the settings form offers them.
     *
     * @return array<string, array{label: string, own: bool, ok: bool, reason: ?string}>
     */
    public static function providerChoices(array $providers): array
    {
        $choices = [];
        foreach ($providers as $key => $provider) {
            if (! is_string($key) || $key === '' || ! is_array($provider)) {
                continue;
            }

            $label = (string) ($provider['label'] ?? ucfirst($key));
            $reason = null;
            if (empty($provider['prices_place'])) {
                $reason = (string) ($provider['place_reason'] ?? "{$label} cannot price this market.");
            } elseif (empty($provider['available'])) {
                $reason = "{$label} is not set up in Manager Core.";
            }

            $choices[self::providerKey($key)] = [
                'label' => $label,
                'own' => ! empty($provider['own']),
                'ok' => $reason === null,
                'reason' => $reason,
            ];
        }

        return $choices;
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

    /** Whether Fuzzwork gave Manager Core these figures, by the strategy it stored them under. */
    protected static function fromFuzzwork(?array $stats): bool
    {
        return strtolower(trim((string) ($stats['strategy'] ?? ''))) === 'fuzzwork';
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
