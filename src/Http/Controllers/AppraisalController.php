<?php

namespace BuybackManager\Http\Controllers;

use BuybackManager\Models\BuybackAppraisal;
use BuybackManager\Models\BuybackSetting;
use BuybackManager\Services\AppraisalRecordService;
use BuybackManager\Services\AppraisalService;
use BuybackManager\Services\BuybackPublicService;
use BuybackManager\Services\ProgrammeMembers;
use BuybackManager\Services\Pricing\PriceProviderService;
use BuybackManager\Services\Pricing\PriceWait;
use BuybackManager\Services\PublicBlurb;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Seat\Web\Http\Controllers\Controller;

/**
 * Member-facing appraisal tool.
 *
 * Every appraisal is stored and handed back with a single-use key. The
 * seller pastes that key into their in-game contract's Description, and
 * Buyback Manager pairs the two up when the contract syncs. Guests get the
 * same thing from the public page at public rates; signing in adds history,
 * and member rates for an account holding one of the programme's members.
 */
class AppraisalController extends Controller
{
    protected AppraisalService $appraisalService;

    protected AppraisalRecordService $records;

    protected ProgrammeMembers $members;

    public function __construct(AppraisalService $appraisalService, AppraisalRecordService $records, ProgrammeMembers $members)
    {
        $this->appraisalService = $appraisalService;
        $this->records = $records;
        $this->members = $members;
    }

    /**
     * The members page: a programme in its public look, at the rates this
     * account is quoted. A member sees member rates; anyone else signed in
     * sees the public rates and is told why. The programme asked for in the
     * link comes first, then one this account is a member of.
     */
    public function index(Request $request, BuybackPublicService $public, PriceProviderService $prices)
    {
        $settings = BuybackSetting::with(['corporation', 'targetCorporation', 'character', 'sellerAlliance'])
            ->where('enabled', true)
            ->get()
            ->sortBy(fn (BuybackSetting $setting) => mb_strtolower(optional($setting->corporation)->name ?? ''))
            ->values();

        $user = $request->user();
        $membership = [];
        foreach ($settings as $setting) {
            $membership[(int) $setting->corporation_id] = $user ? $this->members->memberCharacters((int) $user->id, $setting) : [];
        }

        $wanted = (int) old('corporation_id', $request->query('programme', 0));
        $setting = $settings->firstWhere('corporation_id', $wanted)
            ?? $settings->first(fn (BuybackSetting $s) => $membership[(int) $s->corporation_id] !== [])
            ?? $settings->first();

        if ($setting === null) {
            return view('buyback-manager::appraisal.index', ['setting' => null, 'settings' => $settings]);
        }

        $memberCharacters = $membership[(int) $setting->corporation_id];
        $memberRates = $memberCharacters !== [] && $setting->hasMemberRates();

        return view('buyback-manager::appraisal.index', $public->branding($setting) + [
            'settings' => $settings,
            'setting' => $setting,
            'blurb' => PublicBlurb::html($setting->public_blurb ?: 'We buy your items at competitive rates, with fast and locked-in payouts.'),
            'instructions' => $setting->publicContractInstructions(),
            'rates' => $public->rates($setting, $prices, $memberRates),
            'memberRates' => $memberRates,
            'memberName' => $memberCharacters !== [] ? reset($memberCharacters) : null,
            'groupName' => $setting->memberGroupName(),
            'bonus' => rtrim(rtrim(number_format($setting->memberBonus(), 2, '.', ''), '0'), '.'),
        ]);
    }

