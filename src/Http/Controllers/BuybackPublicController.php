<?php

namespace BuybackManager\Http\Controllers;

use BuybackManager\Models\BuybackAppraisal;
use BuybackManager\Models\BuybackSetting;
use BuybackManager\Services\AppraisalRecordService;
use BuybackManager\Services\AppraisalService;
use BuybackManager\Services\BuybackPublicService;
use BuybackManager\Services\Pricing\PriceProviderService;
use BuybackManager\Services\Pricing\PriceWait;
use BuybackManager\Services\PublicBlurb;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Public, unauthenticated buyback landing page + its image streaming
 * endpoint. Registered under the no-auth /buyback route group.
 */
class BuybackPublicController extends Controller
{
    public function show(string $ticker, BuybackPublicService $service, PriceProviderService $prices)
    {
        $setting = $service->resolveByLink($ticker, false);
        if (! $setting || $this->hidden($setting)) {
            // 404 (not 403) so the existence of a corp/page can't be probed.
            abort(404);
        }

        if ($ticker !== $setting->public_slug) {
            return redirect()->route('buyback-manager.public.show', ['ticker' => $setting->public_slug]);
        }

        if (! $setting->publicPageOpen()) {
            return $this->closed($setting, $service);
        }

        return view('buyback-manager::public.show', $service->branding($setting) + $this->memberNotes($setting) + [
            'setting'       => $setting,
            'blurb'         => PublicBlurb::html($setting->public_blurb ?: 'We buy your items at competitive rates, with fast and locked-in payouts.'),
            'instructions'  => $setting->publicContractInstructions(),
            'rates'         => $setting->public_show_rates ? $service->rates($setting, $prices) : null,
            // No login anything: selling needs no account. Whoever issues the
            // contract is identified from its issuer at detection time, so the
            // page never asks anyone to sign in.
            'layout'        => $setting->public_layout === 'split' ? 'split' : 'stacked',
            'appraisalEnabled' => (bool) $setting->public_appraisal_enabled,
        ]);
    }

    /** Off, and set to answer as if there were no page at all. */
    private function hidden(BuybackSetting $setting): bool
    {
        return ! $setting->publicPageOpen()
            && $setting->publicClosedState() === BuybackSetting::PUBLIC_CLOSED_HIDDEN;
    }

    /**
     * The page while it is off: the corporation's name and look, and a notice
     * that the buyback is closed for now or for good, so a seller following
     * an old link is not left wondering whether it is wrong. A corporation
     * that chose to hide the page keeps the 404.
     */
    private function closed(BuybackSetting $setting, BuybackPublicService $service)
    {
        $state = $setting->publicClosedState();
        if ($state === BuybackSetting::PUBLIC_CLOSED_HIDDEN) {
            abort(404);
        }

        return response()->view('buyback-manager::public.closed', $service->branding($setting) + [
            'setting'   => $setting,
            'permanent' => $state === BuybackSetting::PUBLIC_CLOSED_PERMANENT,
            'message'   => $setting->publicClosedMessage(),
        ]);
    }

    /**
     * What the public pages say about members: where to get member rates,
     * and, for a members-only programme, that anyone else may still sell.
     * Member rates themselves are never shown here.
     */
    private function memberNotes(BuybackSetting $setting): array
    {
        $group = $setting->memberGroupName();

        return [
            'memberRatesNote' => $setting->hasMemberRates() ? $group . ' members: log in to SeAT for member rates.' : null,
            'membersUrl'      => route('buyback.appraisal.index', ['programme' => $setting->corporation_id], false),
            'membersOnlyNote' => $setting->sellerScope() !== BuybackSetting::SELLERS_ANY
                ? 'This buyback is for members of ' . $group . '. Anyone else can still contract it, and a director decides whether to accept.'
                : null,
        ];
    }

