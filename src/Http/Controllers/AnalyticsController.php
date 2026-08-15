<?php

namespace BuybackManager\Http\Controllers;

use BuybackManager\Http\Controllers\Traits\ScopesCorporationAccess;
use BuybackManager\Models\BuybackContract;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Seat\Eveapi\Models\Sde\InvCategory;
use Seat\Eveapi\Models\Sde\InvGroup;
use Seat\Eveapi\Models\Sde\InvType;
use Seat\Web\Http\Controllers\Controller;

/**
 * Buyback analytics: what the programme actually bought, and how much of
 * what it quoted turned into a sale.
 *
 * Two different retention windows feed this page, which is why some panels
 * cover all history and one does not:
 *
 *   - buyback_contract_items are kept for as long as the contract is, so
 *     everything about what was BOUGHT is answerable over full history.
 *   - buyback_appraisal_items are pruned early (they are the bulk of the
 *     data), so the "quoted but never sold" panel only covers the retention
 *     window and says so on the page rather than quietly under-reporting.
 */
class AnalyticsController extends Controller
{
    use ScopesCorporationAccess;

    public function index(Request $request)
    {
        $allowedCorpIds = $this->allowedCorporationIds($request);
        $corporationId = $this->resolveCorporationFilter($request, $allowedCorpIds);

        $days = (int) $request->get('days', 90);
        $days = max(7, min(365, $days));
        $since = now()->subDays($days);

        $corporations = $this->corporationsPicker($allowedCorpIds);

        return view('buyback-manager::analytics.index', [
            'corporations' => $corporations,
            'corporationId' => $corporationId,
            'days' => $days,
            'headline' => $this->headline($allowedCorpIds, $corporationId, $since),
            'funnel' => $this->funnel($allowedCorpIds, $corporationId, $since),
            'topItems' => $this->topItems($allowedCorpIds, $corporationId, $since),
            'topGroups' => $this->topBy('group_id', $allowedCorpIds, $corporationId, $since),
            'topCategories' => $this->topBy('category_id', $allowedCorpIds, $corporationId, $since),
            'timeline' => $this->timeline($allowedCorpIds, $corporationId, $since),
            'flags' => $this->flagBreakdown($allowedCorpIds, $corporationId, $since),
            'topSellers' => $this->topSellers($allowedCorpIds, $corporationId, $since),
            'quotedNotSold' => $this->quotedNotSold($allowedCorpIds, $corporationId),
        ]);
    }

    /**
     * Base query over completed contracts inside the window and the user's
     * corporation scope. Every money figure on the page starts here, so the
     * numbers can never disagree between panels.
     */
    private function paidContracts(?array $allowedCorpIds, ?int $corporationId, $since)
    {
        return BuybackContract::query()
            ->whereIn('status', BuybackContract::COMPLETED_STATES)
            ->where('completed_date', '>=', $since)
            ->when($corporationId, fn ($q) => $q->where('corporation_id', $corporationId))
            ->when($corporationId === null && $allowedCorpIds !== null,
                fn ($q) => $q->whereIn('corporation_id', $allowedCorpIds));
    }

    private function headline(?array $allowedCorpIds, ?int $corporationId, $since): array
    {
        $base = $this->paidContracts($allowedCorpIds, $corporationId, $since);

        $totalPaid = (float) (clone $base)->sum('total_value');
        $contracts = (int) (clone $base)->count();
        $sellers = (int) (clone $base)->distinct('issuer_id')->count('issuer_id');
        $items = (int) (clone $base)->sum('items_count');

        return [
            'total_paid' => $totalPaid,
            'contracts' => $contracts,
            'sellers' => $sellers,
            'items' => $items,
            'average' => $contracts > 0 ? $totalPaid / $contracts : 0.0,
        ];
    }

