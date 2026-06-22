<?php

namespace BuybackManager\Http\Controllers;

use BuybackManager\Integrations\ManagerCoreIntegration;
use BuybackManager\Jobs\SyncContracts;
use BuybackManager\Models\BuybackContract;
use BuybackManager\Models\BuybackContractItem;
use BuybackManager\Models\BuybackPriceCache;
use BuybackManager\Models\BuybackPricingRule;
use BuybackManager\Models\BuybackSetting;
use BuybackManager\Models\BuybackSubscribedType;
use BuybackManager\Services\Pricing\PriceProviderService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Seat\Eveapi\Models\Contracts\ContractDetail;
use Seat\Eveapi\Models\Corporation\CorporationInfo;
use Seat\Eveapi\Models\Sde\InvType;
use Seat\Web\Http\Controllers\Controller;

/**
 * Admin-only diagnostic page for Buyback Manager.
 *
 * Six tabs following the canonical structure:
 *   1. Health Checks        — at-a-glance dashboard
 *   2. Master Test          — deep diagnostic + provider live-tests
 *   3. System Validation    — hardcoded constants + dependencies
 *   4. Settings Health      — per-corp settings audit
 *   5. Data Integrity       — DB consistency + cache staleness
 *   6. Contract Trace       — pick a contract, walk the pricing pipeline
 *
 * Cheap tabs (Health Checks, Master Test) render eagerly. Heavy tabs
 * (System Validation, Settings Health, Data Integrity, Contract Trace)
 * lazy-load via ?diag_tab=X redirect to keep cold page-loads snappy.
 * All cached under bb:diag: namespace with per-tab TTLs.
 */
class DiagnosticController extends Controller
{
    /**
     * Tables the plugin owns. Used by health checks and data integrity.
     */
    private const PLUGIN_TABLES = [
        'buyback_settings',
        'buyback_pricing_rules',
        'buyback_offers',
        'buyback_offer_items',
        'buyback_contracts',
        'buyback_contract_items',
        'buyback_price_cache',
        'buyback_subscribed_types',
        'buyback_webhooks',
        'buyback_notification_log',
    ];

    /**
     * SeAT tables BB reads from. Used by System Validation.
     */
    private const REQUIRED_SEAT_TABLES = [
        'corporation_contracts',
        'character_contracts',
        'contract_details',
        'contract_items',
        'corporation_infos',
        'character_infos',
        'refresh_tokens',
        'character_affiliations',
        // SDE
        'invTypes',
        'invGroups',
        'invCategories',
    ];

    /**
     * MC PluginBridge capabilities BB depends on when MC is present.
     */
    private const REQUIRED_MC_CAPABILITIES = [
        'pricing.getPrices',
        'pricing.subscribeTypes',
        'pricing.unsubscribeTypes',
        'pricing.registerPreference',
        'appraisal.create',
        'events.publish',
    ];

    /**
     * Expected scheduled commands + their crons. Both are registered by
     * ScheduleSeeder; the health check verifies each is present with the
     * expected expression.
     */
    private const EXPECTED_SCHEDULES = [
        ['command' => 'buyback-manager:sync-contracts', 'expression' => '*/15 * * * *'],
        ['command' => 'buyback-manager:expire-offers', 'expression' => '*/5 * * * *'],
    ];

    protected PriceProviderService $priceProvider;

    public function __construct(PriceProviderService $priceProvider)
    {
        $this->priceProvider = $priceProvider;
    }

