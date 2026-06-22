<?php

namespace BuybackManager\Http\Controllers;

use BuybackManager\Models\BuybackContract;
use BuybackManager\Models\BuybackSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Seat\Web\Http\Controllers\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BuybackController extends Controller
{
    public function index(Request $request)
    {
        $allowedCorpIds = $this->allowedCorporationIds($request);

        $query = BuybackContract::with(['corporation', 'issuer', 'items', 'offer']);
        $this->applyContractScope($query, $request, $allowedCorpIds);

        $contracts = $query->orderBy('issued_date', 'desc')->paginate(25);

        $corporations = $this->corporationsPicker($allowedCorpIds);

        return view('buyback-manager::buyback.index', compact('contracts', 'corporations'));
    }

    /**
     * Stream the filtered buyback contracts list as a CSV download.
     *
     * Mirrors index()'s visibility scoping exactly (same allowedCorporationIds
     * gate + per-corp 403 via applyContractScope), so the export can never leak
     * rows the user could not already see in the table. Honours the
     * corporation_id filter so the file matches what is on screen.
     */
    public function export(Request $request): StreamedResponse
    {
        $allowedCorpIds = $this->allowedCorporationIds($request);

        $query = BuybackContract::with(['corporation', 'issuer']);
        $this->applyContractScope($query, $request, $allowedCorpIds);

        $contracts = $query->orderBy('issued_date', 'desc')->get();

        $filename = 'buyback-contracts-' . now()->format('Y-m-d_His') . '.csv';

        return response()->streamDownload(function () use ($contracts) {
            $handle = fopen('php://output', 'w');

            // UTF-8 BOM so Excel opens accented corp/character names correctly.
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                'Contract ID',
                'Offer',
                'Corporation',
                'Issuer',
                'Status',
                'Items',
                'Total Value',
                'Issued Date',
                'Completed Date',
            ]);

            foreach ($contracts as $contract) {
                fputcsv($handle, [
                    $contract->contract_id,
                    $contract->offer_public_id ?? '',
                    $this->csvSafe($contract->corporation->name ?? 'Unknown'),
                    $this->csvSafe($contract->issuer->name ?? 'Unknown'),
                    ucfirst(str_replace('_', ' ', $contract->status)),
                    $contract->items_count,
                    $contract->total_value,
                    $contract->issued_date?->format('Y-m-d H:i:s'),
                    $contract->completed_date?->format('Y-m-d H:i:s'),
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function show(Request $request, int $id)
    {
        $contract = BuybackContract::with(['corporation', 'issuer', 'items.type'])
            ->findOrFail($id);

        $allowedCorpIds = $this->allowedCorporationIds($request);
        if ($allowedCorpIds !== null && ! in_array((int) $contract->corporation_id, $allowedCorpIds, true)) {
            abort(403, 'You do not have access to this contract.');
        }

        return view('buyback-manager::buyback.view', compact('contract'));
    }

    public function statistics(Request $request)
    {
        $corporationId = $request->get('corporation_id');
        $dateFrom = $request->get('date_from', now()->subDays(30));
        $dateTo = $request->get('date_to', now());

        $allowedCorpIds = $this->allowedCorporationIds($request);

        if ($corporationId && $allowedCorpIds !== null && ! in_array((int) $corporationId, $allowedCorpIds, true)) {
            abort(403, 'You do not have access to this corporation.');
        }

        $baseFilter = function ($q) use ($corporationId, $allowedCorpIds, $dateFrom, $dateTo) {
            $q->whereNotNull('offer_id')
                ->where('status', 'completed')
                ->whereBetween('completed_date', [$dateFrom, $dateTo]);

            if ($corporationId) {
                $q->where('corporation_id', $corporationId);
            } elseif ($allowedCorpIds !== null) {
                $q->whereIn('corporation_id', $allowedCorpIds);
            }
        };

        $totalsQuery = BuybackContract::query();
        $baseFilter($totalsQuery);

        $totalValue = (clone $totalsQuery)->sum('total_value');
        $totalContracts = (clone $totalsQuery)->count();
        $totalItems = (clone $totalsQuery)->sum('items_count');

        $topContributorsQuery = BuybackContract::select([
            'issuer_id',
            DB::raw('SUM(total_value) as total'),
            DB::raw('COUNT(*) as count'),
        ]);
        $baseFilter($topContributorsQuery);
        $topContributors = $topContributorsQuery
            ->groupBy('issuer_id')
            ->orderBy('total', 'desc')
            ->limit(10)
            ->with('issuer')
            ->get();

        $dailyQuery = BuybackContract::select([
            DB::raw('DATE(completed_date) as date'),
            DB::raw('SUM(total_value) as value'),
            DB::raw('COUNT(*) as count'),
        ]);
        $baseFilter($dailyQuery);
        $dailyStats = $dailyQuery
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $corporations = $this->corporationsPicker($allowedCorpIds);

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

    /**
     * Returns the corporation IDs this user is allowed to see buyback data for,
     * or null if the user is a settings admin and should see everything.
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
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Apply the standard buyback-contract visibility scope to a query:
     * only offer-linked contracts (rows without offer_id are noise —
     * random/deleted item-exchange contracts or legacy pre-offer-id rows),
     * restricted to the corporations the user may see, plus an optional
     * corporation_id filter that 403s when out of scope.
     *
     * Shared by index() and export() so the two can never diverge — the
     * export must surface exactly the rows the listing would.
     */
    protected function applyContractScope($query, Request $request, ?array $allowedCorpIds): void
    {
        $query->whereNotNull('offer_id')
            ->when($allowedCorpIds !== null, fn($q) => $q->whereIn('corporation_id', $allowedCorpIds));

        $corporationId = $request->get('corporation_id');
        if ($corporationId) {
            if ($allowedCorpIds !== null && ! in_array((int) $corporationId, $allowedCorpIds, true)) {
                abort(403, 'You do not have access to this corporation.');
            }
            $query->where('corporation_id', $corporationId);
        }
    }

    /**
     * Neutralise CSV / spreadsheet formula injection: a cell that begins
     * with = + - @ (or a leading control char) is prefixed with a single
     * quote so Excel / Sheets treat it as text instead of executing it as
     * a formula. Applied to the EVE-sourced name columns.
     */
    protected function csvSafe(?string $value): string
    {
        $value = (string) $value;

        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }

        return $value;
    }

    protected function corporationsPicker(?array $allowedCorpIds)
    {
        $query = BuybackSetting::with('corporation')->where('enabled', true);
        if ($allowedCorpIds !== null) {
            $query->whereIn('corporation_id', $allowedCorpIds);
        }
        return $query->get()->pluck('corporation.name', 'corporation_id');
    }
}