    /**
     * Quote -> contract -> paid. The drop between quoted and contracted is
     * the interesting number: it is the share of people who were given a
     * price and walked away.
     */
    private function funnel(?array $allowedCorpIds, ?int $corporationId, $since): array
    {
        if (! Schema::hasTable('buyback_appraisals')) {
            return ['appraisals' => 0, 'matched' => 0, 'paid' => 0, 'conversion' => 0.0];
        }

        $appraisals = DB::table('buyback_appraisals')
            ->where('created_at', '>=', $since)
            ->when($corporationId, fn ($q) => $q->where('corporation_id', $corporationId))
            ->when($corporationId === null && $allowedCorpIds !== null,
                fn ($q) => $q->whereIn('corporation_id', $allowedCorpIds));

        $total = (int) (clone $appraisals)->count();
        $matched = (int) (clone $appraisals)->whereNotNull('matched_contract_id')->count();
        $paid = (int) $this->paidContracts($allowedCorpIds, $corporationId, $since)->count();

        return [
            'appraisals' => $total,
            'matched' => $matched,
            'paid' => $paid,
            'conversion' => $total > 0 ? round(($matched / $total) * 100, 1) : 0.0,
        ];
    }

    /**
     * Items ranked by ISK actually paid for them.
     */
    private function topItems(?array $allowedCorpIds, ?int $corporationId, $since, int $limit = 20): array
    {
        $rows = $this->itemQuery($allowedCorpIds, $corporationId, $since)
            ->select('i.type_id', DB::raw('SUM(i.total_value) as isk'), DB::raw('SUM(i.quantity) as qty'))
            ->groupBy('i.type_id')
            ->orderByDesc('isk')
            ->limit($limit)
            ->get();

        $names = InvType::whereIn('typeID', $rows->pluck('type_id')->all())
            ->pluck('typeName', 'typeID');

        return $rows->map(fn ($r) => [
            'name' => $names[$r->type_id] ?? ('Type #' . $r->type_id),
            'isk' => (float) $r->isk,
            'qty' => (int) $r->qty,
        ])->all();
    }

    /**
     * Items rolled up by group or category, whichever column is asked for.
     */
    private function topBy(string $column, ?array $allowedCorpIds, ?int $corporationId, $since, int $limit = 10): array
    {
        $rows = $this->itemQuery($allowedCorpIds, $corporationId, $since)
            ->whereNotNull('i.' . $column)
            ->select('i.' . $column . ' as bucket', DB::raw('SUM(i.total_value) as isk'), DB::raw('SUM(i.quantity) as qty'))
            ->groupBy('i.' . $column)
            ->orderByDesc('isk')
            ->limit($limit)
            ->get();

        $ids = $rows->pluck('bucket')->all();
        $names = $column === 'group_id'
            ? InvGroup::whereIn('groupID', $ids)->pluck('groupName', 'groupID')
            : InvCategory::whereIn('categoryID', $ids)->pluck('categoryName', 'categoryID');

        return $rows->map(fn ($r) => [
            'name' => $names[$r->bucket] ?? ('#' . $r->bucket),
            'isk' => (float) $r->isk,
            'qty' => (int) $r->qty,
        ])->all();
    }

    /**
     * Contract line items joined to their paid contract, scoped the same way
     * as every other figure on the page.
     */
    private function itemQuery(?array $allowedCorpIds, ?int $corporationId, $since)
    {
        return DB::table('buyback_contract_items as i')
            ->join('buyback_contracts as c', 'i.contract_id', '=', 'c.id')
            ->whereIn('c.status', BuybackContract::COMPLETED_STATES)
            ->where('c.completed_date', '>=', $since)
            ->when($corporationId, fn ($q) => $q->where('c.corporation_id', $corporationId))
            ->when($corporationId === null && $allowedCorpIds !== null,
                fn ($q) => $q->whereIn('c.corporation_id', $allowedCorpIds));
    }