    /**
     * Render the diagnostic page. All cheap checks compute eagerly;
     * heavy tabs lazy-load when their tab is the active one.
     */
    public function index(Request $request)
    {
        $forceRefresh = (bool) $request->get('refresh', false);
        $activeTab = (string) $request->get('diag_tab', 'health');

        // FAST checks — every page load.
        $checks = [
            'environment' => $this->checkEnvironment(),
            'plugin_tables' => $this->checkPluginTables(),
            'seat_tables' => $this->checkSeatTables(),
            'schedule' => $this->checkSchedule(),
            'settings_summary' => $this->checkSettingsSummary(),
            'mc_integration' => $this->checkManagerCoreIntegration($forceRefresh),
            'price_cache' => $this->checkPriceCacheState(),
            'recent_activity' => $this->checkRecentActivity(),
            'event_log' => $this->checkEventLog($forceRefresh),
        ];

        $summary = $this->summarise($checks);

        // HEAVY checks — only computed when their tab is active.
        $systemValidation = null;
        $settingsHealth = null;
        $dataIntegrity = null;

        if ($activeTab === 'system-validation') {
            $systemValidation = $this->cached(
                'system_validation',
                1800,
                $forceRefresh,
                fn() => $this->buildSystemValidation()
            );
        }
        if ($activeTab === 'settings-health') {
            $settingsHealth = $this->cached(
                'settings_health',
                30,
                $forceRefresh,
                fn() => $this->buildSettingsHealth()
            );
        }
        if ($activeTab === 'data-integrity') {
            $dataIntegrity = $this->cached(
                'data_integrity',
                300,
                $forceRefresh,
                fn() => $this->buildDataIntegrity()
            );
        }

        // Contract Trace state.
        $traceContractId = (int) $request->get('contract_id', 0);
        $contractTrace = null;
        $contractCatalog = null;
        if ($activeTab === 'contract-trace' || $traceContractId > 0) {
            $contractCatalog = $this->cached(
                'contract_catalog',
                120,
                $forceRefresh,
                fn() => $this->buildContractCatalog()
            );
            if ($traceContractId > 0) {
                $contractTrace = $this->buildContractTrace($traceContractId);
            }
        }

        // Notification Testing state.
        $notificationTesting = null;
        if ($activeTab === 'notification-testing') {
            $notificationTesting = $this->cached(
                'notification_testing',
                30,
                $forceRefresh,
                fn() => $this->buildNotificationTesting()
            );
        }

        return view('buyback-manager::diagnostic.index', compact(
            'checks',
            'summary',
            'systemValidation',
            'settingsHealth',
            'dataIntegrity',
            'contractCatalog',
            'contractTrace',
            'traceContractId',
            'notificationTesting',
            'activeTab'
        ));
    }

    /**
     * Dispatch the contract-sync job on demand. Used by the "Sync Now"
     * button on the Health Checks tab to verify the pipeline without
     * waiting for the next 15-minute cron tick.
     */
    public function syncNow(Request $request)
    {
        try {
            SyncContracts::dispatch();
            return redirect()
                ->route('buyback-manager.diagnostic.index')
                ->with('success', 'Contract sync job dispatched to the queue. Check the Recent Activity card below in a few seconds.');
        } catch (\Throwable $e) {
            Log::error('[Buyback Manager] Diagnostic sync-now failed: ' . $e->getMessage());
            return redirect()
                ->route('buyback-manager.diagnostic.index')
                ->with('error', 'Failed to dispatch sync job: ' . $e->getMessage());
        }
    }

    // ============================================================
    // HEALTH CHECKS
    // ============================================================

    private function checkEnvironment(): array
    {
        $version = $this->resolveInstalledVersion();
        return [
            'status' => 'info',
            'message' => 'Buyback Manager ' . $version . ' on PHP ' . PHP_VERSION,
            'details' => [
                'Plugin version' => $version,
                'PHP version' => PHP_VERSION,
                'Laravel version' => app()->version(),
                'Queue driver' => config('queue.default'),
                'Cache driver' => config('cache.default'),
                'Timezone' => config('app.timezone'),
            ],
        ];
    }

    private function checkPluginTables(): array
    {
        $rows = [];
        $missing = [];
        foreach (self::PLUGIN_TABLES as $table) {
            if (! Schema::hasTable($table)) {
                $missing[] = $table;
                continue;
            }
            try {
                $count = DB::table($table)->count();
                $rows[] = ['table' => $table, 'rows' => $count];
            } catch (\Throwable $e) {
                $rows[] = ['table' => $table, 'rows' => '?', 'error' => $e->getMessage()];
            }
        }

        if (! empty($missing)) {
            return [
                'status' => 'error',
                'message' => 'Missing plugin tables: ' . implode(', ', $missing),
                'rows' => $rows,
                'missing' => $missing,
            ];
        }

        return [
            'status' => 'ok',
            'message' => count($rows) . ' plugin tables present',
            'rows' => $rows,
        ];
    }

