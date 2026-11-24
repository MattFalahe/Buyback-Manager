<?php

namespace BuybackManager\Services;

use BuybackManager\Models\BuybackSetting;
use BuybackManager\Models\BuybackSyncStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Whether SeAT can still give a programme its contracts.
 *
 * Buyback Manager reads SeAT's contract tables, and SeAT fills them only
 * while it holds a usable token for the target: the designated character's
 * own token for a player, any member's for a corporation. When that token is
 * removed or loses its contract scope, SeAT stops updating, the sync keeps
 * finishing cleanly on what is left, and every new contract goes unseen.
 * Nothing else would say so.
 */
class ContractFeedHealth
{
    public const CHARACTER_SCOPE = 'esi-contracts.read_character_contracts.v1';

    public const CORPORATION_SCOPE = 'esi-contracts.read_corporation_contracts.v1';

    /**
     * Syncs in a row that must fail before the feed counts as broken. One
     * failed run is usually a database or queue hiccup that the next run
     * outlives; three is 45 minutes of contracts not read.
     */
    public const FAILED_RUNS = 3;

    /**
     * What stands between the programme and its contracts, or null when
     * nothing does. A programme with no feed to read (an outside corporation
     * typed by name) has nothing that can break, so it reports nothing.
     *
     * @return array{reason: string, advice: string}|null
     */
    public function problem(BuybackSetting $setting, ?BuybackSyncStatus $status = null): ?array
    {
        $source = $setting->resolveSyncSource();
        if ($source === null) {
            return null;
        }

        $problem = $source['type'] === 'character'
            ? $this->characterProblem((int) $source['feed_id'])
            : $this->corporationProblem((int) $source['feed_id']);

        if ($problem === null && $status !== null && (int) $status->failures >= self::FAILED_RUNS) {
            $problem = [
                'reason' => 'The last ' . (int) $status->failures . ' contract syncs failed: ' . Str::limit((string) $status->last_error, 300),
                'advice' => 'The Diagnostics page shows the error. Contracts are read again on the first sync that gets through.',
            ];
        }

        return $problem;
    }

    /**
     * @return array{reason: string, advice: string}|null
     */
    protected function characterProblem(int $characterId): ?array
    {
        $name = DB::table('character_infos')->where('character_id', $characterId)->value('name') ?? ('Character #' . $characterId);
        $token = DB::table('refresh_tokens')
            ->where('character_id', $characterId)
            ->whereNull('deleted_at')
            ->first(['scopes']);

        if ($token === null) {
            return [
                'reason' => 'SeAT holds no token for ' . $name . ', so it cannot read the contracts made to them.',
                'advice' => 'Log ' . $name . ' in to SeAT with the ' . self::CHARACTER_SCOPE . ' scope, or pick another contract target.',
            ];
        }

        if (! in_array(self::CHARACTER_SCOPE, $this->scopes($token->scopes), true)) {
            return [
                'reason' => $name . '\'s SeAT token does not carry ' . self::CHARACTER_SCOPE . ', so SeAT does not read their contracts.',
                'advice' => 'Log ' . $name . ' in to SeAT again with the contracts scope.',
            ];
        }

        return null;
    }

    /**
     * @return array{reason: string, advice: string}|null
     */
    protected function corporationProblem(int $corporationId): ?array
    {
        $holders = DB::table('refresh_tokens')
            ->join('character_affiliations', 'character_affiliations.character_id', '=', 'refresh_tokens.character_id')
            ->where('character_affiliations.corporation_id', $corporationId)
            ->whereNull('refresh_tokens.deleted_at')
            ->where('refresh_tokens.scopes', 'like', '%"' . self::CORPORATION_SCOPE . '"%')
            ->count();

        if ($holders > 0) {
            return null;
        }

        $name = DB::table('corporation_infos')->where('corporation_id', $corporationId)->value('name') ?? ('Corporation #' . $corporationId);

        return [
            'reason' => 'No member of ' . $name . ' has a SeAT token with ' . self::CORPORATION_SCOPE . ', so SeAT does not read its contracts.',
            'advice' => 'A member of ' . $name . ' needs to log in to SeAT with the corporation contracts scope.',
        ];
    }

    /**
     * SeAT keeps scopes as a JSON array.
     *
     * @return array<int, string>
     */
    protected function scopes(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }

        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