    /**
     * Appraise a pasted list, store it, and show the result with its key.
     */
    public function create(Request $request)
    {
        $request->validate([
            'corporation_id' => 'required|integer|exists:corporation_infos,corporation_id',
            'items' => 'required|string|min:3|max:200000',
        ]);

        $corporationId = (int) $request->input('corporation_id');
        $user = $request->user();
        $setting = BuybackSetting::where('corporation_id', $corporationId)->first();
        $memberCharacters = $setting && $user ? $this->members->memberCharacters((int) $user->id, $setting) : [];

        $result = $this->appraisalService->createAppraisal(
            $request->input('items'),
            $corporationId,
            $memberCharacters !== [] ? BuybackAppraisal::RATE_MEMBER : BuybackAppraisal::RATE_PUBLIC
        );

        if (! $result['success']) {
            // SeAT's layout shows a flashed error as a banner on every page,
            // so the hamsters' message goes under a key of its own.
            return redirect()->back()
                ->withInput()
                ->with(empty($result['no_prices']) ? 'error' : 'no_prices', $result['message']);
        }

        $appraisal = $this->records->store(
            $result,
            $corporationId,
            $user?->id,
            $memberCharacters !== [] ? (int) array_key_first($memberCharacters) : $this->resolveCharacterId($user, $corporationId)
        );

        // The key and its shareable URL are what the seller actually needs;
        // the appraisal still renders if storage failed, just without them.
        $result['appraisal'] = $appraisal;
        $result['setting'] = $setting;
        $result['haul_fees'] = $result['setting']
            ? $result['setting']->haulFeeQuotes((float) $result['total_buyback_value'], (float) ($result['total_volume'] ?? 0))
            : [];

        return view('buyback-manager::appraisal.result', $result);
    }

    /**
     * Before the quote: ask Manager Core for the pasted items it has no
     * price for yet, and tell the page how many to wait on.
     */
    public function prepare(Request $request, PriceWait $wait)
    {
        $request->validate([
            'corporation_id' => 'required|integer',
            'items' => 'required|string|min:3|max:200000',
        ]);

        $setting = BuybackSetting::where('corporation_id', (int) $request->input('corporation_id'))
            ->where('enabled', true)
            ->first();

        return response()->json($setting
            ? $wait->start($setting, (string) $request->input('items'))
            : $wait->nothingToWaitFor());
    }

    /**
     * Whether the quote prepare() held back can go ahead.
     */
    public function waitFor(Request $request, string $token, PriceWait $wait)
    {
        $setting = BuybackSetting::where('corporation_id', (int) $request->query('corporation_id'))
            ->where('enabled', true)
            ->first();

        return response()->json($setting ? $wait->check($setting, $token) : PriceWait::GO_AHEAD);
    }

    /**
     * The signed-in user's own appraisal history.
     */
    public function mine(Request $request)
    {
        $user = $request->user();

        $appraisals = BuybackAppraisal::with(['corporation', 'setting.corporation', 'setting.targetCorporation', 'setting.character'])
            ->where('user_id', $user?->id)
            ->orderBy('created_at', 'desc')
            ->paginate(25);

        return view('buyback-manager::appraisals.index', compact('appraisals'));
    }

    /**
     * Best-effort character to credit an appraisal to: prefer one in the
     * buying corporation, then the user's main, then any character they
     * hold. Null when they have none registered — the appraisal still works,
     * it is simply not attributed.
     */
    protected function resolveCharacterId($user, int $corporationId): ?int
    {
        if ($user === null) {
            return null;
        }

        // All refresh_tokens columns are table-qualified because
        // character_affiliations also has a character_id column.
        $inCorp = DB::table('refresh_tokens')
            ->join('character_affiliations', 'refresh_tokens.character_id', '=', 'character_affiliations.character_id')
            ->where('refresh_tokens.user_id', $user->id)
            ->whereNull('refresh_tokens.deleted_at')
            ->where('character_affiliations.corporation_id', $corporationId)
            ->orderByRaw('refresh_tokens.character_id = ? DESC', [$user->main_character_id ?? 0])
            ->value('refresh_tokens.character_id');

        if ($inCorp) {
            return (int) $inCorp;
        }

        if ($user->main_character_id) {
            return (int) $user->main_character_id;
        }

        $any = DB::table('refresh_tokens')
            ->where('user_id', $user->id)
            ->whereNull('deleted_at')
            ->value('character_id');

        return $any ? (int) $any : null;
    }
}