    private function checkSeatTables(): array
    {
        $missing = array_values(array_filter(
            self::REQUIRED_SEAT_TABLES,
            fn($t) => ! Schema::hasTable($t)
        ));

        if (! empty($missing)) {
            return [
                'status' => 'error',
                'message' => 'Missing SeAT tables: ' . implode(', ', $missing),
                'details' => ['Missing' => $missing],
            ];
        }

        return [
            'status' => 'ok',
            'message' => 'All ' . count(self::REQUIRED_SEAT_TABLES) . ' required SeAT/SDE tables present',
            'details' => self::REQUIRED_SEAT_TABLES,
        ];
    }

    private function checkSchedule(): array
    {
        $details = [];
        $missing = [];
        $drift = [];

        foreach (self::EXPECTED_SCHEDULES as $expected) {
            $row = DB::table('schedules')
                ->where('command', $expected['command'])
                ->first();

            if (! $row) {
                $missing[] = $expected['command'];
                $details[$expected['command']] = 'NOT registered (expected ' . $expected['expression'] . ')';
                continue;
            }

            if ($row->expression !== $expected['expression']) {
                $drift[] = $expected['command'];
                $details[$expected['command']] = 'cron drift: expected ' . $expected['expression'] . ', got ' . $row->expression;
            } else {
                $details[$expected['command']] = $row->expression . ' (OK)';
            }
        }

        if (! empty($missing)) {
            return [
                'status' => 'error',
                'message' => 'Schedule(s) not registered: ' . implode(', ', $missing)
                    . '. Restart the SeAT container to re-run seeders.',
                'details' => $details,
            ];
        }

        if (! empty($drift)) {
            return [
                'status' => 'warn',
                'message' => 'Schedule cron drift on: ' . implode(', ', $drift),
                'details' => $details,
            ];
        }

        return [
            'status' => 'ok',
            'message' => count(self::EXPECTED_SCHEDULES) . ' schedules registered with expected cron',
            'details' => $details,
        ];
    }

    private function checkSettingsSummary(): array
    {
        $total = BuybackSetting::count();
        $enabled = BuybackSetting::where('enabled', true)->count();
        $byProvider = BuybackSetting::where('enabled', true)
            ->select('price_provider', DB::raw('COUNT(*) as n'))
            ->groupBy('price_provider')
            ->pluck('n', 'price_provider')
            ->all();

        if ($enabled === 0) {
            return [
                'status' => 'warn',
                'message' => 'No enabled buyback settings — plugin will do nothing until at least one corp is configured',
                'details' => ['Total settings' => $total, 'Enabled' => $enabled],
            ];
        }

        return [
            'status' => 'ok',
            'message' => $enabled . ' of ' . $total . ' settings enabled',
            'details' => array_merge(
                ['Total settings' => $total, 'Enabled' => $enabled],
                $this->prefixKeys($byProvider, 'Provider: ')
            ),
        ];
    }

    private function checkManagerCoreIntegration(bool $forceRefresh): array
    {
        if (! ManagerCoreIntegration::isAvailable()) {
            return [
                'status' => 'info',
                'message' => 'Manager Core is not installed (standalone mode)',
                'details' => [
                    'PluginBridge class' => 'not loaded',
                    'AppraisalService class' => 'not loaded',
                    'Standalone fallback' => 'Fuzzwork',
                ],
            ];
        }

        return $this->cached('mc_integration', 60, $forceRefresh, function () {
            try {
                $bridge = ManagerCoreIntegration::bridge();

                // Verify each capability against the LIVE bridge map via
                // hasCapability(), NOT bridge.discoverCapabilities(). The latter
                // reflects the persisted plugin_registry table, which only ever
                // holds CONSUMER plugins — Manager Core never self-registers a
                // registry row for itself, so its own capabilities are absent
                // there and every check false-reported as "missing / older MC".
                // hasCapability() reads the in-memory registry that call()
                // actually dispatches against, so it is the authoritative,
                // version-independent source of truth (the same map pricing uses).
                $caps = $this->probeMcCapabilities($bridge);

                if ($caps === null) {
                    return [
                        'status' => 'warn',
                        'message' => 'Manager Core present but its Plugin Bridge could not be introspected.',
                        'details' => ['Bridge' => 'unresolved or no hasCapability()'],
                    ];
                }

                $missing = $caps['missing'];

                $subscribedCount = 0;
                if (Schema::hasTable('buyback_subscribed_types')) {
                    $subscribedCount = BuybackSubscribedType::count();
                }

                $status = empty($missing) ? 'ok' : 'warn';
                $msg = empty($missing)
                    ? 'Manager Core available with all ' . count($caps['present']) . ' required capabilities'
                    : 'Manager Core missing ' . count($missing) . ' capability/capabilities — update Manager Core?';

                return [
                    'status' => $status,
                    'message' => $msg,
                    'details' => [
                        'Required by BB' => count(self::REQUIRED_MC_CAPABILITIES),
                        'Present on bridge' => count($caps['present']),
                        'Missing' => empty($missing) ? '—' : implode(', ', $missing),
                        'Subscribed types (BB ledger)' => $subscribedCount,
                    ],
                ];
            } catch (\Throwable $e) {
                return [
                    'status' => 'error',
                    'message' => 'Manager Core bridge call failed: ' . $e->getMessage(),
                    'details' => ['Error' => $e->getMessage()],
                ];
            }
        });
    }

