<?php

namespace BuybackManager\Http\Controllers;

use BuybackManager\Integrations\ManagerCoreIntegration;
use BuybackManager\Models\BuybackPricingRule;
use BuybackManager\Models\BuybackSetting;
use BuybackManager\Services\BuybackPublicService;
use BuybackManager\Services\Pricing\PriceProviderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Seat\Eveapi\Models\Corporation\CorporationInfo;
use Seat\Eveapi\Models\Sde\InvCategory;
use Seat\Eveapi\Models\Sde\InvGroup;
use Seat\Web\Http\Controllers\Controller;

class SettingsController extends Controller
{
    protected PriceProviderService $priceProvider;

    public function __construct(PriceProviderService $priceProvider)
    {
        $this->priceProvider = $priceProvider;
    }

    public function index()
    {
        $settings = BuybackSetting::with(['corporation', 'pricingRules'])->get();
        $corporations = CorporationInfo::all();
        $providers = $this->priceProvider->getAvailableProviders();
        $mcMarkets = $this->loadManagerCoreMarkets();

        // Webhook management is now embedded in the settings page (the
        // Discord Webhooks tab) instead of a separate redirect page.
        $webhooks = \BuybackManager\Models\BuybackWebhook::with('corporation')
            ->orderBy('corporation_id')
            ->orderBy('name')
            ->get();
        $allCategories = \BuybackManager\Models\BuybackWebhook::ALL_CATEGORIES;
        $recentLog = \BuybackManager\Models\BuybackNotificationLog::orderBy('sent_at', 'desc')->limit(50)->get();

        $roleProviderAvailable = \BuybackManager\Services\DiscordRoleResolver::isAvailable();
        $roleProviderLabel = \BuybackManager\Services\DiscordRoleResolver::providerLabel();
        $roleLookup = $roleProviderAvailable ? \BuybackManager\Services\DiscordRoleResolver::roleLookupMap() : [];

        $routingMap = $this->buildRoutingMap($webhooks);

        return view('buyback-manager::settings.index', compact(
            'settings',
            'corporations',
            'providers',
            'mcMarkets',
            'webhooks',
            'allCategories',
            'recentLog',
            'roleProviderAvailable',
            'roleProviderLabel',
            'roleLookup',
            'routingMap'
        ));
    }

    /**
     * Per-category routing map: for each notification category, which
     * enabled webhooks fire for it. A read-only view so operators can see
     * "if a contract goes unmatched, which channels get pinged?" without
     * cross-referencing every webhook's category list by hand.
     *
     * BB's routing is direct (each webhook subscribes to a set of
     * categories via its JSON column) so there's no L1/L2/L3 binding
     * precedence to resolve like Structure Manager — the map is simply
     * category -> the webhooks subscribing to it. Categories with no
     * subscriber are flagged so the operator knows that event is silent.
     *
     * @param  \Illuminate\Support\Collection  $webhooks
     * @return array<string, array{description: string, events: array, webhooks: \Illuminate\Support\Collection}>
     */
    protected function buildRoutingMap($webhooks): array
    {
        // category => [human description, the buyback.* events that map here]
        $catMeta = [
            'offer_published' => ['New offers published', ['buyback.offer.published', 'buyback.offer.expired', 'buyback.offer.cancelled']],
            'offer_matched' => ['Offers/contracts matched', ['buyback.offer.matched', 'buyback.contract.matched']],
            'offer_rejected' => ['Offers/contracts rejected', ['buyback.offer.rejected', 'buyback.contract.rejected']],
            'contract_unmatched' => ['Contract referenced an offer id that did not resolve (review)', ['buyback.contract.unmatched']],
            'contract_completed' => ['Completed buybacks', ['buyback.contract.completed']],
            'contract_cancelled' => ['Cancelled contracts', ['buyback.contract.cancelled']],
            'contract_nudge' => ['Matched contract still awaiting acceptance past the auto-nudge window', ['buyback.contract.nudge']],
        ];

        $map = [];
        foreach (\BuybackManager\Models\BuybackWebhook::ALL_CATEGORIES as $cat) {
            $subs = $webhooks->filter(function ($w) use ($cat) {
                return $w->enabled && in_array($cat, $w->categories ?? [], true);
            })->values();

            $map[$cat] = [
                'description' => $catMeta[$cat][0] ?? $cat,
                'events' => $catMeta[$cat][1] ?? [],
                'webhooks' => $subs,
            ];
        }

        return $map;
    }

