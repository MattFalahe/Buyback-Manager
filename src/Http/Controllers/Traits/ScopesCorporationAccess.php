<?php

namespace BuybackManager\Http\Controllers\Traits;

use BuybackManager\Models\BuybackSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Shared corporation visibility rules for the director-facing pages.
 *
 * Every screen that reports on buyback data has to answer the same question
 * the same way — "which corporations may this user see?" — so the rule lives
 * here rather than being reimplemented per controller, where the copies would
 * eventually disagree and leak.
 */
trait ScopesCorporationAccess
{
    /**
     * Corporation IDs this user may see buyback data for, or null when they
     * hold the settings permission and should see everything.
     */
    protected function allowedCorporationIds(Request $request): ?array
    {
        $user = $request->user();

        if ($user && $user->can('buyback-manager.settings')) {
            return null;
        }

        if (! $user) {
            return [];
        }

        return DB::table('refresh_tokens')
            ->join('character_affiliations', 'refresh_tokens.character_id', '=', 'character_affiliations.character_id')
            ->where('refresh_tokens.user_id', $user->id)
            ->whereNull('refresh_tokens.deleted_at')
            ->pluck('character_affiliations.corporation_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Corporations offered in a filter dropdown, limited to what the user
     * may see.
     */
    protected function corporationsPicker(?array $allowedCorpIds)
    {
        $query = BuybackSetting::with('corporation')->where('enabled', true);
        if ($allowedCorpIds !== null) {
            $query->whereIn('corporation_id', $allowedCorpIds);
        }

        return $query->get()->pluck('corporation.name', 'corporation_id');
    }

    /**
     * Resolve the corporation filter for a report, throwing a 403 when the
     * requested corporation is outside the user's scope. Returns the
     * corporation id to filter on, or null for "everything in scope".
     */
    protected function resolveCorporationFilter(Request $request, ?array $allowedCorpIds): ?int
    {
        $corporationId = $request->get('corporation_id');
        if (! $corporationId) {
            return null;
        }

        if ($allowedCorpIds !== null && ! in_array((int) $corporationId, $allowedCorpIds, true)) {
            abort(403, 'You do not have access to this corporation.');
        }

        return (int) $corporationId;
    }
}