    /**
     * The stored appraisal, as a public page.
     *
     * This URL is the artefact the whole flow hangs on: it holds the key to
     * paste into the contract Description, the itemised breakdown behind
     * the quote, the contract instructions for this corporation, and the
     * locations we accept from. Shareable, and readable without an account.
     *
     * Keys are random and unguessable, so possession of the URL is the
     * access check. Beyond the quote, the page says what became of the
     * contract that used the key, by state and date, never by who.
     */
    public function appraisal(string $ticker, string $key, BuybackPublicService $service, PriceProviderService $prices)
    {
        // The key alone finds the quote, and the programme comes from it: the
        // name in front only has to lead there. A link copied before the name
        // followed the contract's recipient carries the programme's ticker,
        // which may now be another programme's name.
        $appraisal = BuybackAppraisal::with(['items', 'corporation', 'contract'])
            ->where('public_id', strtolower($key))
            ->first();
        $setting = $appraisal
            ? BuybackSetting::with(['corporation', 'targetCorporation', 'character'])
                ->where('corporation_id', $appraisal->corporation_id)
                ->first()
            : null;

        if (! $setting || $this->hidden($setting)) {
            abort(404);
        }

        if ($ticker !== $setting->public_slug) {
            return redirect()->route('buyback-manager.public.appraisal', ['ticker' => $setting->public_slug, 'key' => $appraisal->public_id]);
        }

        // A closed buyback takes no contracts, so its quotes are not shown.
        if (! $setting->publicPageOpen()) {
            return $this->closed($setting, $service);
        }

        $showDetail = (bool) $setting->public_show_pricing_detail;
        $validUntil = $service->validUntil($appraisal, $setting);

        return view('buyback-manager::public.appraisal', $this->memberNotes($setting) + [
            'setting'      => $setting,
            'appraisal'    => $appraisal,
            'corpName'     => optional($setting->corporation)->name ?? ('Corporation #' . $setting->corporation_id),
            'ticker'       => $setting->corp_ticker,
            'accent'       => BuybackPublicService::safeAccent($setting->public_accent_color),
            'instructions' => $setting->publicContractInstructions(),
            'locations'    => $setting->allowedLocationLabels(),
            'excluded'     => $appraisal->excluded_json ?? [],
            'pricingNote'  => $showDetail ? $appraisal->pricing_note : null,
            'pricedBy'     => $showDetail ? $prices->sourceLabel($appraisal->provider, $appraisal->market) : null,
            'staleHours'   => $setting->staleAfterHours(),
            'keyStatus'    => $service->keyStatus($appraisal),
            'validUntil'   => $validUntil,
            'isStale'      => $validUntil !== null && $validUntil->isPast(),
            // Whole ISK, with no separator a client language could read
            // differently. Rounding moves the price by under half an ISK,
            // which the price check rounds away on anything over 10,000 ISK.
            'copyPrice'    => number_format(round((float) $appraisal->total_buyback_value), 0, '', ''),
            'haulFees'     => $setting->haulFeeQuotes((float) $appraisal->total_buyback_value, (float) $appraisal->total_volume),
            'thinGuard'    => (float) ($setting->thin_market_guard_percent ?? 10),
            // Rule names are labels, read from the rules as they are now.
            'ruleNames'    => $appraisal->items->mapWithKeys(fn ($item) => [$item->id => $setting->getRuleForItem(
                (int) $item->type_id,
                $item->category_id !== null ? (int) $item->category_id : null,
                $item->group_id !== null ? (int) $item->group_id : null
            )['rule_name'] ?? null])->filter()->all(),
            'recipient'    => $setting->targetDisplayLabel(),
            'publicUrl'    => route('buyback-manager.public.show', ['ticker' => $setting->public_slug], false),
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
        $setting = $service->resolveByLink($ticker, false);
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
     * Before the estimate: ask Manager Core for the pasted items it has no
     * price for yet, and tell the page how many to wait on.
     */
    public function prepare(string $ticker, Request $request, BuybackPublicService $service, PriceWait $wait)
    {
        $setting = $service->resolveByLink($ticker);
        if (! $setting || ! $setting->public_appraisal_enabled) {
            abort(404);
        }

        $request->validate([
            'items' => 'required|string|min:3|max:50000',
        ]);

        return response()->json($wait->start($setting, (string) $request->input('items')));
    }

    /**
     * Whether the estimate prepare() held back can go ahead.
     */
    public function waitFor(string $ticker, string $token, BuybackPublicService $service, PriceWait $wait)
    {
        $setting = $service->resolveByLink($ticker);
        if (! $setting || ! $setting->public_appraisal_enabled) {
            abort(404);
        }

        return response()->json($wait->check($setting, $token));
    }

    /**
     * Public, no-login appraisal preview. Runs the corp's normal appraisal
     * (which itself requires an enabled programme) and returns totals only
     * as JSON. Gated by public_appraisal_enabled and
     * rate-limited at the route.
     */
    public function estimate(
        string $ticker,
        Request $request,
        BuybackPublicService $service,
        AppraisalService $appraisalService,
        AppraisalRecordService $records,
        PriceProviderService $prices
    ) {
        $setting = $service->resolveByLink($ticker);
        if (! $setting || ! $setting->public_appraisal_enabled) {
            abort(404);
        }

        $request->validate([
            'items' => 'required|string|min:3|max:50000',
        ]);

        try {
            $result = $appraisalService->createAppraisal($request->input('items'), (int) $setting->corporation_id);
        } catch (\Throwable $e) {
            // Never let a provider/parser fault surface as an unhandled 500
            // (the browser would get an HTML error page instead of JSON).
            Log::warning('[Buyback Manager] public estimate failed for ' . $ticker . ': ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'The estimate could not be completed. Please try again later.',
            ], 500);
        }

        if (! ($result['success'] ?? false)) {
            Log::info('[Buyback Manager] public estimate returned no result for ' . $ticker . ': ' . ($result['message'] ?? 'unknown'));

            return response()->json([
                'success' => false,
                'no_prices' => ! empty($result['no_prices']),
                'message' => $result['message'] ?? 'Could not estimate.',
            ], 422);
        }

        // Store it so the visitor gets a key they can paste into the
        // contract, plus a shareable page. Attributed when they happen to
        // be signed in, otherwise recorded as a guest.
        $user = $request->user();
        $record = $records->store($result, (int) $setting->corporation_id, $user?->id, null);

        return response()->json([
            'success' => true,
            'total_buyback_value' => (float) $result['total_buyback_value'],
            'total_market_value' => (float) $result['total_market_value'],
            'average_percentage' => round((float) ($result['average_percentage'] ?? 0), 1),
            'item_count' => is_array($result['items'] ?? null) ? count($result['items']) : 0,
            'truncated' => (bool) ($result['truncated'] ?? false),
            'total_volume' => round((float) ($result['total_volume'] ?? 0), 2),
            'haul_fees' => $setting->haulFeeQuotes((float) $result['total_buyback_value'], (float) ($result['total_volume'] ?? 0)),
            'volume_assembled' => array_values(array_map('strval', $result['volume_assembled'] ?? [])),
            'thin_market' => count(array_filter($result['items'] ?? [], fn ($item) => ! empty($item['thin_market']))),
            'appraisal_key' => $record?->public_id,
            'appraisal_url' => $record
                ? route('buyback-manager.public.appraisal', ['ticker' => $setting->public_slug, 'key' => $record->public_id], false)
                : null,
            // Items not valued, by a pricing rule or for want of a price, and
            // lines that named nothing. Listed so the visitor knows they are
            // not in the total rather than silently missing.
            'excluded' => array_map(
                fn ($e) => [
                    'name' => (string) $e['type_name'],
                    'quantity' => (int) $e['quantity'],
                    'unpriced' => ($e['reason'] ?? null) === AppraisalService::NOT_ACCEPTED_UNPRICED,
                ],
                is_array($result['excluded'] ?? null) ? $result['excluded'] : []
            ),
            'unrecognised' => array_values(array_map('strval', array_slice($result['unrecognised'] ?? [], 0, 20))),
            // The note and the source name the provider and market, which
            // the corporation may have chosen to keep off its public page.
            'pricing_note' => $setting->public_show_pricing_detail ? ($result['pricing_note'] ?? null) : null,
            'priced_by' => $setting->public_show_pricing_detail
                ? $prices->sourceLabel($result['provider'] ?? null, $result['market'] ?? null)
                : null,
            'quoted_at' => ($record?->created_at ?? now())->format('j M Y, H:i') . ' EVE',
        ]);
    }
}
