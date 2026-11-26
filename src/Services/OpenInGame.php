<?php

namespace BuybackManager\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Whether "Open in game" can work, and for whom.
 *
 * The button needs esi-ui.open_window.v1 on the login of the character it
 * opens the contract for. Buyback Manager adds the scope to the list SeAT
 * offers, but only an admin can add it to a scope profile, and a character
 * only picks it up by logging in again with that profile. SeAT keeps the
 * scopes a character logged in with, and a character linked while signed in
 * gets the scopes of the account's main, so a member logs out and back in
 * with their main first and links any alt again after.
 */
class OpenInGame
{
    public const SCOPE = 'esi-ui.open_window.v1';

    /** Whether SeAT lists the scope, so a profile can ask for it. */
    public function scopeListed(): bool
    {
        return in_array(self::SCOPE, (array) config('eveapi.scopes', []), true);
    }

    /**
     * SeAT's default scope profile, the one a first login uses, and whether
     * it asks for the scope. Null when no profile is marked default or the
     * profiles could not be read.
     *
     * @return array{name: string, asks: bool}|null
     */
    public function defaultProfile(): ?array
    {
        $profiles = $this->profiles();

        if (empty($profiles)) {
            // SeAT falls back to a built-in profile when none is saved, so an
            // empty answer means the profiles could not be read. Saying the
            // scope is asked for then would hide a real problem.
            return null;
        }

        foreach ((array) $profiles as $profile) {
            $profile = (object) $profile;
            if (! empty($profile->default)) {
                return [
                    'name' => (string) ($profile->name ?? 'default'),
                    'asks' => in_array(self::SCOPE, (array) ($profile->scopes ?? []), true),
                ];
            }
        }

        return null;
    }

    /**
     * SeAT's scope profiles as its setting() helper reads them: the saved
     * ones, or its built-in default when none is saved.
     */
    protected function profiles(): mixed
    {
        try {
            return function_exists('setting') ? setting('sso_scopes', true) : null;
        } catch (\Throwable $e) {
            Log::warning('[Buyback Manager] Could not read SeAT\'s scope profiles: ' . $e->getMessage());

            return null;
        }
    }

    public function defaultProfileAsks(): bool
    {
        return (bool) ($this->defaultProfile()['asks'] ?? false);
    }

    /** How many characters' logins hold the scope now. */
    public function charactersWithScope(): int
    {
        try {
            return $this->withScope(DB::table('refresh_tokens'))->count();
        } catch (\Throwable $e) {
            Log::warning('[Buyback Manager] Could not count logins with ' . self::SCOPE . ': ' . $e->getMessage());

            return 0;
        }
    }

    /**
     * The account's characters whose login holds the scope, main first.
     *
     * @return array<int, string>  character_id => name
     */
    public function charactersFor(?int $userId): array
    {
        if ($userId === null) {
            return [];
        }

        try {
            $mainId = (int) (DB::table('users')->where('id', $userId)->value('main_character_id') ?? 0);

            return $this->withScope(DB::table('refresh_tokens'))
                ->leftJoin('character_infos', 'character_infos.character_id', '=', 'refresh_tokens.character_id')
                ->where('refresh_tokens.user_id', $userId)
                ->orderByRaw('refresh_tokens.character_id = ? DESC', [$mainId])
                ->orderBy('character_infos.name')
                ->get(['refresh_tokens.character_id', 'character_infos.name'])
                ->mapWithKeys(fn ($row) => [(int) $row->character_id => (string) ($row->name ?? ('Character #' . $row->character_id))])
                ->all();
        } catch (\Throwable $e) {
            Log::warning('[Buyback Manager] Could not read the logins of user ' . $userId . ': ' . $e->getMessage());

            return [];
        }
    }

    /**
     * Where the feature stands, for Settings and Diagnostics.
     *
     * @return array{listed: bool, profile: ?array{name: string, asks: bool}, characters: int}
     */
    public function status(): array
    {
        return [
            'listed' => $this->scopeListed(),
            'profile' => $this->defaultProfile(),
            'characters' => $this->charactersWithScope(),
        ];
    }

    /**
     * Live logins holding the scope. SeAT stores a login's scopes as a JSON
     * list, and a quoted match finds the one scope and no other.
     */
    protected function withScope($query)
    {
        return $query
            ->whereNull('refresh_tokens.deleted_at')
            ->where('refresh_tokens.scopes', 'like', '%"' . self::SCOPE . '"%');
    }
}
