<?php

namespace BuybackManager\Http\Controllers;

use BuybackManager\Integrations\ManagerCoreIntegration;
use BuybackManager\Models\BuybackSetting;
use BuybackManager\Services\AppraisalService;
use Illuminate\Http\Request;
use Seat\Web\Http\Controllers\Controller;

class AppraisalController extends Controller
{
    protected AppraisalService $appraisalService;

    public function __construct(AppraisalService $appraisalService)
    {
        $this->appraisalService = $appraisalService;
    }

    /**
     * Show the appraisal form
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
     * Create appraisal from raw input
     */
    public function create(Request $request)
    {
        $request->validate([
            'corporation_id' => 'required|integer|exists:corporation_infos,corporation_id',
            'items' => 'required|string|min:3',
        ]);

        $result = $this->appraisalService->createAppraisal(
            $request->input('items'),
            $request->input('corporation_id')
        );

        if (!$result['success']) {
            return redirect()->back()
                ->withInput()
                ->with('error', $result['message']);
        }

        return view('buyback-manager::appraisal.result', $result);
    }
}
