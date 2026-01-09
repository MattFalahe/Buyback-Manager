<?php

namespace BuybackManager\Http\Controllers;

use Illuminate\Http\Request;
use Seat\Web\Http\Controllers\Controller;
use BuybackManager\Models\BuybackContract;
use BuybackManager\Models\BuybackSetting;

class BuybackController extends Controller
{
    public function index(Request $request)
    {
        $corporationId = $request->get('corporation_id');
        
        $query = BuybackContract::with(['corporation', 'issuer', 'items']);
        
        if ($corporationId) {
            $query->where('corporation_id', $corporationId);
        }
        
        $contracts = $query->orderBy('issued_date', 'desc')->paginate(25);
        
        $corporations = BuybackSetting::with('corporation')
            ->where('enabled', true)
            ->get()
            ->pluck('corporation.name', 'corporation_id');
        
        return view('buyback-manager::buyback.index', compact('contracts', 'corporations'));
    }

    public function show(int $id)
    {
        $contract = BuybackContract::with(['corporation', 'issuer', 'items.type'])
            ->findOrFail($id);
        
        return view('buyback-manager::buyback.view', compact('contract'));
    }

    public function statistics(Request $request)
    {
        $corporationId = $request->get('corporation_id');
        $dateFrom = $request->get('date_from', now()->subDays(30));
        $dateTo = $request->get('date_to', now());

        $query = BuybackContract::where('status', 'completed');
        
        if ($corporationId) {
            $query->where('corporation_id', $corporationId);
        }
        
        $query->whereBetween('completed_date', [$dateFrom, $dateTo]);

        // Total statistics
        $totalValue = $query->sum('total_value');
        $totalContracts = $query->count();
        $totalItems = $query->sum('items_count');

        // Top contributors
        $topContributors = BuybackContract::selectRaw('issuer_id, SUM(total_value) as total, COUNT(*) as count')
            ->where('status', 'completed')
            ->when($corporationId, function ($q) use ($corporationId) {
                return $q->where('corporation_id', $corporationId);
            })
            ->whereBetween('completed_date', [$dateFrom, $dateTo])
            ->groupBy('issuer_id')
            ->orderBy('total', 'desc')
            ->limit(10)
            ->with('issuer')
            ->get();

        // Daily statistics for chart
        $dailyStats = BuybackContract::selectRaw('DATE(completed_date) as date, SUM(total_value) as value, COUNT(*) as count')
            ->where('status', 'completed')
            ->when($corporationId, function ($q) use ($corporationId) {
                return $q->where('corporation_id', $corporationId);
            })
            ->whereBetween('completed_date', [$dateFrom, $dateTo])
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $corporations = BuybackSetting::with('corporation')
            ->where('enabled', true)
            ->get()
            ->pluck('corporation.name', 'corporation_id');

        return view('buyback-manager::statistics.index', compact(
            'totalValue',
            'totalContracts',
            'totalItems',
            'topContributors',
            'dailyStats',
            'corporations',
            'dateFrom',
            'dateTo'
        ));
    }
}
