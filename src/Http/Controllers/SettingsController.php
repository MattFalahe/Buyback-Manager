<?php

namespace BuybackManager\Http\Controllers;

use BuybackManager\Http\Controllers\Traits\ScopesCorporationAccess;
use BuybackManager\Integrations\ManagerCoreIntegration;
use BuybackManager\Models\BuybackLocationRule;
use BuybackManager\Models\BuybackPriceStatus;
use BuybackManager\Models\BuybackPricingRule;
use BuybackManager\Models\BuybackProgrammeAccess;
use BuybackManager\Models\BuybackSetting;
use BuybackManager\Models\BuybackSyncStatus;
use BuybackManager\Services\BuybackPublicService;
use BuybackManager\Services\ProgrammeAccess;
use BuybackManager\Services\TypeFinder;
use BuybackManager\Services\Pricing\PriceProviderService;
use BuybackManager\Services\Pricing\Reprocessing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Seat\Eveapi\Models\Corporation\CorporationInfo;
use Seat\Eveapi\Models\Sde\InvCategory;
use Seat\Eveapi\Models\Sde\InvGroup;
use Seat\Web\Http\Controllers\Controller;

class SettingsController extends Controller
{
    use ScopesCorporationAccess;

    /** Tritanium: traded at every hub, so any working provider prices it. */
    private const TEST_TYPE_ID = 34;

    /** What a programme's optional fields start at, matching the add form. */
    public const FIELD_DEFAULTS = [
        'max_deviation_percent' => 1.00,
        'thin_market_guard_percent' => 10.00,
        'appraisal_stale_hours' => 48,
        'auto_nudge_hours' => 48,
        'appraisal_item_retention_days' => 14,
        'appraisal_retention_days' => 180,
    ];

    protected PriceProviderService $priceProvider;

    public function __construct(PriceProviderService $priceProvider)
    {
        $this->priceProvider = $priceProvider;
    }