    /**
     * Probe whether Manager Core's bridge exposes each capability BB requires,
     * using the LIVE in-memory registry (hasCapability) rather than the
     * persisted plugin_registry reflection (bridge.discoverCapabilities) — the
     * latter never lists Manager Core's OWN capabilities, so it would
     * false-report every one as missing.
     *
     * Returns ['present' => string[], 'missing' => string[]], or null when the
     * bridge cannot be introspected (unresolved, or an MC too old to expose
     * hasCapability()).
     */
    private function probeMcCapabilities($bridge): ?array
    {
        if ($bridge === null || ! method_exists($bridge, 'hasCapability')) {
            return null;
        }

        $present = [];
        $missing = [];
        foreach (self::REQUIRED_MC_CAPABILITIES as $cap) {
            if ($bridge->hasCapability('ManagerCore', $cap)) {
                $present[] = $cap;
            } else {
                $missing[] = $cap;
            }
        }

        return ['present' => $present, 'missing' => $missing];
    }

    private function checkPriceCacheState(): array
    {
        if (! Schema::hasTable('buyback_price_cache')) {
            return [
                'status' => 'warn',
                'message' => 'buyback_price_cache table missing — migrations may not have run',
            ];
        }

        $count = BuybackPriceCache::count();
        $oldest = BuybackPriceCache::min('cached_at');
        $newest = BuybackPriceCache::max('cached_at');
        $stale = $oldest
            ? BuybackPriceCache::where('cached_at', '<', Carbon::now()->subHours(24))->count()
            : 0;

        $status = $count === 0 ? 'info' : ($stale > $count * 0.5 ? 'warn' : 'ok');
        $message = $count === 0
            ? 'No prices cached yet (will populate on first appraisal or contract sync)'
            : "{$count} prices cached, {$stale} older than 24h";

        return [
            'status' => $status,
            'message' => $message,
            'details' => [
                'Total rows' => $count,
                'Oldest cached_at' => $oldest ?: '—',
                'Newest cached_at' => $newest ?: '—',
                'Stale (>24h)' => $stale,
            ],
        ];
    }

    private function checkRecentActivity(): array
    {
        $totalContracts = BuybackContract::count();
        $last24h = BuybackContract::where('updated_at', '>=', Carbon::now()->subDay())->count();
        $latestUpdate = BuybackContract::max('updated_at');

        $status = 'info';
        $message = 'No contracts synced yet';

        if ($totalContracts > 0) {
            if ($latestUpdate && Carbon::parse($latestUpdate)->gt(Carbon::now()->subHours(2))) {
                $status = 'ok';
                $message = "Contract sync active — last update {$latestUpdate}";
            } else {
                $status = 'warn';
                $message = "Last contract update {$latestUpdate} (expected every 15 min)";
            }
        }

        return [
            'status' => $status,
            'message' => $message,
            'details' => [
                'Total contracts tracked' => $totalContracts,
                'Updated in last 24h' => $last24h,
                'Most recent update' => $latestUpdate ?: '—',
            ],
        ];
    }

