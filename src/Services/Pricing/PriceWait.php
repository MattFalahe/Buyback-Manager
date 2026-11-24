<?php

namespace BuybackManager\Services\Pricing;

use BuybackManager\Models\BuybackSetting;
use BuybackManager\Services\Parsing\PasteParserService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Holds a quote back for a few seconds while Manager Core fetches the items
 * it had no price for, so they are priced by the provider the corporation
 * picked and not by its fallback. The page calls start() before it asks for
 * the quote and check() every couple of seconds after, and asks for the
 * quote once it is told to go ahead. The quote itself is made as before.
 *
 * Nothing here prices or stores a quote. Any failure means no wait: a slow
 * or broken Manager Core costs the seller seconds, never the quote.
 */
class PriceWait
{
    /** The longest a quote waits before the fallback prices what is still missing. */
    public const WAIT_SECONDS = 20;

    /** How often the page checks back. */
    public const POLL_SECONDS = 2;

    /** The answer to a check on a wait that is over, unknown or not this corporation's. */
    public const GO_AHEAD = ['waiting' => 0, 'done' => true];

    private const KEY = 'buyback-manager:price-wait:';

    protected PriceProviderService $prices;

    protected PasteParserService $parser;

    protected Reprocessing $reprocessing;

    public function __construct(PriceProviderService $prices, PasteParserService $parser, ?Reprocessing $reprocessing = null)
    {
        $this->prices = $prices;
        $this->parser = $parser;
        $this->reprocessing = $reprocessing ?? new Reprocessing();
    }

    /**
     * Ask Manager Core for the pasted items it has no price for yet, and say
     * how many the quote waits on.
     *
     * @return array{waiting: int, token: ?string, seconds: int, poll: int}
     */
    public function start(BuybackSetting $setting, string $rawInput): array
    {
        try {
            if (! $setting->enabled) {
                return $this->nothingToWaitFor();
            }

            $items = $this->parser->parse($rawInput)['items'] ?? [];

            // What the quote will price: a refined item's materials, not the item.
            return $this->startFor($setting, Reprocessing::typesToPrice(
                array_column($items, 'type_id'),
                $this->reprocessing->recipesForPaste($items, $setting)
            ));
        } catch (\Throwable $e) {
            Log::warning('[Buyback Manager] Could not wait for Manager Core prices: ' . $e->getMessage());

            return $this->nothingToWaitFor();
        }
    }

    /**
     * @param  int[]  $typeIds
     * @return array{waiting: int, token: ?string, seconds: int, poll: int}
     */
    public function startFor(BuybackSetting $setting, array $typeIds): array
    {
        $pending = $this->prices->managerCoreFirstFetches($setting, $typeIds);
        if (empty($pending)) {
            return $this->nothingToWaitFor();
        }

        $token = Str::random(32);
        $this->keep($token, [
            'setting_id' => (int) $setting->id,
            'type_ids' => $pending,
            'until' => Carbon::now()->getTimestamp() + self::WAIT_SECONDS,
            'arrived' => false,
        ]);

        return $this->answer(count($pending), $token);
    }

    /**
     * Whether the quote can go ahead: every item has its price, or the fetch
     * came back without the rest, or the wait is over. Manager Core fetches
     * a batch in one job, so once some items have arrived and a check brings
     * no more, the rest have no orders to be found and waiting is pointless.
     *
     * @return array{waiting: int, done: bool}
     */
    public function check(BuybackSetting $setting, string $token): array
    {
        try {
            $state = Cache::get(self::KEY . $token);
            if (! is_array($state) || (int) ($state['setting_id'] ?? 0) !== (int) $setting->id) {
                return self::GO_AHEAD;
            }

            $before = array_map('intval', (array) ($state['type_ids'] ?? []));
            $left = $this->prices->managerCoreStillWithoutPrice($setting, $before);
            $arrivedNow = count($left) < count($before);

            $done = empty($left)
                || Carbon::now()->getTimestamp() >= (int) ($state['until'] ?? 0)
                || (! $arrivedNow && ! empty($state['arrived']));

            if ($done) {
                Cache::forget(self::KEY . $token);
            } else {
                $this->keep($token, ['type_ids' => $left, 'arrived' => ! empty($state['arrived']) || $arrivedNow] + $state);
            }

            return ['waiting' => $done ? 0 : count($left), 'done' => $done];
        } catch (\Throwable $e) {
            Log::warning('[Buyback Manager] Could not check on Manager Core prices: ' . $e->getMessage());

            return self::GO_AHEAD;
        }
    }

    protected function keep(string $token, array $state): void
    {
        Cache::put(self::KEY . $token, $state, self::WAIT_SECONDS + 60);
    }

    /** @return array{waiting: int, token: ?string, seconds: int, poll: int} */
    public function nothingToWaitFor(): array
    {
        return $this->answer(0, null);
    }

    /** @return array{waiting: int, token: ?string, seconds: int, poll: int} */
    protected function answer(int $waiting, ?string $token): array
    {
        return ['waiting' => $waiting, 'token' => $token, 'seconds' => self::WAIT_SECONDS, 'poll' => self::POLL_SECONDS];
    }
}
