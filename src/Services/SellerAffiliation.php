<?php

namespace BuybackManager\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The alliance a contract's seller sold from.
 *
 * The contract already says which corporation it was issued from, so only
 * that corporation's alliance is looked up: first in what SeAT holds, then
 * on EveWho, then on zKillboard. A source that has the seller in another
 * corporation than the contract's is passed over, since they have moved
 * since and their alliance now says nothing about the one they sold from.
 */
class SellerAffiliation
{
    public const EVEWHO_URL = 'https://evewho.com/api/character/%d';

    public const ZKILLBOARD_URL = 'https://zkillboard.com/api/stats/characterID/%d/';

    public const SOURCE_SEAT = 'SeAT';

    public const SOURCE_EVEWHO = 'EveWho';

    public const SOURCE_ZKILLBOARD = 'zKillboard';

    /** Contract sync runs in a job, and a seller lookup must not hold it long. */
    private const HTTP_TIMEOUT_SECONDS = 6;

    /** An answer found online is kept this long, so one seller's contracts ask once. */
    private const REMEMBER_SECONDS = 21600;

    private const CACHE_KEY = 'buyback-manager:seller-alliance:';

    private const USER_AGENT = 'Buyback Manager (SeAT plugin)';

    /**
     * Sources that failed in this run. One sync can meet many new sellers,
     * and a site that is down would otherwise cost a full timeout on each.
     *
     * @var array<string, true>
     */
    private array $down = [];

    /**
     * @return array{alliance_id: ?int, source: string}|null  alliance_id null
     *         for a corporation in no alliance; null when no source could tell
     */
    public function allianceOf(int $characterId, int $corporationId): ?array
    {
        if ($characterId <= 0 || $corporationId <= 0) {
            return null;
        }

        return $this->fromSeat($characterId, $corporationId)
            ?? $this->online($characterId, $corporationId);
    }

    /**
     * @return array{alliance_id: ?int, source: string}|null
     */
    protected function fromSeat(int $characterId, int $corporationId): ?array
    {
        $affiliation = DB::table('character_affiliations')
            ->where('character_id', $characterId)
            ->first(['corporation_id', 'alliance_id']);
        if ($affiliation !== null && (int) $affiliation->corporation_id === $corporationId) {
            return ['alliance_id' => $affiliation->alliance_id ? (int) $affiliation->alliance_id : null, 'source' => self::SOURCE_SEAT];
        }

        // SeAT pulls every alliance's member corporations, so a corporation
        // in one is found here even for a seller SeAT has never seen. One in
        // none may have no alliance, or may have joined since SeAT's last
        // pull, and only a source online can tell those apart.
        $allianceId = DB::table('alliance_members')->where('corporation_id', $corporationId)->value('alliance_id');

        return $allianceId ? ['alliance_id' => (int) $allianceId, 'source' => self::SOURCE_SEAT] : null;
    }

    /**
     * @return array{alliance_id: ?int, source: string}|null
     */
    protected function online(int $characterId, int $corporationId): ?array
    {
        $key = self::CACHE_KEY . $characterId . ':' . $corporationId;
        $remembered = Cache::get($key);
        if (is_array($remembered)) {
            return $remembered;
        }

        $sources = [
            self::SOURCE_EVEWHO => fn () => $this->evewho($characterId),
            self::SOURCE_ZKILLBOARD => fn () => $this->zkillboard($characterId),
        ];
        foreach ($sources as $source => $ask) {
            if (isset($this->down[$source])) {
                continue;
            }

            $found = $ask();
            if ($found !== null && $found['corporation_id'] === $corporationId) {
                $answer = ['alliance_id' => $found['alliance_id'] > 0 ? $found['alliance_id'] : null, 'source' => $source];
                Cache::put($key, $answer, self::REMEMBER_SECONDS);

                return $answer;
            }
        }

        return null;
    }

    /**
     * @return array{corporation_id: int, alliance_id: int}|null
     */
    protected function evewho(int $characterId): ?array
    {
        $info = $this->fetch(sprintf(self::EVEWHO_URL, $characterId), self::SOURCE_EVEWHO)['info'][0] ?? null;

        return is_array($info) && ! empty($info['corporation_id'])
            ? ['corporation_id' => (int) $info['corporation_id'], 'alliance_id' => (int) ($info['alliance_id'] ?? 0)]
            : null;
    }

    /**
     * @return array{corporation_id: int, alliance_id: int}|null
     */
    protected function zkillboard(int $characterId): ?array
    {
        $info = $this->fetch(sprintf(self::ZKILLBOARD_URL, $characterId), self::SOURCE_ZKILLBOARD)['info'] ?? null;

        return is_array($info) && ! empty($info['corporationID'])
            ? ['corporation_id' => (int) $info['corporationID'], 'alliance_id' => (int) ($info['allianceID'] ?? 0)]
            : null;
    }

    /**
     * @return array<mixed>|null
     */
    protected function fetch(string $url, string $source): ?array
    {
        try {
            $response = Http::timeout(self::HTTP_TIMEOUT_SECONDS)
                ->withUserAgent(self::USER_AGENT)
                ->withHeaders(['Accept-Encoding' => 'gzip'])
                ->acceptJson()
                ->get($url);
        } catch (\Throwable $e) {
            $this->down[$source] = true;
            Log::warning('[Buyback Manager] ' . $source . ' did not answer a seller lookup, so it is not asked again this run: ' . $e->getMessage());

            return null;
        }

        if (! $response->successful()) {
            // A missing character is an answer about that character; a server
            // error or a refusal is about the site, and the rest of the run
            // goes without it.
            if ($response->serverError() || $response->status() === 429) {
                $this->down[$source] = true;
            }
            Log::info('[Buyback Manager] ' . $source . ' returned HTTP ' . $response->status() . ' for a seller lookup');

            return null;
        }

        $data = $response->json();

        return is_array($data) ? $data : null;
    }
}