    private function checkEventLog(bool $forceRefresh): array
    {
        if (! ManagerCoreIntegration::isAvailable() || ! Schema::hasTable('manager_core_event_log')) {
            return [
                'status' => 'info',
                'message' => 'Event log unavailable (Manager Core not installed)',
            ];
        }

        return $this->cached('event_log', 60, $forceRefresh, function () {
            try {
                $recent = DB::table('manager_core_event_log')
                    ->where('publisher_plugin', 'buyback-manager')
                    ->where('created_at', '>=', Carbon::now()->subDay())
                    ->count();

                $byEvent = DB::table('manager_core_event_log')
                    ->where('publisher_plugin', 'buyback-manager')
                    ->where('created_at', '>=', Carbon::now()->subDay())
                    ->select('event_name', DB::raw('COUNT(*) as n'))
                    ->groupBy('event_name')
                    ->pluck('n', 'event_name')
                    ->all();

                return [
                    'status' => 'ok',
                    'message' => "{$recent} buyback.* events published in last 24h",
                    'details' => array_merge(
                        ['Total in last 24h' => $recent],
                        $byEvent
                    ),
                ];
            } catch (\Throwable $e) {
                return [
                    'status' => 'warn',
                    'message' => 'Could not read event log: ' . $e->getMessage(),
                ];
            }
        });
    }

    // ============================================================
    // SYSTEM VALIDATION (lazy)
    // ============================================================

    private function buildSystemValidation(): array
    {
        $items = [];

        // Constants
        $tritanium = InvType::where('typeID', 34)->first();
        $items[] = [
            'category' => 'Constants',
            'name' => 'Tritanium type (typeID=34)',
            'status' => $tritanium ? 'ok' : 'error',
            'message' => $tritanium ? $tritanium->typeName . ' resolves in SDE' : 'typeID 34 not found in invTypes',
        ];

        $items[] = [
            'category' => 'Constants',
            'name' => 'The Forge region (regionID=10000002)',
            'status' => 'info',
            'message' => 'Hardcoded as PriceProviderService::DEFAULT_REGION_ID',
        ];

        // PriceProviderService class
        $items[] = [
            'category' => 'Service classes',
            'name' => PriceProviderService::class,
            'status' => class_exists(PriceProviderService::class) ? 'ok' : 'error',
            'message' => class_exists(PriceProviderService::class) ? 'class loaded' : 'class missing',
        ];

        // MC capabilities (when MC present). Checked against the live bridge
        // map (hasCapability) — see probeMcCapabilities for why we do NOT use
        // bridge.discoverCapabilities here.
        if (ManagerCoreIntegration::isAvailable()) {
            try {
                $caps = $this->probeMcCapabilities(ManagerCoreIntegration::bridge());

                if ($caps === null) {
                    $items[] = [
                        'category' => 'MC capabilities',
                        'name' => 'Plugin Bridge',
                        'status' => 'warn',
                        'message' => 'Bridge present but could not be introspected (no hasCapability())',
                    ];
                } else {
                    $presentSet = array_flip($caps['present']);
                    foreach (self::REQUIRED_MC_CAPABILITIES as $cap) {
                        $present = isset($presentSet[$cap]);
                        $items[] = [
                            'category' => 'MC capabilities',
                            'name' => $cap,
                            'status' => $present ? 'ok' : 'warn',
                            'message' => $present ? 'callable on the bridge' : 'NOT registered — update Manager Core',
                        ];
                    }
                }
            } catch (\Throwable $e) {
                $items[] = [
                    'category' => 'MC capabilities',
                    'name' => 'capability probe',
                    'status' => 'error',
                    'message' => 'probe threw: ' . $e->getMessage(),
                ];
            }
        } else {
            $items[] = [
                'category' => 'MC capabilities',
                'name' => 'Manager Core',
                'status' => 'info',
                'message' => 'Not installed — capabilities not checked',
            ];
        }

        $statuses = array_column($items, 'status');
        $hasError = in_array('error', $statuses, true);
        $hasWarn = in_array('warn', $statuses, true);
        $overall = $hasError ? 'error' : ($hasWarn ? 'warn' : 'ok');

        return ['status' => $overall, 'items' => $items];
    }

    // ============================================================
    // SETTINGS HEALTH (lazy)
    // ============================================================

