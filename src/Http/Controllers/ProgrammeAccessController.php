<?php

namespace BuybackManager\Http\Controllers;

use BuybackManager\Http\Controllers\Traits\ScopesCorporationAccess;
use BuybackManager\Services\ProgrammeAccess;
use Illuminate\Http\Request;
use Seat\Web\Http\Controllers\Controller;

/**
 * Naming who manages and who views each programme. Only an admin names
 * anyone: a manager naming more managers would undo the point of it.
 */
class ProgrammeAccessController extends Controller
{
    use ScopesCorporationAccess;

    private const ADMIN_ONLY = 'Only a Buyback Manager admin names who manages or views a programme.';

    public function store(Request $request, int $settingId)
    {
        $this->authorizeAdmin($request, self::ADMIN_ONLY);
        $setting = $this->authorizedSetting($request, $settingId, ['corporation']);

        $access = (string) $request->input('access');
        $problem = (new ProgrammeAccess())->name(
            $setting->id,
            $access,
            (string) $request->input('subject_type'),
            (int) $request->input('subject_id')
        );

        return $problem !== null
            ? $this->back()->with('error', $problem)
            : $this->back()->with('success', ucfirst($access) . ' added to ' . ($setting->corporation->name ?? 'the programme') . '.');
    }

    public function destroy(Request $request, int $settingId, int $accessId)
    {
        $this->authorizeAdmin($request, self::ADMIN_ONLY);
        $setting = $this->authorizedSetting($request, $settingId);

        (new ProgrammeAccess())->unname($setting->id, $accessId);

        return $this->back()->with('success', 'Removed.');
    }

    /** SeAT accounts found by any of their characters' names, for the picker. */
    public function accounts(Request $request)
    {
        $this->authorizeAdmin($request, self::ADMIN_ONLY);

        return response()->json((new ProgrammeAccess())->searchAccounts(mb_substr((string) $request->input('q'), 0, 100)));
    }

    private function back()
    {
        return redirect()->to(route('buyback-manager.settings.index') . '#access');
    }
}
