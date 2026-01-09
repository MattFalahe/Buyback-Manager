<?php

namespace BuybackManager\Http\Controllers;

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
        // Get corporations with buyback enabled
        $corporations = BuybackSetting::with('corporation')
            ->where('enabled', true)
            ->get()
            ->pluck('corporation.name', 'corporation_id');

        return view('buyback::appraisal.index', compact('corporations'));
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

        return view('buyback::appraisal.result', $result);
    }
}