    private function buildSettingsHealth(): array
    {
        $settings = BuybackSetting::with('corporation')->get();
        $audit = [];

        foreach ($settings as $setting) {
            $issues = [];

            // Validate provider config
            $providerValid = $this->priceProvider->validateProviderConfig(
                $setting->price_provider ?? 'fuzzwork',
                $setting
            );
            if (! $providerValid) {
                $issues[] = 'Provider config invalid (missing API key or MC not installed?)';
            }

            // Base percentage
            $bp = (float) $setting->base_percentage;
            if ($bp < 0 || $bp > 100) {
                $issues[] = "base_percentage out of range: {$bp}";
            }

            // Janice checks
            if ($setting->price_provider === 'janice' && empty($setting->janice_api_key)) {
                $issues[] = 'Janice provider selected but API key empty';
            }

            // MC checks
            if ($setting->price_provider === 'manager-core') {
                if (! ManagerCoreIntegration::isAvailable()) {
                    $issues[] = 'MC provider selected but Manager Core not installed';
                }
                if (empty($setting->manager_core_market)) {
                    $issues[] = 'MC provider selected but manager_core_market is empty';
                }
            }

            // Rule count
            $ruleCount = BuybackPricingRule::where('setting_id', $setting->id)->count();

            $audit[] = [
                'id' => $setting->id,
                'corporation_id' => $setting->corporation_id,
                'corporation_name' => $setting->corporation->name ?? 'Unknown',
                'enabled' => (bool) $setting->enabled,
                'provider' => $setting->price_provider ?? 'fuzzwork',
                'base_percentage' => $setting->base_percentage,
                'rule_count' => $ruleCount,
                'fallback_to_jita' => (bool) $setting->fallback_to_jita,
                'status' => empty($issues) ? 'ok' : 'warn',
                'issues' => $issues,
            ];
        }

        $overall = 'ok';
        if (empty($audit)) {
            $overall = 'info';
        } elseif (in_array('warn', array_column($audit, 'status'), true)) {
            $overall = 'warn';
        }

        return ['status' => $overall, 'rows' => $audit];
    }

    // ============================================================
    // DATA INTEGRITY (lazy)
    // ============================================================

    private function buildDataIntegrity(): array
    {
        $issues = [];

        // Orphan contract items (FK pointing to deleted contracts)
        $orphanedItems = DB::table('buyback_contract_items as i')
            ->leftJoin('buyback_contracts as c', 'i.contract_id', '=', 'c.id')
            ->whereNull('c.id')
            ->count();
        $issues[] = [
            'table' => 'buyback_contract_items',
            'check' => 'Orphans (no matching buyback_contract)',
            'count' => $orphanedItems,
            'status' => $orphanedItems > 0 ? 'warn' : 'ok',
        ];

        // Orphan pricing rules
        $orphanedRules = DB::table('buyback_pricing_rules as r')
            ->leftJoin('buyback_settings as s', 'r.setting_id', '=', 's.id')
            ->whereNull('s.id')
            ->count();
        $issues[] = [
            'table' => 'buyback_pricing_rules',
            'check' => 'Orphans (no matching buyback_setting)',
            'count' => $orphanedRules,
            'status' => $orphanedRules > 0 ? 'warn' : 'ok',
        ];

        // Settings without a CorporationInfo
        $orphanedSettings = DB::table('buyback_settings as s')
            ->leftJoin('corporation_infos as ci', 's.corporation_id', '=', 'ci.corporation_id')
            ->whereNull('ci.corporation_id')
            ->count();
        $issues[] = [
            'table' => 'buyback_settings',
            'check' => 'Settings referencing missing corporations',
            'count' => $orphanedSettings,
            'status' => $orphanedSettings > 0 ? 'warn' : 'ok',
        ];

        // Stale price cache
        if (Schema::hasTable('buyback_price_cache')) {
            $stale = BuybackPriceCache::where('cached_at', '<', Carbon::now()->subDays(7))->count();
            $issues[] = [
                'table' => 'buyback_price_cache',
                'check' => 'Rows older than 7 days',
                'count' => $stale,
                'status' => $stale > 1000 ? 'warn' : 'ok',
            ];
        }

        // Subscribed-types ledger consistency vs MC's table (when MC present)
        if (ManagerCoreIntegration::isAvailable() && Schema::hasTable('manager_core_type_subscriptions')) {
            try {
                $bbLedger = BuybackSubscribedType::count();
                $mcSubs = DB::table('manager_core_type_subscriptions')
                    ->where('plugin_name', 'buyback-manager')
                    ->count();

                $delta = abs($bbLedger - $mcSubs);
                $issues[] = [
                    'table' => 'buyback_subscribed_types',
                    'check' => 'Local ledger vs MC subscriptions (drift)',
                    'count' => $delta,
                    'status' => $delta > 0 ? 'warn' : 'ok',
                    'note' => "BB ledger: {$bbLedger}, MC table: {$mcSubs}",
                ];
            } catch (\Throwable $e) {
                $issues[] = [
                    'table' => 'buyback_subscribed_types',
                    'check' => 'Local ledger vs MC subscriptions',
                    'count' => '?',
                    'status' => 'warn',
                    'note' => 'Could not query MC table: ' . $e->getMessage(),
                ];
            }
        }

        $overall = in_array('warn', array_column($issues, 'status'), true) ? 'warn' : 'ok';
        return ['status' => $overall, 'issues' => $issues];
    }