    /**
     * ISK paid per day, for the trend chart.
     */
    private function timeline(?array $allowedCorpIds, ?int $corporationId, $since): array
    {
        return $this->paidContracts($allowedCorpIds, $corporationId, $since)
            ->select(
                DB::raw('DATE(completed_date) as day'),
                DB::raw('SUM(total_value) as isk'),
                DB::raw('COUNT(*) as contracts')
            )
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->map(fn ($r) => [
                'day' => (string) $r->day,
                'isk' => (float) $r->isk,
                'contracts' => (int) $r->contracts,
            ])->all();
    }

    /**
     * How often each review reason fired. Tells an operator which rule is
     * actually causing friction: lots of stale-quote flags means the window
     * is too short, lots of wrong-location means the instructions are unclear.
     */
    private function flagBreakdown(?array $allowedCorpIds, ?int $corporationId, $since): array
    {
        $contracts = BuybackContract::query()
            ->whereNotNull('flags_json')
            ->where('issued_date', '>=', $since)
            ->when($corporationId, fn ($q) => $q->where('corporation_id', $corporationId))
            ->when($corporationId === null && $allowedCorpIds !== null,
                fn ($q) => $q->whereIn('corporation_id', $allowedCorpIds))
            ->get(['flags_json']);

        $counts = [];
        foreach ($contracts as $contract) {
            foreach ((array) $contract->flags_json as $flag) {
                $counts[$flag] = ($counts[$flag] ?? 0) + 1;
            }
        }
        arsort($counts);

        $out = [];
        foreach ($counts as $flag => $count) {
            $out[] = [
                'label' => BuybackContract::FLAG_LABELS[$flag] ?? $flag,
                'count' => $count,
            ];
        }

        return $out;
    }

    private function topSellers(?array $allowedCorpIds, ?int $corporationId, $since, int $limit = 10): array
    {
        $rows = $this->paidContracts($allowedCorpIds, $corporationId, $since)
            ->select('issuer_id', DB::raw('SUM(total_value) as isk'), DB::raw('COUNT(*) as contracts'))
            ->groupBy('issuer_id')
            ->orderByDesc('isk')
            ->limit($limit)
            ->get();

        $names = DB::table('character_infos')
            ->whereIn('character_id', $rows->pluck('issuer_id')->all())
            ->pluck('name', 'character_id');

        return $rows->map(fn ($r) => [
            'name' => $names[$r->issuer_id] ?? ('Character #' . $r->issuer_id),
            'character_id' => (int) $r->issuer_id,
            'isk' => (float) $r->isk,
            'contracts' => (int) $r->contracts,
        ])->all();
    }

    /**
     * Items people were quoted for but never contracted, ranked by the ISK
     * that walked away. A big number next to an item usually means the rate
     * on it is not competitive.
     *
     * Limited to whatever is left of buyback_appraisal_items, which is pruned
     * early by design — the view states the window rather than presenting a
     * partial answer as a complete one.
     */
    private function quotedNotSold(?array $allowedCorpIds, ?int $corporationId, int $limit = 10): array
    {
        if (! Schema::hasTable('buyback_appraisal_items')) {
            return [];
        }

        $rows = DB::table('buyback_appraisal_items as ai')
            ->join('buyback_appraisals as a', 'ai.appraisal_id', '=', 'a.id')
            ->whereNull('a.matched_contract_id')
            ->when($corporationId, fn ($q) => $q->where('a.corporation_id', $corporationId))
            ->when($corporationId === null && $allowedCorpIds !== null,
                fn ($q) => $q->whereIn('a.corporation_id', $allowedCorpIds))
            ->select('ai.type_id', DB::raw('SUM(ai.total_buyback) as isk'), DB::raw('COUNT(DISTINCT a.id) as quotes'))
            ->groupBy('ai.type_id')
            ->orderByDesc('isk')
            ->limit($limit)
            ->get();

        $names = InvType::whereIn('typeID', $rows->pluck('type_id')->all())
            ->pluck('typeName', 'typeID');

        return $rows->map(fn ($r) => [
            'name' => $names[$r->type_id] ?? ('Type #' . $r->type_id),
            'isk' => (float) $r->isk,
            'quotes' => (int) $r->quotes,
        ])->all();
    }
}
