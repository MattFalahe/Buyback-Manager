<?php

namespace BuybackManager\Http\Controllers;

use BuybackManager\Models\BuybackSetting;
use BuybackManager\Services\AppraisalService;
use BuybackManager\Services\BuybackPublicService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Seat\Eveapi\Models\Sde\InvCategory;
use Seat\Eveapi\Models\Sde\InvGroup;
use Seat\Eveapi\Models\Sde\InvType;

/**
 * Public, unauthenticated buyback landing page + its image streaming
 * endpoint. Registered under the no-auth /buyback route group.
 */
class BuybackPublicController extends Controller
{
    public function show(string $ticker, BuybackPublicService $service)
    {
        $setting = $service->resolveByTicker($ticker);
        if (! $setting) {
            // 404 (not 403) so the existence of a corp/page can't be probed.
            abort(404);
        }

        $overlay = max(0, min(100, (int) ($setting->public_overlay_opacity ?? 55))) / 100;

        $backgroundUrl = ! empty($setting->public_background_path)
            ? route('buyback-manager.public.image', ['ticker' => $setting->corp_ticker, 'kind' => 'background'])
            : null;

        $logoUrl = ! empty($setting->public_logo_path)
            ? route('buyback-manager.public.image', ['ticker' => $setting->corp_ticker, 'kind' => 'logo'])
            : ('https://images.evetech.net/corporations/' . $setting->corporation_id . '/logo?size=128');

        return view('buyback-manager::public.show', [
            'setting'       => $setting,
            'corpName'      => optional($setting->corporation)->name ?? ('Corporation #' . $setting->corporation_id),
            'ticker'        => $setting->corp_ticker,
            'accent'        => $this->safeAccent($setting->public_accent_color),
            'overlay'       => $overlay,
            'backgroundUrl' => $backgroundUrl,
            'logoUrl'       => $logoUrl,
            'instructions'  => $setting->publicContractInstructions(),
            'rates'         => $setting->public_show_rates ? $this->buildRates($setting) : null,
            'loginUrl'      => route('buyback.appraisal.index'),
            'layout'        => $setting->public_layout === 'split' ? 'split' : 'stacked',
            'logoStyle'     => in_array($setting->public_logo_style, ['dark', 'none', 'light'], true) ? $setting->public_logo_style : 'dark',
            'appraisalEnabled' => (bool) $setting->public_appraisal_enabled,
        ]);
    }

    /**
     * Stream an uploaded public image (background or logo) directly from
     * the upload disk. Mirrors HR Manager's hero stream: sidesteps
     * `storage:link` and serves from the app origin so CSP is satisfied.
     */
    public function image(string $ticker, string $kind, BuybackPublicService $service)
    {
        if (! in_array($kind, ['background', 'logo'], true)) {
            abort(404);
        }

        // No enabled-gate here: the asset is not sensitive (it is meant to
        // be public anyway) and the admin editor previews it on a
        // not-yet-enabled page. Mirrors HR Manager's hero stream.
        $setting = $service->resolveByTicker($ticker, false);
        if (! $setting) {
            abort(404);
        }

        $path = $kind === 'logo' ? $setting->public_logo_path : $setting->public_background_path;
        if (! $path) {
            abort(404);
        }

        $disk = $service->uploadDisk();
        try {
            if (! Storage::disk($disk)->exists($path)) {
                abort(404);
            }
            return Storage::disk($disk)->response($path, null, [
                'Cache-Control' => 'public, max-age=86400',
                'X-Robots-Tag'  => 'noindex',
            ]);
        } catch (\Throwable $e) {
            Log::warning('[Buyback Manager] public image stream failed: ' . $e->getMessage());
            abort(404);
        }
    }

