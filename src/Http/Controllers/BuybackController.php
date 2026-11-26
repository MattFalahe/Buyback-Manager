<?php

namespace BuybackManager\Http\Controllers;

use BuybackManager\Http\Controllers\Traits\ScopesCorporationAccess;
use BuybackManager\Jobs\OpenContractWindow;
use BuybackManager\Models\BuybackContract;
use BuybackManager\Models\BuybackSetting;
use BuybackManager\Models\BuybackSyncStatus;
use BuybackManager\Services\OpenInGame;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Seat\Eveapi\Models\RefreshToken;
use Seat\Web\Http\Controllers\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BuybackController extends Controller
{
    use ScopesCorporationAccess;

    public function index(Request $request, OpenInGame $openInGame)
    {
        $allowedCorpIds = $this->allowedCorporationIds($request);

        $query = BuybackContract::with(['corporation', 'issuer', 'items']);
        $this->applyContractScope($query, $request, $allowedCorpIds);

        $contracts = $query->orderBy('issued_date', 'desc')->paginate(25);

        $corporations = $this->corporationsPicker($allowedCorpIds);
        $syncStatuses = $this->syncStatuses($allowedCorpIds);
        $oig = $this->openInGameState($request, $contracts->pluck('corporation_id')->unique()->values()->all(), $openInGame);

        return view('buyback-manager::buyback.index', compact('contracts', 'corporations', 'syncStatuses', 'oig'));
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
                'Appraisal',
                'Corporation',
                'Issuer',
                'Status',
                'Items',
                'Volume (m3)',
                'Quoted Value',
                'Asked Price',
                'Deviation %',
                'Review Flags',
                'Issued Date',
                'Completed Date',
            ]);

            foreach ($contracts as $contract) {
                fputcsv($handle, [
                    $contract->contract_id,
                    $contract->appraisal_public_id ?? ($contract->quoted_key ? $contract->quoted_key . ' (no match)' : ''),
                    $this->csvSafe($contract->corporation->name ?? 'Unknown'),
                    $this->csvSafe($contract->issuer->name ?? 'Unknown'),
                    ucfirst(str_replace('_', ' ', $contract->displayStatus())),
                    $contract->items_count,
                    $contract->total_volume,
                    $contract->total_value,
                    $contract->asked_price,
                    $contract->deviation_percent,
                    implode('; ', $contract->flags()),
                    $contract->issued_date?->format('Y-m-d H:i:s'),
                    $contract->completed_date?->format('Y-m-d H:i:s'),
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function show(Request $request, int $id, OpenInGame $openInGame)
    {
        $contract = BuybackContract::with(['corporation', 'issuer', 'items.type', 'appraisal'])
            ->findOrFail($id);

        $allowedCorpIds = $this->allowedCorporationIds($request);
        if ($allowedCorpIds !== null && ! in_array((int) $contract->corporation_id, $allowedCorpIds, true)) {
            abort(403, 'You do not have access to this contract.');
        }

        $oig = $this->openInGameState($request, [(int) $contract->corporation_id], $openInGame);

        return view('buyback-manager::buyback.view', compact('contract', 'oig'));
    }

    public function statistics(Request $request)
    {
        $corporationId = $request->get('corporation_id');

        // The form sends plain dates. The last day counts whole, or a contract
        // paid that afternoon falls outside a range that ends on it.
        $from = $this->dateOr($request->get('date_from'), now()->subDays(30))->startOfDay();
        $to = $this->dateOr($request->get('date_to'), now())->endOfDay();
        $dateFrom = $from->toDateString();
        $dateTo = $to->toDateString();

        $allowedCorpIds = $this->allowedCorporationIds($request);

        if ($corporationId && $allowedCorpIds !== null && ! in_array((int) $corporationId, $allowedCorpIds, true)) {
            abort(403, 'You do not have access to this corporation.');
        }

        // A contract whose key matched no appraisal has no quote, so it
        // stays out of every total.
        $baseFilter = function ($q) use ($corporationId, $allowedCorpIds, $from, $to) {
            $q->whereNotNull('appraisal_id')
                ->whereIn('status', BuybackContract::COMPLETED_STATES)
                ->whereBetween('completed_date', [$from, $to]);

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
        $totalVolume = (float) (clone $totalsQuery)->sum('total_volume');

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
            'totalVolume',
            'topContributors',
            'dailyStats',
            'corporations',
            'dateFrom',
            'dateTo'
        ));
    }


    /**
     * Apply the standard buyback-contract visibility scope to a query:
     * restricted to the corporations the user may see, plus an optional
     * corporation_id filter that 403s when out of scope, and the review
     * filter. Every tracked row quoted a key; one whose key matched nothing
     * is listed too, under "Key matched nothing", so it can be reviewed.
     *
     * Shared by index() and export() so the two can never diverge: the
     * export must surface exactly the rows the listing would.
     */
    protected function applyContractScope($query, Request $request, ?array $allowedCorpIds): void
    {
        $query->when($allowedCorpIds !== null, fn($q) => $q->whereIn('corporation_id', $allowedCorpIds));

        $corporationId = $request->get('corporation_id');
        if ($corporationId) {
            if ($allowedCorpIds !== null && ! in_array((int) $corporationId, $allowedCorpIds, true)) {
                abort(403, 'You do not have access to this corporation.');
            }
            $query->where('corporation_id', $corporationId);
        }

        match ((string) $request->get('review')) {
            'flagged' => $query->flagged(),
            'unknown_key' => $query->unknownKey(),
            'active' => $query->active(),
            'expired' => $query->whereNotNull('expired_at')->whereNotIn('status', BuybackContract::FINAL_STATES),
            default => null,
        };
    }

    /**
     * How each programme's last contract sync went, for the programmes the
     * user can see.
     *
     * @return \Illuminate\Support\Collection<int, array{name: string, status: ?BuybackSyncStatus, manual: bool}>
     */
    protected function syncStatuses(?array $allowedCorpIds)
    {
        return BuybackSetting::with('corporation')
            ->where('enabled', true)
            ->when($allowedCorpIds !== null, fn ($q) => $q->whereIn('corporation_id', $allowedCorpIds))
            ->get()
            ->map(fn (BuybackSetting $setting) => [
                'name' => $setting->corporation->name ?? ('Corporation #' . $setting->corporation_id),
                'status' => BuybackSyncStatus::where('setting_id', $setting->id)->first(),
                'manual' => $setting->isInstructionsOnly(),
            ]);
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

    /**
     * A date from the query string, or the default when it is missing or
     * not a date.
     */
    protected function dateOr(?string $value, Carbon $default): Carbon
    {
        if ($value === null || $value === '') {
            return $default;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable $e) {
            return $default;
        }
    }

    /**
     * Ask the EVE client of one of the user's characters to show a contract.
     * The character defaults to the account's main, then the first one that
     * holds the scope. EVE answers the same whether or not the client is
     * running, so the reply can only say the request was sent.
     */
    public function openInGame(Request $request, int $id, OpenInGame $openInGame)
    {
        $contract = BuybackContract::findOrFail($id);

        $allowedCorpIds = $this->allowedCorporationIds($request);
        if ($allowedCorpIds !== null && ! in_array((int) $contract->corporation_id, $allowedCorpIds, true)) {
            abort(403, 'You do not have access to this contract.');
        }

        $enabled = BuybackSetting::where('corporation_id', $contract->corporation_id)->value('open_in_game');
        if (! $enabled) {
            return response()->json(['ok' => false, 'message' => 'Open in game is off for this programme.'], 422);
        }

        $characters = $openInGame->charactersFor($request->user()?->id);
        if ($characters === []) {
            return response()->json(['ok' => false, 'message' => 'None of your characters has logged in with ' . OpenInGame::SCOPE . ' yet.'], 422);
        }

        $characterId = (int) ($request->input('character_id') ?: array_key_first($characters));
        $token = isset($characters[$characterId]) ? RefreshToken::find($characterId) : null;
        if ($token === null) {
            return response()->json(['ok' => false, 'message' => 'That character is not one of yours with ' . OpenInGame::SCOPE . '.'], 422);
        }

        try {
            (new OpenContractWindow($token, (int) $contract->contract_id))->handle();
        } catch (\Throwable $e) {
            Log::warning('[Buyback Manager] Could not open contract ' . $contract->contract_id . ' in game for character ' . $characterId . ': ' . $e->getMessage());

            return response()->json([
                'ok' => false,
                'message' => 'EVE did not take the request: ' . mb_substr($e->getMessage(), 0, 160),
            ], 502);
        }

        return response()->json(['ok' => true, 'message' => 'Sent to ' . $characters[$characterId] . '.']);
    }

    /**
     * What the Open in game button and banner need: which programmes have it
     * on, the user's characters that can use it, and, when they have none,
     * whether SeAT's default profile even asks for the scope.
     *
     * @param  array<int, int>  $corporationIds
     * @return array{corporations: array<int, true>, characters: array<int, string>, profile_asks: bool}
     */
    protected function openInGameState(Request $request, array $corporationIds, OpenInGame $openInGame): array
    {
        $on = BuybackSetting::whereIn('corporation_id', $corporationIds)
            ->where('open_in_game', true)
            ->pluck('corporation_id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all();

        $characters = $on !== [] ? $openInGame->charactersFor($request->user()?->id) : [];

        return [
            'corporations' => $on,
            'characters' => $characters,
            'profile_asks' => $on !== [] && $characters === [] ? $openInGame->defaultProfileAsks() : true,
        ];
    }
}