    // ============================================================
    // CONTRACT TRACE
    // ============================================================

    private function buildContractCatalog(): array
    {
        // Recent 50 contracts for the picker dropdown.
        return BuybackContract::with('corporation')
            ->orderBy('issued_date', 'desc')
            ->limit(50)
            ->get()
            ->map(fn($c) => [
                'id' => $c->id,
                'contract_id' => $c->contract_id,
                'corporation_id' => $c->corporation_id,
                'corporation_name' => $c->corporation->name ?? 'Unknown',
                'status' => $c->status,
                'total_value' => (float) $c->total_value,
                'items_count' => (int) $c->items_count,
                'issued_date' => optional($c->issued_date)->toDateTimeString(),
            ])
            ->all();
    }

    private function buildContractTrace(int $internalId): ?array
    {
        $contract = BuybackContract::with(['items', 'corporation', 'issuer'])->find($internalId);
        if (! $contract) {
            return ['error' => "No BuybackContract with id={$internalId}"];
        }

        // SeAT-side source
        $esiDetail = ContractDetail::with('lines')->where('contract_id', $contract->contract_id)->first();

        // Per-item resolution
        $itemTrace = [];
        $typeIds = $contract->items->pluck('type_id')->unique()->all();
        $typeMeta = InvType::with('group')->whereIn('typeID', $typeIds)->get()->keyBy('typeID');

        foreach ($contract->items as $item) {
            $type = $typeMeta->get($item->type_id);
            $itemTrace[] = [
                'type_id' => $item->type_id,
                'type_name' => $type?->typeName ?? 'Unknown',
                'group_id' => $item->group_id,
                'group_name' => $type?->group?->groupName ?? null,
                'category_id' => $item->category_id,
                'quantity' => $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'total_value' => (float) $item->total_value,
            ];
        }

        // EventBus events for this contract (when MC present)
        $events = [];
        if (ManagerCoreIntegration::isAvailable() && Schema::hasTable('manager_core_event_log')) {
            try {
                $rows = DB::table('manager_core_event_log')
                    ->where('publisher_plugin', 'buyback-manager')
                    ->where('event_name', 'like', 'buyback.contract.%')
                    ->orderBy('created_at', 'desc')
                    ->limit(20)
                    ->get();

                foreach ($rows as $row) {
                    $payload = json_decode($row->payload ?? '{}', true) ?: [];
                    if (($payload['contract_id'] ?? null) == $contract->contract_id) {
                        $events[] = [
                            'event_name' => $row->event_name,
                            'created_at' => $row->created_at,
                            'status' => $row->status ?? null,
                            'event_id' => $payload['event_id'] ?? null,
                            'previous_status' => $payload['previous_status'] ?? null,
                            'status_field' => $payload['status'] ?? null,
                        ];
                    }
                }
            } catch (\Throwable $e) {
                $events = ['_error' => $e->getMessage()];
            }
        }

        return [
            'contract' => $contract,
            'esi_detail' => $esiDetail,
            'items' => $itemTrace,
            'events' => $events,
        ];
    }

    // ============================================================
    // NOTIFICATION TESTING
    // ============================================================