    /**
     * Public, no-login appraisal preview. Runs the corp's normal appraisal
     * (which itself requires an enabled programme) and returns totals only
     * as JSON — no offer is created. Gated by public_appraisal_enabled and
     * rate-limited at the route. Members log in to lock the real offer.
     */
    public function estimate(string $ticker, Request $request, BuybackPublicService $service, AppraisalService $appraisal)
    {
        $setting = $service->resolveByTicker($ticker);
        if (! $setting || ! $setting->public_appraisal_enabled) {
            abort(404);
        }

        $request->validate([
            'items' => 'required|string|min:3|max:50000',
        ]);

        $result = $appraisal->createAppraisal($request->input('items'), (int) $setting->corporation_id);

        if (! ($result['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => $result['message'] ?? 'Could not estimate.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'total_buyback_value' => (float) $result['total_buyback_value'],
            'total_market_value' => (float) $result['total_market_value'],
            'average_percentage' => round((float) ($result['average_percentage'] ?? 0), 1),
            'item_count' => is_array($result['items'] ?? null) ? count($result['items']) : 0,
            'truncated' => (bool) ($result['truncated'] ?? false),
        ]);
    }

    /**
     * Validate the stored accent colour before it reaches an inline style,
     * falling back to the default teal. Defence-in-depth on top of the
     * save-time regex so the public page can never become a CSS-injection
     * surface.
     */
    private function safeAccent(?string $color): string
    {
        $color = (string) $color;
        return preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : '#1d9e75';
    }

    /**
     * Advertised-rates payload: base %, lock window, and the pricing rules
     * (with SDE names resolved). Honours two optional display flags:
     *   - public_show_all_rules: list every non-excluded rule, else only
     *     the featured ("most wanted") ones.
     *   - public_show_pricing_detail: include the sourced market + each
     *     rule's price side (buy / sell / split). Excluded rules are never
     *     advertised regardless.
     */
    private function buildRates(BuybackSetting $setting): array
    {
        $query = $setting->pricingRules()->where('excluded', false);
        if (! $setting->public_show_all_rules) {
            $query->where('featured', true);
        }
        $rules = $query->orderBy('featured', 'desc')->orderBy('priority', 'desc')->get();

        $names = $this->resolveRuleNames($rules);
        $showDetail = (bool) $setting->public_show_pricing_detail;

        $items = $rules->map(fn ($rule) => [
            'name' => $names[$rule->type][$rule->type_id] ?? (ucfirst($rule->type) . ' #' . $rule->type_id),
            'percentage' => (float) $rule->percentage,
            'featured' => (bool) $rule->featured,
            'price_side' => $showDetail ? $this->sideLabel($rule->price_side) : null,
        ])->values()->all();

        return [
            'base_percentage' => (float) $setting->base_percentage,
            'lock_hours' => (int) ($setting->offer_lock_hours ?: 24),
            'items' => $items,
            'show_detail' => $showDetail,
            'market' => $showDetail ? $this->marketLabel($setting) : null,
        ];
    }

    /**
     * Short, public-friendly label for a rule's price side. Null (use the
     * corp's default side) returns null so no badge is shown.
     */
    private function sideLabel(?string $side): ?string
    {
        return match ($side) {
            'buy' => 'of buy',
            'sell' => 'of sell',
            'split' => 'of split',
            default => null,
        };
    }

    /**
     * Human-readable market the corp prices against, derived from its
     * provider config.
     */
    private function marketLabel(BuybackSetting $setting): string
    {
        return match ($setting->price_provider ?? 'fuzzwork') {
            'janice' => ucfirst($setting->janice_market ?: 'jita'),
            'manager-core' => $setting->manager_core_market ? ucfirst($setting->manager_core_market) : 'Jita',
            default => $setting->price_source === 'region' ? 'a regional market' : 'Jita',
        };
    }

    /**
     * Batch-resolve SDE display names for a set of pricing rules,
     * grouped by rule type to avoid N+1 lookups.
     *
     * @return array{item: array, group: array, category: array}
     */
    private function resolveRuleNames($rules): array
    {
        $byType = ['item' => [], 'group' => [], 'category' => []];
        foreach ($rules as $rule) {
            if (isset($byType[$rule->type])) {
                $byType[$rule->type][] = $rule->type_id;
            }
        }

        $names = ['item' => [], 'group' => [], 'category' => []];
        if ($byType['item']) {
            $names['item'] = InvType::whereIn('typeID', $byType['item'])->pluck('typeName', 'typeID')->all();
        }
        if ($byType['group']) {
            $names['group'] = InvGroup::whereIn('groupID', $byType['group'])->pluck('groupName', 'groupID')->all();
        }
        if ($byType['category']) {
            $names['category'] = InvCategory::whereIn('categoryID', $byType['category'])->pluck('categoryName', 'categoryID')->all();
        }

        return $names;
    }
}
