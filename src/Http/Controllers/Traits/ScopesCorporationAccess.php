<?php

namespace BuybackManager\Http\Controllers\Traits;

use BuybackManager\Models\BuybackProgrammeAccess;
use BuybackManager\Models\BuybackSetting;
use BuybackManager\Services\ProgrammeAccess;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Shared corporation visibility rules for the director-facing pages.
 *
 * Every screen that reports on buyback data, and every action that changes a
 * programme, has to answer the same question the same way — "which
 * corporations is this user for?" — so the rule lives here rather than being
 * reimplemented per controller, where the copies would eventually disagree
 * and leak.
 *
 * buyback-manager.admin covers every corporation. Everyone else reaches
 * only the programmes an admin named them on, as an account or through a
 * SeAT role: a manager's with buyback-manager.settings, a viewer's with
 * buyback-manager.view. A character in the corporation counts for nothing,
 * since a member of one corporation may run a buyback under another.
 */
trait ScopesCorporationAccess
{
    /** @var int[]|null */
    private ?array $managedCorporationIds = null;

    /** @var int[]|null */
    private ?array $visibleCorporationIds = null;

    /** Whether this user acts for every corporation. */
    protected function managesEveryCorporation(Request $request): bool
    {
        $user = $request->user();

        return $user !== null && $user->can('buyback-manager.admin');
    }

    /**
     * Corporation IDs whose programme this user may see the contracts and
     * figures of, or null when they hold the admin permission and should
     * see everything. A manager sees what they manage.
     */
    protected function allowedCorporationIds(Request $request): ?array
    {
        if ($this->managesEveryCorporation($request)) {
            return null;
        }

        if ($this->visibleCorporationIds !== null) {
            return $this->visibleCorporationIds;
        }

        $user = $request->user();
        $viewed = $user !== null && $user->can(ProgrammeAccess::NEEDS[BuybackProgrammeAccess::VIEWER])
            ? (new ProgrammeAccess())->corporationIds((int) $user->id, BuybackProgrammeAccess::VIEWER)
            : [];
        $ids = array_values(array_unique(array_merge($this->manageableCorporationIds($request), $viewed)));
        sort($ids);

        return $this->visibleCorporationIds = $ids;
    }

    /**
     * Corporation IDs whose programme this user may change, or null for an
     * admin, who changes every one.
     */
    protected function manageableCorporationIds(Request $request): ?array
    {
        if ($this->managesEveryCorporation($request)) {
            return null;
        }

        if ($this->managedCorporationIds !== null) {
            return $this->managedCorporationIds;
        }

        $user = $request->user();

        return $this->managedCorporationIds = $user !== null && $user->can(ProgrammeAccess::NEEDS[BuybackProgrammeAccess::MANAGER])
            ? (new ProgrammeAccess())->corporationIds((int) $user->id, BuybackProgrammeAccess::MANAGER)
            : [];
    }

    /**
     * Stop here unless this user may manage this corporation's programme.
     * Null stands for every corporation, as a global webhook does, so only
     * an admin may manage it.
     */
    protected function authorizeCorporation(Request $request, ?int $corporationId): void
    {
        if ($this->managesEveryCorporation($request)) {
            return;
        }

        if ($corporationId === null) {
            throw new AccessDeniedHttpException('Only a Buyback Manager admin can manage what covers every corporation.');
        }

        if (! in_array($corporationId, $this->manageableCorporationIds($request), true)) {
            throw new AccessDeniedHttpException('You are not named a manager of this corporation\'s buyback programme. A Buyback Manager admin names them under Settings > Programme Access.');
        }
    }

    /** Stop here unless this user is a Buyback Manager admin. */
    protected function authorizeAdmin(Request $request, string $why): void
    {
        if (! $this->managesEveryCorporation($request)) {
            throw new AccessDeniedHttpException($why);
        }
    }

    /** The setting, once this user is known to manage its corporation. */
    protected function authorizedSetting(Request $request, int $settingId, array $with = []): BuybackSetting
    {
        $setting = BuybackSetting::with($with)->findOrFail($settingId);
        $this->authorizeCorporation($request, (int) $setting->corporation_id);

        return $setting;
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
            throw new AccessDeniedHttpException('You do not have access to this corporation.');
        }

        return (int) $corporationId;
    }
}