    /**
     * Query Manager Core's `manager_core_markets` table for the dropdown
     * that populates the MC Market field. Returns a list shape consumable
     * by the view:
     *   [['key' => 'jita', 'label' => 'Jita', 'type' => 'hub'], ...]
     *
     * Empty when MC isn't installed, the table doesn't exist, or the
     * read fails. The view falls back to a single 'jita' option in that
     * case so the form remains usable.
     *
     * Direct DB::table read rather than importing MC's Market model —
     * per the integration architecture, BB shouldn't take a hard dep
     * on MC's Eloquent surface. `Schema::hasTable` makes this safe to
     * call regardless of MC's presence.
     */
    protected function loadManagerCoreMarkets(): array
    {
        if (! ManagerCoreIntegration::isAvailable()) {
            return [];
        }
        if (! Schema::hasTable('manager_core_markets')) {
            return [];
        }

        try {
            return DB::table('manager_core_markets')
                ->where('is_enabled', true)
                ->orderBy('market_type')
                ->orderBy('name')
                ->select('key', 'name', 'market_type', 'structure_name')
                ->get()
                ->map(function ($row) {
                    $label = (string) $row->name;
                    if ($row->market_type === 'citadel' && ! empty($row->structure_name)) {
                        $label = $row->name . ' (' . $row->structure_name . ')';
                    }
                    return [
                        'key' => (string) $row->key,
                        'label' => $label,
                        'type' => (string) $row->market_type,
                    ];
                })
                ->all();
        } catch (\Throwable $e) {
            Log::warning('[Buyback Manager] Failed to load MC markets list: ' . $e->getMessage());
            return [];
        }
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'id' => 'nullable|integer|exists:buyback_settings,id',
            'corporation_id' => 'required|exists:corporation_infos,corporation_id',
            'character_id' => 'nullable|exists:character_infos,character_id',
            'enabled' => 'boolean',
            'base_percentage' => 'required|numeric|min:0|max:100',
            'price_source' => 'required|in:jita,region',
            'region_id' => 'nullable|required_if:price_source,region|integer',
            // Provider config
            'price_provider' => 'required|in:fuzzwork,janice,manager-core',
            'janice_api_key' => 'nullable|string|max:255',
            'janice_market' => 'nullable|in:jita,amarr',
            'janice_price_method' => 'nullable|in:buy,sell,split',
            'manager_core_market' => 'nullable|string|max:64',
            'manager_core_variant' => 'nullable|in:min,max,avg,median,percentile',
            'fallback_to_jita' => 'boolean',
            'price_cache_ttl_minutes' => 'nullable|integer|min:0|max:1440',
            // Contract target
            'target_type' => 'required|in:my_corp,corp,player',
            'target_corporation_id' => 'nullable|integer|exists:corporation_infos,corporation_id',
            'target_corporation_name' => 'nullable|string|max:255',
            'offer_lock_hours' => 'nullable|integer|min:1|max:168',
            'private_auto_nudge_hours' => 'nullable|integer|min:0|max:168',
        ]);

        $targetType = $validated['target_type'] ?? BuybackSetting::TARGET_MY_CORP;

        // Player target requires a designated operator character.
        if ($targetType === BuybackSetting::TARGET_PLAYER && empty($validated['character_id'])) {
            return redirect()->back()
                ->withInput()
                ->with('error', 'Player target requires a designated operator character. Set Specific Character on the form.');
        }

        // Corporation target requires either a picked corp (resolvable ID)
        // or a typed name (instructions-only).
        if ($targetType === BuybackSetting::TARGET_CORP
            && empty($validated['target_corporation_id'])
            && empty($validated['target_corporation_name'])) {
            return redirect()->back()
                ->withInput()
                ->with('error', 'Corporation target requires either a corporation picked from the list or a corporation name typed in.');
        }

        // Normalise target fields per type so stale data from a previous
        // target choice doesn't linger (hidden form fields still submit).
        if ($targetType === BuybackSetting::TARGET_CORP) {
            // When a known corp is picked, resolve its name for display so
            // the offer instructions read nicely even if the free-text box
            // was left blank.
            if (! empty($validated['target_corporation_id'])) {
                $resolved = CorporationInfo::where('corporation_id', $validated['target_corporation_id'])->value('name');
                $validated['target_corporation_name'] = $validated['target_corporation_name'] ?: $resolved;
            }
        } else {
            $validated['target_corporation_id'] = null;
            $validated['target_corporation_name'] = null;
        }

        if ($targetType !== BuybackSetting::TARGET_PLAYER) {
            $validated['character_id'] = null;
        }

        // Legacy mode column, derived for the offer-visibility layer.
        $validated['buyback_mode'] = $targetType === BuybackSetting::TARGET_PLAYER ? 'private' : 'public';

        $validated['enabled'] = $request->has('enabled');
        $validated['fallback_to_jita'] = $request->has('fallback_to_jita');
        $validated['offer_lock_hours'] = $validated['offer_lock_hours'] ?? 24;
        $validated['private_auto_nudge_hours'] = $validated['private_auto_nudge_hours'] ?? 48;

        // Capture the previous state so we can detect provider transitions
        // and (un)subscribe MC accordingly.
        $previousProvider = null;
        $previousMcMarket = null;
        if (! empty($validated['id'])) {
            $existing = BuybackSetting::find($validated['id']);
            if ($existing) {
                $previousProvider = $existing->price_provider;
                $previousMcMarket = $existing->manager_core_market;
            }
        }

        if (! empty($validated['id'])) {
            $setting = BuybackSetting::findOrFail($validated['id']);
            unset($validated['id']);
            $setting->update($validated);
        } else {
            $setting = BuybackSetting::updateOrCreate(
                ['corporation_id' => $validated['corporation_id']],
                $validated
            );
        }

        $this->reconcileManagerCoreSubscription($setting, $previousProvider, $previousMcMarket);

        return redirect()->route('buyback-manager.settings.index')
            ->with('success', 'Buyback settings saved successfully');
    }

    /**
     * Live-test the provider config currently entered in the form (does
     * NOT require saving). POST body shape mirrors the form fields plus
     * a `provider` selector. Returns JSON {success, message} for the
     * Test Connection button's inline status indicator.
     */
    public function testProvider(Request $request)
    {
        $request->validate([
            'provider' => 'required|in:fuzzwork,janice,manager-core',
            'janice_api_key' => 'nullable|string',
            'janice_market' => 'nullable|in:jita,amarr',
            'janice_price_method' => 'nullable|in:buy,sell,split',
            'manager_core_market' => 'nullable|string',
            'manager_core_variant' => 'nullable|string',
        ]);

        // Build a transient BuybackSetting (NOT persisted) so the price
        // provider can read the form's pricing fields without us having
        // to thread them through as a separate config bag.
        $transient = new BuybackSetting([
            'corporation_id' => 0,
            'enabled' => true,
            'base_percentage' => 100,
            'price_source' => 'jita',
            'price_provider' => $request->input('provider'),
            'janice_api_key' => $request->input('janice_api_key'),
            'janice_market' => $request->input('janice_market', 'jita'),
            'janice_price_method' => $request->input('janice_price_method', 'buy'),
            'manager_core_market' => $request->input('manager_core_market', 'jita'),
            'manager_core_variant' => $request->input('manager_core_variant', 'min'),
            'fallback_to_jita' => false,
        ]);

        try {
            $ok = $this->priceProvider->testProvider($request->input('provider'), $transient);
            if ($ok) {
                return response()->json([
                    'success' => true,
                    'message' => 'Connection OK (Tritanium priced)',
                ]);
            }
            return response()->json([
                'success' => false,
                'message' => 'Provider returned no price for Tritanium',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Test error: ' . $e->getMessage(),
            ]);
        }
    }

    public function destroy(int $id)
    {
        $setting = BuybackSetting::findOrFail($id);
        $setting->delete();

        return redirect()->route('buyback-manager.settings.index')
            ->with('success', 'Buyback setting deleted successfully');
    }

    /**
     * Show the public landing page editor for a corp's buyback setting.
     */
    public function editPublic(int $id)
    {
        $setting = BuybackSetting::with('corporation')->findOrFail($id);

        return view('buyback-manager::settings.public', compact('setting'));
    }

    /**
     * Persist the public landing page config + handle background/logo
     * uploads. Images follow HR Manager's pattern: store the new file
     * first, then delete the old, so a failed upload never leaves the row
     * pointing at a deleted file.
     */
    public function updatePublic(Request $request, int $id, BuybackPublicService $publicService)
    {
        $setting = BuybackSetting::findOrFail($id);

        $request->validate([
            'public_page_enabled'    => 'nullable|boolean',
            'public_show_rates'      => 'nullable|boolean',
            'public_show_all_rules'  => 'nullable|boolean',
            'public_show_pricing_detail' => 'nullable|boolean',
            'public_layout'          => 'nullable|in:stacked,split',
            'public_headline'        => 'nullable|string|max:191',
            'public_blurb'           => 'nullable|string|max:2000',
            'public_accent_color'    => 'nullable|regex:/^#[0-9a-fA-F]{6}$/',
            'public_overlay_opacity' => 'nullable|integer|min:0|max:100',
            'public_footer_text'     => 'nullable|string|max:500',
            'public_background'      => 'nullable|image|mimes:jpeg,png,webp|max:5120',
            'public_logo'            => 'nullable|image|mimes:jpeg,png,webp|max:5120',
            'public_logo_style'      => 'nullable|in:dark,none,light',
            'remove_background'      => 'nullable|boolean',
            'remove_logo'            => 'nullable|boolean',
        ]);

        $data = [
            'public_page_enabled'    => $request->has('public_page_enabled'),
            'public_show_rates'      => $request->has('public_show_rates'),
            'public_show_all_rules'  => $request->has('public_show_all_rules'),
            'public_show_pricing_detail' => $request->has('public_show_pricing_detail'),
            'public_layout'          => $request->input('public_layout') === 'split' ? 'split' : 'stacked',
            'public_headline'        => $request->input('public_headline'),
            'public_blurb'           => $request->input('public_blurb'),
            'public_accent_color'    => $request->input('public_accent_color') ?: null,
            'public_overlay_opacity' => (int) $request->input('public_overlay_opacity', 55),
            'public_footer_text'     => $request->input('public_footer_text'),
            'public_logo_style'      => in_array($request->input('public_logo_style'), ['dark', 'none', 'light'], true) ? $request->input('public_logo_style') : 'dark',
        ];

        // Background: store new first, then drop the old (no orphan pointer).
        if ($request->hasFile('public_background')) {
            try {
                $new = $publicService->storeImage($request->file('public_background'), 'background');
            } catch (\Throwable $e) {
                Log::warning('[Buyback Manager] public background upload failed: ' . $e->getMessage());
                return back()->withInput()->with('error', 'Background upload failed: ' . $e->getMessage());
            }
            $publicService->deleteImage($setting->public_background_path);
            $data['public_background_path'] = $new;
        } elseif ($request->boolean('remove_background')) {
            $publicService->deleteImage($setting->public_background_path);
            $data['public_background_path'] = null;
        }

        if ($request->hasFile('public_logo')) {
            try {
                $new = $publicService->storeImage($request->file('public_logo'), 'logo');
            } catch (\Throwable $e) {
                Log::warning('[Buyback Manager] public logo upload failed: ' . $e->getMessage());
                return back()->withInput()->with('error', 'Logo upload failed: ' . $e->getMessage());
            }
            $publicService->deleteImage($setting->public_logo_path);
            $data['public_logo_path'] = $new;
        } elseif ($request->boolean('remove_logo')) {
            $publicService->deleteImage($setting->public_logo_path);
            $data['public_logo_path'] = null;
        }

        $setting->update($data);

        return redirect()->route('buyback-manager.settings.public.edit', $setting->id)
            ->with('success', 'Public page settings saved.');
    }

    public function rules(int $settingId)
    {
        $setting = BuybackSetting::with(['pricingRules'])->findOrFail($settingId);
        $categories = InvCategory::orderBy('categoryName')->get();
        $groups = InvGroup::orderBy('groupName')->get();

        $ruleTypeIds = [
            'category' => $setting->pricingRules->where('type', 'category')->pluck('type_id')->all(),
            'group' => $setting->pricingRules->where('type', 'group')->pluck('type_id')->all(),
            'item' => $setting->pricingRules->where('type', 'item')->pluck('type_id')->all(),
        ];

        $categoryNames = InvCategory::whereIn('categoryID', $ruleTypeIds['category'])
            ->pluck('categoryName', 'categoryID');

        $groupNames = InvGroup::whereIn('groupID', $ruleTypeIds['group'])
            ->pluck('groupName', 'groupID');

        $typeNames = \Seat\Eveapi\Models\Sde\InvType::whereIn('typeID', $ruleTypeIds['item'])
            ->pluck('typeName', 'typeID');

        return view('buyback-manager::settings.rules', compact(
            'setting',
            'categories',
            'groups',
            'categoryNames',
            'groupNames',
            'typeNames'
        ));
    }

    public function storeRule(Request $request, int $settingId)
    {
        $setting = BuybackSetting::findOrFail($settingId);

        $validated = $request->validate([
            'type' => 'required|in:category,group,item',
            'type_id' => 'required|integer',
            'percentage' => 'nullable|numeric|min:0|max:100',
            'price_side' => 'nullable|in:buy,sell,split',
            'excluded' => 'boolean',
            'featured' => 'boolean',
            'priority' => 'nullable|integer',
        ]);

        $validated['setting_id'] = $setting->id;
        $validated['excluded'] = $request->has('excluded');
        $validated['featured'] = $request->has('featured');

        // Empty-string -> null for the optional price_side selector
        // (null means "use the corp's default side preference").
        if (empty($validated['price_side'])) {
            $validated['price_side'] = null;
        }

        if (! $request->filled('priority')) {
            $validated['priority'] = match ($validated['type']) {
                'item' => 30,
                'group' => 20,
                'category' => 10,
                default => 0,
            };
        }

        BuybackPricingRule::create($validated);

        return redirect()->route('buyback-manager.settings.rules', $settingId)
            ->with('success', 'Pricing rule added successfully');
    }

    public function destroyRule(int $settingId, int $ruleId)
    {
        $rule = BuybackPricingRule::where('setting_id', $settingId)
            ->where('id', $ruleId)
            ->firstOrFail();

        $rule->delete();

        return redirect()->route('buyback-manager.settings.rules', $settingId)
            ->with('success', 'Pricing rule deleted successfully');
    }

    /**
     * Sync Manager Core subscriptions when a setting transitions into or
     * out of the manager-core provider, or changes its MC market.
     *
     * The lazy-on-encounter pattern handles new-type subscriptions
     * during normal getPrices() calls — this method is purely for the
     * provider-switch case where the operator might want a forwarded
     * unsubscribe (turning off MC) or an eager re-subscribe (turning
     * MC on for a known type universe — currently a no-op since BB has
     * no curated type list, but kept here as a documented hook).
     */
    protected function reconcileManagerCoreSubscription(BuybackSetting $setting, ?string $previousProvider, ?string $previousMcMarket): void
    {
        $newProvider = $setting->price_provider;
        $movingToMc = $newProvider === PriceProviderService::PROVIDER_MANAGER_CORE
            && $previousProvider !== PriceProviderService::PROVIDER_MANAGER_CORE;
        $movingAwayFromMc = $previousProvider === PriceProviderService::PROVIDER_MANAGER_CORE
            && $newProvider !== PriceProviderService::PROVIDER_MANAGER_CORE;

        try {
            if ($movingAwayFromMc) {
                // Other corps in the same install may still use MC — only
                // unsubscribe if no other enabled setting uses MC.
                $stillInUse = BuybackSetting::where('id', '!=', $setting->id)
                    ->where('enabled', true)
                    ->where('price_provider', PriceProviderService::PROVIDER_MANAGER_CORE)
                    ->exists();

                if (! $stillInUse) {
                    $this->priceProvider->unsubscribeFromManagerCore();
                }
            }

            if ($movingToMc) {
                // No-op subscribe for BB — type universe is unbounded, so we
                // rely on lazy subscribe during the first contract sync.
                Log::info('[Buyback Manager] Corp ' . $setting->corporation_id . ' switched to Manager Core provider. Types will subscribe on first contract sync.');
            }

            // Register/refresh BB's pricing preference with MC whenever MC is
            // the provider. This makes Buyback Manager APPEAR in MC's Pricing
            // Preferences admin UI so an operator can override the MARKET
            // there (BB's resolveMcMarket honours admin_overridden rows).
            //
            // We register price_type='sell' as a placeholder — BB never
            // consumes the preference's price_type because it always fetches
            // BOTH sides and picks buy/sell/split per pricing rule. Only the
            // MARKET portion of the preference is meaningful to BB. The notes
            // string documents this in the MC UI so admins aren't confused.
            if ($newProvider === PriceProviderService::PROVIDER_MANAGER_CORE
                && ManagerCoreIntegration::isAvailable()) {
                $bridge = ManagerCoreIntegration::bridge();
                $bridge->call(
                    'ManagerCore',
                    'pricing.registerPreference',
                    'buyback-manager',
                    $setting->manager_core_market ?: 'jita',
                    'sell',
                    'Market only. Buyback Manager fetches both buy+sell and picks the side per pricing rule, so the price_type here is ignored. Override the market to change where BB prices contracts.'
                );
            }
        } catch (\Throwable $e) {
            // Don't let MC-sync hiccups break the settings save.
            Log::warning('[Buyback Manager] MC subscription reconcile failed: ' . $e->getMessage());
        }
    }
}