    public function index(Request $request)
    {
        $allowed = $this->manageableCorporationIds($request);
        $managesEveryCorporation = $allowed === null;

        $settings = BuybackSetting::with(['corporation', 'pricingRules'])
            ->when(! $managesEveryCorporation, fn ($query) => $query->whereIn('corporation_id', $allowed))
            ->get();
        // Any corporation can be where contracts go, so the target picker
        // lists them all. A webhook can only be scoped to a programme this
        // user manages.
        $corporations = CorporationInfo::orderBy('name')->get();
        $scopeCorporations = $managesEveryCorporation
            ? $corporations
            : $corporations->whereIn('corporation_id', $allowed)->values();

        $programmeAccess = new ProgrammeAccess();
        $access = $programmeAccess->entries($settings->pluck('id')->all());
        $roleOptions = $managesEveryCorporation ? $programmeAccess->roleNames() : [];
        $providers = $this->priceProvider->getAvailableProviders();
        $mcMarkets = $this->priceProvider->managerCoreMarkets();
        $mcProviders = collect($mcMarkets)->mapWithKeys(fn (array $market) => [$market['key'] => $market['providers']])->all();

        // Where each corporation prices, and how its provider last did.
        $pricing = [];
        foreach ($settings as $setting) {
            $market = $this->priceProvider->marketFor($setting);
            $pricing[$setting->id] = [
                'market' => $this->priceProvider->pricedAtName($market, $this->priceProvider->managerCoreProviderAt($setting, $market)),
                'status' => $this->priceProvider->providerStatus($setting),
                'mc_problem' => $this->priceProvider->providerOf($setting) === PriceProviderService::PROVIDER_MANAGER_CORE
                    ? $this->priceProvider->managerCoreProviderProblem($setting)
                    : null,
            ];
        }

        // An admin's market for Buyback in Manager Core's Pricing Preferences
        // wins over every corporation's own Manager Core market.
        $mcPreference = $this->priceProvider->managerCorePreference();
        $mcOverrideMarket = ! empty($mcPreference['admin_overridden']) && ! empty($mcPreference['market'])
            ? ($mcPreference['market_name'] ?? $mcPreference['market'])
            : null;

        // Webhook management is now embedded in the settings page (the
        // Discord Webhooks tab) instead of a separate redirect page.
        // A global webhook also posts for this user's corporations, so it is
        // listed, but only an admin may change it.
        $webhooks = \BuybackManager\Models\BuybackWebhook::with('corporation')
            ->when(! $managesEveryCorporation, fn ($query) => $query->where(
                fn ($scope) => $scope->whereNull('corporation_id')->orWhereIn('corporation_id', $allowed)
            ))
            ->orderBy('corporation_id')
            ->orderBy('name')
            ->get();
        $allCategories = \BuybackManager\Models\BuybackWebhook::ALL_CATEGORIES;
        // A global webhook's posts are about every corporation's contracts.
        $recentLog = \BuybackManager\Models\BuybackNotificationLog::query()
            ->when(! $managesEveryCorporation, fn ($query) => $query->whereIn(
                'webhook_id', $webhooks->whereNotNull('corporation_id')->pluck('id')->all()
            ))
            ->orderBy('sent_at', 'desc')
            ->limit(50)
            ->get();

        $roleProviderAvailable = \BuybackManager\Services\DiscordRoleResolver::isAvailable();
        $roleProviderLabel = \BuybackManager\Services\DiscordRoleResolver::providerLabel();
        $roleLookup = $roleProviderAvailable ? \BuybackManager\Services\DiscordRoleResolver::roleLookupMap() : [];

        $routingMap = $this->buildRoutingMap($webhooks);

        return view('buyback-manager::settings.index', compact(
            'settings',
            'corporations',
            'providers',
            'mcMarkets',
            'mcProviders',
            'pricing',
            'mcOverrideMarket',
            'webhooks',
            'allCategories',
            'recentLog',
            'roleProviderAvailable',
            'roleProviderLabel',
            'roleLookup',
            'routingMap',
            'managesEveryCorporation',
            'scopeCorporations',
            'access',
            'roleOptions'
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
        // Labels and descriptions come from the model so the routing map, the
        // webhook form and the dispatch log all name a category the same way.
        $catMeta = \BuybackManager\Models\BuybackWebhook::categoryMeta();

        $map = [];
        foreach (\BuybackManager\Models\BuybackWebhook::ALL_CATEGORIES as $cat) {
            $subs = $webhooks->filter(function ($w) use ($cat) {
                return $w->enabled && in_array($cat, $w->categories ?? [], true);
            })->values();

            $map[$cat] = [
                'label' => $catMeta[$cat]['label'] ?? $cat,
                'description' => $catMeta[$cat]['help'] ?? $cat,
                'events' => $catMeta[$cat]['events'] ?? [],
                'webhooks' => $subs,
            ];
        }

        return $map;
    }

    public function store(Request $request)
    {
        // Only an admin adds a programme, since only an admin names who runs it.
        if (! $request->filled('id')) {
            $this->authorizeAdmin($request, 'Only a Buyback Manager admin can add a buyback programme.');
        }

        $validated = $request->validate([
            'id' => 'nullable|integer|exists:buyback_manager_settings,id',
            'corporation_id' => 'required|exists:corporation_infos,corporation_id',
            'character_id' => 'nullable|exists:character_infos,character_id',
            'enabled' => 'boolean',
            // 'sometimes': the edit modal no longer carries this field, since
            // the default rate is managed on the Pricing Rules page. When it
            // is absent the stored value is left alone.
            'base_percentage' => 'sometimes|required|numeric|min:0|max:100',
            'price_source' => 'required|in:' . implode(',', array_keys(PriceProviderService::HUB_REGIONS)) . ',region',
            'region_id' => 'nullable|required_if:price_source,region|integer|min:1',
            // Provider config
            'price_provider' => 'required|in:fuzzwork,janice,manager-core',
            'fallback_provider' => 'required|in:fuzzwork,janice,manager-core,' . PriceProviderService::FALLBACK_NONE,
            'janice_api_key' => 'nullable|string|max:255',
            'forget_janice_api_key' => 'nullable|boolean',
            'janice_market' => 'nullable|in:' . implode(',', array_keys(PriceProviderService::JANICE_MARKETS)),
            'janice_price_method' => 'nullable|in:buy,sell,split',
            'manager_core_market' => 'nullable|string|max:64',
            'manager_core_provider' => 'nullable|string|max:32|regex:/^[a-z]+$/',
            'fallback_to_jita' => 'boolean',
            'price_cache_ttl_minutes' => 'nullable|integer|min:0|max:1440',
            // Contract target
            'target_type' => 'required|in:my_corp,corp,player',
            'target_corporation_id' => 'nullable|integer|exists:corporation_infos,corporation_id',
            'target_corporation_name' => 'nullable|string|max:255',
            'max_deviation_percent' => 'nullable|numeric|min:0|max:100',
            'thin_market_guard_percent' => 'nullable|numeric|min:0|max:1000',
            'appraisal_stale_hours' => 'nullable|integer|min:1|max:720',
            'auto_nudge_hours' => 'nullable|integer|min:0|max:720',
            'appraisal_item_retention_days' => 'nullable|integer|min:1|max:365',
            'appraisal_retention_days' => 'nullable|integer|min:1|max:3650',
        ]);

        // Both ends of an edit: the programme as it is, and the corporation
        // it is saved under, so nobody moves it elsewhere.
        $this->authorizeCorporation($request, (int) $validated['corporation_id']);
        if (! empty($validated['id'])) {
            $this->authorizedSetting($request, (int) $validated['id']);
        }

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
            // the contract instructions read nicely even if the free-text box
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

        $validated['enabled'] = $request->has('enabled');
        $validated['fallback_to_jita'] = $request->has('fallback_to_jita');

        // Capture the previous state so we can detect provider transitions
        // and reconcile Manager Core. The add form can also land on an
        // existing corporation, since it saves by corporation.
        $existing = ! empty($validated['id'])
            ? BuybackSetting::find($validated['id'])
            : BuybackSetting::where('corporation_id', $validated['corporation_id'])->first();
        $previousProvider = $existing?->price_provider;
        $validated = $this->withDefaults($validated, $request, $existing);

        // The page never holds the saved key, so a blank field keeps it.
        if ($request->boolean('forget_janice_api_key')) {
            $validated['janice_api_key'] = null;
        } elseif (! $request->filled('janice_api_key')) {
            unset($validated['janice_api_key']);
        }
        unset($validated['forget_janice_api_key']);

        $hasJaniceKey = array_key_exists('janice_api_key', $validated)
            ? ! empty($validated['janice_api_key'])
            : ! empty($existing?->janice_api_key);

        foreach (['price_provider' => 'The price provider', 'fallback_provider' => 'The fallback provider'] as $field => $what) {
            if ($validated[$field] === PriceProviderService::PROVIDER_JANICE && ! $hasJaniceKey) {
                return redirect()->back()
                    ->withInput()
                    ->with('error', $what . ' is Janice, which needs an API key.');
            }
        }

        // A provider cannot stand in for itself.
        if ($validated['fallback_provider'] === $validated['price_provider']) {
            $validated['fallback_provider'] = PriceProviderService::FALLBACK_NONE;
        }

        // Manager Core prices the corporation's market through the market's
        // own provider unless another is named, and another is saved only
        // where Manager Core can price the market through it.
        $via = $validated['price_provider'] === PriceProviderService::PROVIDER_MANAGER_CORE
            ? (string) ($validated['manager_core_provider'] ?? '')
            : '';
        if ($via !== '') {
            $mcMarket = (string) ($validated['manager_core_market'] ?: 'jita');
            $problem = $this->priceProvider->managerCoreProviderProblem(
                new BuybackSetting(['manager_core_market' => $mcMarket, 'manager_core_provider' => $via])
            );
            if ($problem !== null) {
                return redirect()->back()
                    ->withInput()
                    ->with('error', 'The Manager Core provider was not saved. ' . $problem);
            }

            if ($this->priceProvider->managerCoreProviderChoice($mcMarket, $via)['own'] ?? false) {
                $via = '';
            }
        }
        $validated['manager_core_provider'] = $via === '' ? null : $via;

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

        // A new provider starts with a clean record, so its first answer is
        // not announced as the old provider recovering.
        if ($previousProvider !== null && $previousProvider !== $setting->price_provider) {
            BuybackPriceStatus::where('setting_id', $setting->id)->delete();
        }

        $this->reconcileManagerCoreSubscription($setting, $previousProvider);

        return redirect()->route('buyback-manager.settings.index')
            ->with('success', 'Buyback settings saved successfully');
    }

    /**
     * Live-test the provider config currently entered in the form (does
     * NOT require saving). POST body shape mirrors the form fields plus
     * a `provider` selector. Returns JSON {success, message} for the
     * Test Connection button's inline status indicator.
     *
     * It asks the provider alone, changing nothing: no fallback, no cached
     * prices and no alert, so a test never hides a provider that is down.
     */
    public function testProvider(Request $request)
    {
        $request->validate([
            'provider' => 'required|in:fuzzwork,janice,manager-core',
            'id' => 'nullable|integer|exists:buyback_manager_settings,id',
            'price_source' => 'nullable|in:' . implode(',', array_keys(PriceProviderService::HUB_REGIONS)) . ',region',
            'region_id' => 'nullable|integer|min:1',
            'janice_api_key' => 'nullable|string|max:255',
            'janice_market' => 'nullable|in:' . implode(',', array_keys(PriceProviderService::JANICE_MARKETS)),
            'manager_core_market' => 'nullable|string|max:64',
            'manager_core_provider' => 'nullable|string|max:32|regex:/^[a-z]+$/',
        ]);

        // A blank key field means the saved key, which the page never holds.
        $saved = $request->filled('id') ? $this->authorizedSetting($request, (int) $request->input('id')) : null;
        $apiKey = $request->input('janice_api_key') ?: $saved?->janice_api_key;

        // Build a transient BuybackSetting (NOT persisted) so the price
        // provider can read the form's pricing fields without us having
        // to thread them through as a separate config bag.
        $transient = new BuybackSetting([
            'corporation_id' => 0,
            'enabled' => true,
            'base_percentage' => 100,
            'price_source' => $request->input('price_source', 'jita'),
            'region_id' => $request->input('region_id'),
            'price_provider' => $request->input('provider'),
            'janice_api_key' => $apiKey,
            'janice_market' => $request->input('janice_market', 'jita'),
            'manager_core_market' => $request->input('manager_core_market', 'jita'),
            'manager_core_provider' => $request->input('manager_core_provider'),
            'fallback_to_jita' => false,
        ]);

        $provider = $request->input('provider');
        $label = $this->priceProvider->providerLabel($provider);

        // With an admin's override in force the named provider is set aside,
        // so only the override is tested.
        if ($provider === PriceProviderService::PROVIDER_MANAGER_CORE
            && ! $this->priceProvider->managerCoreSourceFor($transient)['overridden']) {
            $problem = $this->priceProvider->managerCoreProviderProblem($transient);
            if ($problem !== null) {
                return response()->json(['success' => false, 'message' => $problem]);
            }
        }

        try {
            $answer = $this->priceProvider->tryProvider($provider, $transient, [self::TEST_TYPE_ID]);
            $via = $this->priceProvider->managerCoreProviderAt($transient, $answer['market']);
            $market = $this->priceProvider->pricedAtName($answer['market'], $via);
        } catch (\Throwable $e) {
            Log::warning('[Buyback Manager] Provider test failed: ' . $e->getMessage());

            return response()->json(['success' => false, 'message' => 'The test could not run. The SeAT log has the reason.']);
        }

        if ($answer['error'] !== null) {
            return response()->json([
                'success' => false,
                'message' => $label . ' did not answer: ' . Str::limit($answer['error'], 200),
            ]);
        }

        $sides = $answer['prices'][self::TEST_TYPE_ID] ?? null;
        if ($sides === null) {
            $message = "{$label} answered, but had no Tritanium price at {$market}.";
            if ($provider === PriceProviderService::PROVIDER_MANAGER_CORE) {
                // Manager Core fetches a provider's prices only for the items
                // a plugin subscribed to, on its own worker.
                $message = $via !== ''
                    ? 'Manager Core can price ' . $this->priceProvider->marketName($answer['market']) . ' through ' . $this->priceProvider->managerCoreProviderLabel($via)
                        . ', but holds no Tritanium price through it yet. Saving asks it to fetch the items your rules name, and each quote the items it prices.'
                    : "Manager Core answered, but holds no Tritanium price at {$market} yet. A quote asks it to fetch the items it needs.";
            }

            return response()->json(['success' => false, 'message' => $message]);
        }

        return response()->json([
            'success' => true,
            'message' => "Tritanium at {$market}: buy " . $this->isk($sides['buy']) . ', sell ' . $this->isk($sides['sell']),
        ]);
    }

    /**
     * A blank field takes its default. A field the form did not send at all
     * keeps what an existing programme has stored, so a form that leaves one
     * out cannot reset it to the default behind the operator's back.
     */
    protected function withDefaults(array $validated, Request $request, ?BuybackSetting $existing): array
    {
        foreach (self::FIELD_DEFAULTS as $field => $default) {
            if ($existing !== null && ! $request->has($field)) {
                unset($validated[$field]);
            } else {
                $validated[$field] = $validated[$field] ?? $default;
            }
        }

        return $validated;
    }

    public function destroy(Request $request, int $id)
    {
        $this->authorizeAdmin($request, 'Only a Buyback Manager admin can delete a buyback programme.');
        $setting = $this->authorizedSetting($request, $id);
        $usedManagerCore = $setting->price_provider === PriceProviderService::PROVIDER_MANAGER_CORE;

        // Everything keyed to the programme goes with it, or a programme added
        // later for the same corporation would sit beside rows nobody can
        // reach. Contracts and appraisals are kept by corporation, as the
        // record of what was bought.
        DB::transaction(function () use ($setting) {
            BuybackPricingRule::where('setting_id', $setting->id)->delete();
            BuybackLocationRule::where('setting_id', $setting->id)->delete();
            BuybackPriceStatus::where('setting_id', $setting->id)->delete();
            BuybackSyncStatus::where('setting_id', $setting->id)->delete();
            BuybackProgrammeAccess::where('setting_id', $setting->id)->delete();
            $setting->delete();
        });

        // The same rule as switching provider: Manager Core keeps Buyback's
        // items priced at a market only while an enabled corporation still
        // prices there.
        if ($usedManagerCore) {
            $this->priceProvider->dropUnusedManagerCoreMarkets();
        }

        return redirect()->route('buyback-manager.settings.index')
            ->with('success', 'Programme deleted, with its rules, locations and names');
    }

    protected function isk(?float $price): string
    {
        return $price === null ? 'no orders' : number_format($price, 2) . ' ISK';
    }

    /**
     * Show the public landing page editor for a corp's buyback setting.
     */
    public function editPublic(Request $request, int $id, BuybackPublicService $publicService)
    {
        $setting = $this->authorizedSetting($request, $id, ['corporation', 'targetCorporation', 'character']);
        $sharingLink = $publicService->sharingLink($setting);

        return view('buyback-manager::settings.public', compact('setting', 'sharingLink'));
    }

    /**
     * Persist the public landing page config + handle background/logo
     * uploads. Images follow HR Manager's pattern: store the new file
     * first, then delete the old, so a failed upload never leaves the row
     * pointing at a deleted file.
     */
    public function updatePublic(Request $request, int $id, BuybackPublicService $publicService)
    {
        $setting = $this->authorizedSetting($request, $id);

        $request->validate([
            'public_page_enabled'    => 'nullable|boolean',
            'public_show_rates'      => 'nullable|boolean',
            'public_show_all_rules'  => 'nullable|boolean',
            'public_show_pricing_detail' => 'nullable|boolean',
            'public_appraisal_enabled' => 'nullable|boolean',
            'public_layout'          => 'nullable|in:stacked,split',
            'public_headline'        => 'nullable|string|max:191',
            'public_blurb'           => 'nullable|string|max:2000',
            'public_accent_color'    => 'nullable|regex:/^#[0-9a-fA-F]{6}$/',
            'public_overlay_opacity' => 'nullable|integer|min:0|max:100',
            'public_footer_text'     => 'nullable|string|max:500',
            'public_closed_state'    => 'nullable|in:temporary,permanent,hidden',
            'public_closed_message'  => 'nullable|string|max:1000',
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
            'public_appraisal_enabled' => $request->has('public_appraisal_enabled'),
            'public_layout'          => $request->input('public_layout') === 'split' ? 'split' : 'stacked',
            'public_headline'        => $request->input('public_headline'),
            'public_blurb'           => $request->input('public_blurb'),
            'public_accent_color'    => $request->input('public_accent_color') ?: null,
            'public_overlay_opacity' => (int) $request->input('public_overlay_opacity', 55),
            'public_footer_text'     => $request->input('public_footer_text'),
            'public_logo_style'      => in_array($request->input('public_logo_style'), ['dark', 'none', 'light'], true) ? $request->input('public_logo_style') : 'dark',
            'public_closed_state'    => in_array($request->input('public_closed_state'), ['permanent', 'hidden'], true) ? $request->input('public_closed_state') : 'temporary',
            'public_closed_message'  => trim((string) $request->input('public_closed_message')) ?: null,
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

    public function rules(Request $request, int $settingId)
    {
        $setting = $this->authorizedSetting($request, $settingId, ['pricingRules']);
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

        // Split the rules into the two things they actually express, so the
        // page reads as "what we pay differently for" and "what we do not
        // buy" rather than one undifferentiated list.
        $exceptions = $setting->pricingRules
            ->where('excluded', false)
            ->sortByDesc('priority')
            ->values();

        $exclusions = $setting->pricingRules
            ->where('excluded', true)
            ->sortByDesc('priority')
            ->values();

        return view('buyback-manager::settings.rules', compact(
            'setting',
            'categories',
            'groups',
            'categoryNames',
            'groupNames',
            'typeNames',
            'exceptions',
            'exclusions'
        ));
    }

    /**
     * Save the default-rate block on the Pricing Rules page: the flat rate
     * every unlisted item is bought at, and whether the programme buys
     * everything or only the items listed as price exceptions.
     */
    public function updateRuleDefaults(Request $request, int $settingId)
    {
        $setting = $this->authorizedSetting($request, $settingId);

        $validated = $request->validate([
            'base_percentage' => 'required|numeric|min:0|max:100',
            'refine_yield' => 'required|numeric|min:1|max:100',
        ]);

        $setting->update([
            'base_percentage' => $validated['base_percentage'],
            'refine_yield' => $validated['refine_yield'],
            'buy_listed_only' => $request->has('buy_listed_only'),
        ]);

        return redirect()->route('buyback-manager.settings.rules', $setting->id)
            ->with('success', 'Default rate and refining yield saved.');
    }

    /**
     * The rule form's finder: items, groups and categories by name or id,
     * each item with the group and category it belongs to.
     */
    public function searchTypes(Request $request, TypeFinder $finder)
    {
        $request->validate(['q' => 'required|string|max:100']);

        return response()->json($finder->search((string) $request->input('q')));
    }

    public function storeRule(Request $request, int $settingId)
    {
        $setting = $this->authorizedSetting($request, $settingId);

        $validated = $request->validate([
            'type' => 'required|in:category,group,item',
            'type_id' => 'required|integer',
            'display_name' => 'nullable|string|max:100',
            'percentage' => 'nullable|numeric|min:0|max:100',
            'price_side' => 'nullable|in:buy,sell,split',
            'refine_yield' => 'nullable|numeric|min:1|max:100',
            'excluded' => 'boolean',
            'featured' => 'boolean',
        ]);

        $validated['setting_id'] = $setting->id;
        $validated['excluded'] = $request->has('excluded');
        $validated['featured'] = $request->has('featured');
        $validated['display_name'] = trim((string) ($validated['display_name'] ?? '')) ?: null;

        // Empty-string -> null for the optional price_side selector
        // (null means "use the corp's default side preference").
        if (empty($validated['price_side'])) {
            $validated['price_side'] = null;
        }

        // A rule that buys nothing pays on nothing, and a yield of its own
        // only means something on a rule that refines.
        $validated['refined'] = $request->has('refined') && ! $validated['excluded'];
        if (! $validated['refined'] || ($validated['refine_yield'] ?? '') === '') {
            $validated['refine_yield'] = null;
        }

        if ($validated['refined'] && $validated['refine_yield'] === null
            && ! app(Reprocessing::class)->inAsteroidCategory($validated['type'], (int) $validated['type_id'])) {
            return redirect()->back()
                ->withInput()
                ->with('error', 'The programme\'s refining yield is for ore, ice and moon ore. To refine anything else, give the rule a yield of its own: modules and ships reprocess for far less.');
        }

        // Only orders the rule lists; which rule applies is fixed by type.
        $validated['priority'] = match ($validated['type']) {
            'item' => 30,
            'group' => 20,
            'category' => 10,
        };

        // One rule per item, group or category: adding one again replaces it.
        $rule = BuybackPricingRule::updateOrCreate(
            [
                'setting_id' => $validated['setting_id'],
                'type' => $validated['type'],
                'type_id' => $validated['type_id'],
            ],
            $validated
        );

        $this->priceProvider->subscribeRuleItems($setting);

        return redirect()->route('buyback-manager.settings.rules', $settingId)
            ->with('success', $rule->wasRecentlyCreated ? 'Pricing rule added successfully' : 'Pricing rule replaced');
    }

    public function destroyRule(Request $request, int $settingId, int $ruleId)
    {
        $this->authorizedSetting($request, $settingId);

        $rule = BuybackPricingRule::where('setting_id', $settingId)
            ->where('id', $ruleId)
            ->firstOrFail();

        $rule->delete();

        return redirect()->route('buyback-manager.settings.rules', $settingId)
            ->with('success', 'Pricing rule deleted successfully');
    }

    /**
     * Bring Manager Core's subscriptions in line with a saved programme: a
     * market nobody prices at any more is dropped, the preference row that
     * lets an admin override the market is registered, and the items the
     * rules name are subscribed. Every other item is subscribed by the quote
     * that first prices it.
     */
    protected function reconcileManagerCoreSubscription(BuybackSetting $setting, ?string $previousProvider): void
    {
        $newProvider = $setting->price_provider;
        if ($newProvider !== PriceProviderService::PROVIDER_MANAGER_CORE
            && $previousProvider !== PriceProviderService::PROVIDER_MANAGER_CORE) {
            return;
        }

        try {
            // Register/refresh BB's pricing preference with MC whenever MC is
            // the provider. This makes Buyback Manager APPEAR in MC's Pricing
            // Preferences admin UI so an operator can override it there
            // centrally: an admin's market, and its provider override if it
            // has one, replace every corporation's own (managerCoreSourceFor).
            //
            // We register price_type='sell' as a placeholder — BB never
            // consumes the preference's price_type because it always fetches
            // BOTH sides and picks buy/sell/split per pricing rule. The notes
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
                    'Buyback Manager prices both sides and picks the side per pricing rule, so the price type here is ignored. Saving here replaces every corporation\'s own Manager Core market and provider in Buyback Manager with this market, and this provider override if one is set.'
                );
            }

            $this->priceProvider->dropUnusedManagerCoreMarkets();
            $this->priceProvider->subscribeRuleItems($setting);
        } catch (\Throwable $e) {
            // Don't let MC-sync hiccups break the settings save.
            Log::warning('[Buyback Manager] MC subscription reconcile failed: ' . $e->getMessage());
        }
    }
}