    /**
     * Build the data shape for the Notification Testing tab: every
     * configured webhook with its scope, categories, enabled state, and
     * last-dispatch outcome, plus the 50 most recent dispatch log
     * entries from the last 24h.
     *
     * The tab's per-webhook Test button POSTs to the existing
     * WebhookController::testFire endpoint, which already handles the
     * synthetic event dispatch + dedup-ledger purge. No new endpoint
     * needed.
     */
    private function buildNotificationTesting(): array
    {
        $webhooks = \BuybackManager\Models\BuybackWebhook::with('corporation')
            ->orderBy('corporation_id')
            ->orderBy('name')
            ->get();

        // Pre-fetch each webhook's most-recent dispatch in one batch so
        // we don't N+1 against the notification log.
        $lastByWebhook = \BuybackManager\Models\BuybackNotificationLog::query()
            ->selectRaw('webhook_id, MAX(sent_at) as last_sent_at')
            ->whereIn('webhook_id', $webhooks->pluck('id'))
            ->groupBy('webhook_id')
            ->pluck('last_sent_at', 'webhook_id');

        $lastStatusByWebhook = [];
        if (! $lastByWebhook->isEmpty()) {
            $statusRows = \BuybackManager\Models\BuybackNotificationLog::query()
                ->where(function ($q) use ($lastByWebhook) {
                    foreach ($lastByWebhook as $wid => $sent) {
                        $q->orWhere(function ($qq) use ($wid, $sent) {
                            $qq->where('webhook_id', $wid)->where('sent_at', $sent);
                        });
                    }
                })
                ->select('webhook_id', 'status', 'sent_at')
                ->get();
            foreach ($statusRows as $row) {
                $lastStatusByWebhook[$row->webhook_id] = $row->status;
            }
        }

        $statusClass = fn($s) => match ($s) {
            'sent' => 'ok',
            'rate_limited' => 'warn',
            'failed' => 'error',
            default => 'info',
        };

        $webhookRows = $webhooks->map(function ($w) use ($lastByWebhook, $lastStatusByWebhook, $statusClass) {
            $lastSent = $lastByWebhook[$w->id] ?? null;
            $lastStatus = $lastStatusByWebhook[$w->id] ?? null;
            return [
                'id' => $w->id,
                'name' => $w->name,
                'corporation_name' => $w->corporation->name ?? null,
                'categories' => $w->categories ?? [],
                'enabled' => (bool) $w->enabled,
                'last_dispatch' => $lastSent,
                'last_dispatch_status' => $lastStatus,
                'last_dispatch_class' => $lastStatus ? $statusClass($lastStatus) : 'info',
            ];
        })->all();

        $recent = \BuybackManager\Models\BuybackNotificationLog::with('webhook')
            ->where('sent_at', '>=', \Carbon\Carbon::now()->subDay())
            ->orderBy('sent_at', 'desc')
            ->limit(50)
            ->get()
            ->map(fn($row) => [
                'sent_at' => (string) $row->sent_at,
                'webhook_name' => $row->webhook->name ?? ('#' . $row->webhook_id),
                'event_name' => $row->event_name,
                'status' => $row->status,
                'status_class' => $statusClass($row->status),
                'error' => $row->error,
            ])
            ->all();

        return [
            'webhooks' => $webhookRows,
            'recent' => $recent,
        ];
    }

    // ============================================================
    // HELPERS
    // ============================================================

    private function summarise(array $checks): array
    {
        $counts = ['ok' => 0, 'warn' => 0, 'error' => 0, 'info' => 0];
        foreach ($checks as $c) {
            $status = $c['status'] ?? 'info';
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }

        $overall = 'ok';
        if ($counts['error'] > 0) {
            $overall = 'error';
        } elseif ($counts['warn'] > 0) {
            $overall = 'warn';
        }

        return [
            'overall' => $overall,
            'counts' => $counts,
            'total' => count($checks),
        ];
    }

    private function cached(string $key, int $ttlSeconds, bool $forceRefresh, callable $compute)
    {
        $fullKey = "bb:diag:{$key}";
        try {
            if ($forceRefresh) {
                Cache::forget($fullKey);
            }
            return Cache::remember($fullKey, $ttlSeconds, $compute);
        } catch (\Throwable $e) {
            Log::warning("[Buyback Manager] Diagnostic cache failed for {$fullKey}: " . $e->getMessage());
            return $compute();
        }
    }

    private function resolveInstalledVersion(): string
    {
        try {
            $version = \Composer\InstalledVersions::getPrettyVersion('mattfalahe/buyback-manager');
            return $version ?: 'dev';
        } catch (\Throwable $e) {
            return 'unknown';
        }
    }

    private function prefixKeys(array $assoc, string $prefix): array
    {
        $out = [];
        foreach ($assoc as $k => $v) {
            $out[$prefix . $k] = $v;
        }
        return $out;
    }
}
