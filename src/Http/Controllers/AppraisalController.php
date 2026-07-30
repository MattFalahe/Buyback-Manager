<?php

namespace BuybackManager\Http\Controllers;

use BuybackManager\Integrations\ManagerCoreIntegration;
use BuybackManager\Models\BuybackAppraisal;
use BuybackManager\Models\BuybackSetting;
use BuybackManager\Services\AppraisalRecordService;
use BuybackManager\Services\AppraisalService;
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

        $managerCoreAvailable = ManagerCoreIntegration::isAvailable();

        return view('buyback-manager::appraisal.index', compact('corporations', 'managerCoreAvailable'));
    }

    /**
     * Appraise a pasted list, store it, and show the result with its key.
     */
    public function create(Request $request)
    {
        $request->validate([
            'corporation_id' => 'required|integer|exists:corporation_infos,corporation_id',
            'items' => 'required|string|min:3',
        ]);

        $corporationId = (int) $request->input('corporation_id');

        $result = $this->appraisalService->createAppraisal(
            $request->input('items'),
            $corporationId
        );

        if (! $result['success']) {
            return redirect()->back()
                ->withInput()
                ->with('error', $result['message']);
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

        return view('buyback-manager::appraisal.result', $result);
    }

    /**
     * The signed-in user's own appraisal history.
     */
    public function mine(Request $request)
    {
        $user = $request->user();

        $appraisals = BuybackAppraisal::with('corporation')
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
