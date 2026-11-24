<?php

namespace BuybackManager\Http\Controllers;

use BuybackManager\Models\BuybackAppraisal;
use BuybackManager\Models\BuybackSetting;
use BuybackManager\Services\AppraisalRecordService;
use BuybackManager\Services\AppraisalService;
use BuybackManager\Services\Pricing\PriceWait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Seat\Web\Http\Controllers\Controller;

/**
 * Member-facing appraisal tool.
 *
 * Every appraisal is stored and handed back with a single-use key. The
 * seller pastes that key into their in-game contract's Description, and
 * Buyback Manager pairs the two up when the contract syncs. Guests get the
 * same thing from the public page — signing in only adds history.
 */
class AppraisalController extends Controller
{
    protected AppraisalService $appraisalService;

    protected AppraisalRecordService $records;

    public function __construct(AppraisalService $appraisalService, AppraisalRecordService $records)
    {
        $this->appraisalService = $appraisalService;
        $this->records = $records;
    }

    /**
     * Show the appraisal form.
     */
    public function index()
    {
        $corporations = BuybackSetting::with('corporation')
            ->where('enabled', true)
            ->get()
            ->pluck('corporation.name', 'corporation_id');

        return view('buyback-manager::appraisal.index', compact('corporations'));
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

        $result = $this->appraisalService->createAppraisal(
            $request->input('items'),
            $corporationId
        );

        if (! $result['success']) {
            // SeAT's layout shows a flashed error as a banner on every page,
            // so the hamsters' message goes under a key of its own.
            return redirect()->back()
                ->withInput()
                ->with(empty($result['no_prices']) ? 'error' : 'no_prices', $result['message']);
        }

        $user = $request->user();
        $appraisal = $this->records->store(
            $result,
            $corporationId,
            $user?->id,
            $this->resolveCharacterId($user, $corporationId)
        );

        // The key and its shareable URL are what the seller actually needs;
        // the appraisal still renders if storage failed, just without them.
        $result['appraisal'] = $appraisal;
        $result['setting'] = BuybackSetting::where('corporation_id', $corporationId)->first();
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
