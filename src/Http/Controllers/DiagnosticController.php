<?php

namespace BuybackManager\Http\Controllers;

use BuybackManager\Integrations\ManagerCoreIntegration;
use BuybackManager\Jobs\ImportPackagedVolumes;
use BuybackManager\Jobs\SyncContracts;
use BuybackManager\Models\BuybackContract;
use BuybackManager\Models\BuybackContractItem;
use BuybackManager\Models\BuybackPackagedVolume;
use BuybackManager\Models\BuybackPriceCache;
use BuybackManager\Models\BuybackPricingRule;
use BuybackManager\Models\BuybackProgrammeAccess;
use BuybackManager\Models\BuybackSetting;
use BuybackManager\Models\BuybackSubscribedType;
use BuybackManager\Models\BuybackSyncStatus;
use BuybackManager\Services\ContractFeedHealth;
use BuybackManager\Services\DevBuildUpgrade;
use BuybackManager\Services\Pricing\PriceProviderService;
use BuybackManager\Services\ProgrammeAccess;
use BuybackManager\Services\SdeVolumes;
use BuybackManager\Services\VolumeLookup;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
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
        'buyback_manager_settings',
        'buyback_manager_pricing_rules',
        'buyback_manager_appraisals',
        'buyback_manager_appraisal_items',
        'buyback_manager_contracts',
        'buyback_manager_contract_items',
        'buyback_manager_price_cache',
        'buyback_manager_price_status',
        'buyback_manager_sync_status',
        'buyback_manager_subscribed_types',
        'buyback_manager_webhooks',
        'buyback_manager_notification_log',
        'buyback_manager_location_rules',
        'buyback_manager_packaged_volumes',
        'buyback_manager_programme_access',
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
        'alliances',
        'alliance_members',
        // SDE
        'invTypes',
        'invGroups',
        'invCategories',
        // SDE — what ore and ice refine into, for refining rules
        'invTypeMaterials',
        // SDE — location resolution for buyback location rules
        'mapDenormalize',
        'universe_structures',
    ];

    /**
     * Every MC PluginBridge capability BB calls, all of them in Manager Core
     * 1.2.0. Events go through its Topics, which are not a capability.
     */
    private const REQUIRED_MC_CAPABILITIES = [
        'pricing.getPrices',
        'pricing.subscribeTypes',
        'pricing.unsubscribeTypes',
        'pricing.registerPreference',
        'pricing.getPreferenceForPlugin',
        'pricing.listMarkets',
        'pricing.getVariantPrices',
        'pricing.subscribeVariants',
        'pricing.unsubscribeVariants',
        'pricing.getSubscribedTypeCount',
        'events.getRecentForPublisher',
    ];

    /**
     * The scheduled commands ScheduleSeeder adds, with the cron each ships
     * with. A missing one is an error. A different cron is the operator's
     * own change, which updates keep, so it is reported, not warned about.
     */
    private const EXPECTED_SCHEDULES = [
        ['command' => 'buyback-manager:sync-contracts', 'expression' => '*/15 * * * *'],
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
            'dev_build_leftovers' => $this->checkDevBuildLeftovers(),
            'seat_tables' => $this->checkSeatTables(),
            'schedule' => $this->checkSchedule(),
            'settings_summary' => $this->checkSettingsSummary(),
            'mc_integration' => $this->checkManagerCoreIntegration($forceRefresh),
            'price_providers' => $this->checkPriceProviders(),
            'price_cache' => $this->checkPriceCacheState(),
            'item_volumes' => $this->checkItemVolumes(),
            'contract_sync' => $this->checkContractSync(),
            'who_can_sell' => $this->checkWhoCanSell(),
            'appraisal_activity' => $this->checkAppraisalActivity(),
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
                ->with('success', 'Contract sync job dispatched to the queue. Check the Contract Sync card below in a few seconds.');
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

    /**
     * A development build from before 1.0.0 kept its data in buyback_*
     * tables, which sit beside 1.0.0's until the upgrade command clears them.
     * Plugin commands are only registered on the console, so the schedule
     * rows are judged against the schedules this release ships.
     */
    private function checkDevBuildLeftovers(): array
    {
        try {
            $found = (new DevBuildUpgrade())->inspect(array_column(self::EXPECTED_SCHEDULES, 'command'));
        } catch (\Throwable $e) {
            return ['status' => 'warn', 'message' => 'Could not look for development build tables: ' . $e->getMessage()];
        }

        $note = $found['seat_buyback'] ? ' buyback_contracts belongs to seat-buyback, a different plugin, and is not counted.' : '';
        if (DevBuildUpgrade::nothingLeft($found)) {
            return ['status' => 'ok', 'message' => 'Nothing left from a development build before 1.0.0.' . $note];
        }

        return [
            'status' => 'warn',
            'message' => 'A development build from before 1.0.0 left ' . count($found['tables']) . ' old table(s), '
                . $found['migrations'] . ' migration record(s) and ' . $found['schedules'] . ' schedule row(s) for commands that are gone. '
                . 'Run the upgrade command to carry its programme over or start fresh.' . $note,
            'details' => [
                'Command' => 'docker exec -it seat-docker-front-1 php artisan buyback-manager:upgrade-dev-build',
            ],
            'rows' => array_map(fn ($table, $rows) => ['table' => $table, 'rows' => $rows], array_keys($found['tables']), $found['tables']),
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
                $details[$expected['command']] = $row->expression . ' (ships as ' . $expected['expression'] . ')';
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
                'status' => 'info',
                'message' => 'Running on a cron changed from the one it ships with: ' . implode(', ', $drift)
                    . '. Updates keep your change.',
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
                'message' => 'No enabled buyback settings: plugin will do nothing until at least one corp is configured',
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
        if (ManagerCoreIntegration::isTooOld()) {
            return [
                'status' => 'warn',
                'message' => ManagerCoreIntegration::unavailableReason(),
                'details' => [
                    'Installed' => ManagerCoreIntegration::version() ?? 'unknown version',
                    'Needed' => ManagerCoreIntegration::MINIMUM_VERSION . ' or later',
                    'Price providers' => 'Fuzzwork, Janice',
                ],
            ];
        }

        if (! ManagerCoreIntegration::isAvailable()) {
            return [
                'status' => 'info',
                'message' => 'Manager Core is not installed (standalone mode)',
                'details' => [
                    'PluginBridge class' => 'not loaded',
                    'Price providers' => 'Fuzzwork, Janice',
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
                if (Schema::hasTable('buyback_manager_subscribed_types')) {
                    $subscribedCount = BuybackSubscribedType::count();
                }

                $status = empty($missing) ? 'ok' : 'warn';
                $msg = empty($missing)
                    ? 'Manager Core available with all ' . count($caps['present']) . ' required capabilities'
                    : 'Manager Core missing ' . count($missing) . ' capability/capabilities. Update Manager Core?';

                return [
                    'status' => $status,
                    'message' => $msg,
                    'details' => [
                        'Required by BB' => count(self::REQUIRED_MC_CAPABILITIES),
                        'Present on bridge' => count($caps['present']),
                        'Missing' => empty($missing) ? '-' : implode(', ', $missing),
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

    /**
     * How each enabled corporation's provider last did. A provider that is
     * down is a warning even with a fallback standing in, since quotes are
     * then priced somewhere other than where the corporation chose.
     */
    private function checkPriceProviders(): array
    {
        if (! Schema::hasTable('buyback_manager_price_status')) {
            return [
                'status' => 'warn',
                'message' => 'buyback_manager_price_status table missing: migrations may not have run',
            ];
        }

        $settings = BuybackSetting::with('corporation')->where('enabled', true)->get();
        if ($settings->isEmpty()) {
            return ['status' => 'info', 'message' => 'No enabled buyback settings, so nothing has been priced'];
        }

        $details = [];
        $failing = 0;
        $unknown = 0;

        foreach ($settings as $setting) {
            $name = ($setting->corporation->name ?? ('Corporation ' . $setting->corporation_id))
                . ' (' . $this->priceProvider->providerLabel($this->priceProvider->providerOf($setting)) . ')';
            $status = $this->priceProvider->providerStatus($setting);

            if ($status === null) {
                $unknown++;
                $details[$name] = 'no quote priced since the provider was set';
                continue;
            }

            if (! $status->failing) {
                $details[$name] = 'answering, last ' . optional($status->last_success)->diffForHumans();
                continue;
            }

            $failing++;
            $fallback = $status->fallback
                ? $this->priceProvider->providerLabel($status->fallback) . ($status->fallback_ok ? ' standing in' : ' not answering either')
                : 'no fallback';
            $details[$name] = 'not answering since ' . optional($status->since)->diffForHumans()
                . ': ' . Str::limit((string) $status->error, 160) . ' (' . $fallback . ')';
        }

        if ($failing > 0) {
            return [
                'status' => 'warn',
                'message' => $failing . ' of ' . $settings->count() . ' price providers not answering; quotes use the fallback provider or the last cached prices',
                'details' => $details,
            ];
        }

        return [
            'status' => $unknown === $settings->count() ? 'info' : 'ok',
            'message' => $unknown === $settings->count()
                ? 'No quote priced yet'
                : 'Every price provider answered its last quote',
            'details' => $details,
        ];
    }

    private function checkPriceCacheState(): array
    {
        if (! Schema::hasTable('buyback_manager_price_cache')) {
            return [
                'status' => 'warn',
                'message' => 'buyback_manager_price_cache table missing: migrations may not have run',
            ];
        }

        $count = BuybackPriceCache::count();
        $newest = BuybackPriceCache::max('checked_at');
        $recent = BuybackPriceCache::where('checked_at', '>=', Carbon::now()->subHours(24))->count();
        $markets = BuybackPriceCache::distinct()->count('market');

        // Rows only get old because nobody quoted those items lately, so an
        // old row is no fault: it is the price a quote falls back on.
        return [
            'status' => $count === 0 ? 'info' : 'ok',
            'message' => $count === 0
                ? 'No prices cached yet (fills on the first quote)'
                : "{$count} prices cached across {$markets} " . Str::plural('market', $markets) . ", {$recent} asked for in the last 24h",
            'details' => [
                'Total rows' => $count,
                'Markets' => $markets,
                'Asked for in the last 24h' => $recent,
                'Newest answer' => $newest ?: '-',
            ],
        ];
    }

    /**
     * Where the m3 on quotes, contracts and haul fees comes from. SeAT's SDE
     * tables have only the assembled volume, so ships, capital modules and
     * containers are read from SeAT's copy of CCP's static data.
     */
    private function checkItemVolumes(?string $directory = null): array
    {
        $feeLocations = Schema::hasTable('buyback_manager_location_rules')
            ? DB::table('buyback_manager_location_rules')->where('haul_fee_per_m3', '>', 0)->count()
            : 0;

        $downloaded = SdeVolumes::build($directory);
        $read = Schema::hasTable('buyback_manager_packaged_volumes') ? ImportPackagedVolumes::importedBuild() : null;
        $fromSde = $read === null ? 0 : BuybackPackagedVolume::where('source', BuybackPackagedVolume::SOURCE_SDE)->count();
        $fromOthers = Schema::hasTable('buyback_manager_packaged_volumes')
            ? BuybackPackagedVolume::where('source', '<>', BuybackPackagedVolume::SOURCE_SDE)->count()
            : 0;

        $details = [
            'Locations with a haul fee' => $feeLocations,
            "SeAT's static data" => $downloaded === null ? 'not found in storage/sde' : 'build ' . $downloaded,
            'Read by Buyback Manager' => $read === null
                ? 'not yet'
                : 'build ' . $read . ', ' . $fromSde . ' item types in ' . count((new VolumeLookup())->repackagingGroups()) . ' groups',
            'From Janice or ESI' => $fromOthers . ' item ' . Str::plural('type', $fromOthers),
        ];

        if ($downloaded === null && $read === null) {
            return [
                'status' => 'info',
                'message' => 'SeAT\'s copy of CCP\'s static data is not in storage/sde, so only ships are looked up, through Janice or one ESI call each. Capital modules and containers count at their assembled volume.',
                'details' => $details,
            ];
        }

        if ($downloaded !== null && $downloaded !== $read) {
            return [
                'status' => 'info',
                'message' => 'SeAT has static data build ' . $downloaded . ', which Buyback Manager has not read yet. The next contract sync queues it.',
                'details' => $details,
            ];
        }

        return [
            'status' => 'ok',
            'message' => 'Packaged volumes come from SeAT\'s copy of CCP\'s static data. ESI is asked only for an item newer than it.',
            'details' => $details,
        ];
    }

    /**
     * Who can sell to each programme. A seller's alliance is looked for in
     * SeAT's list of each alliance's member corporations before EveWho and
     * zKillboard, so an alliance SeAT holds none for sends every seller
     * online.
     */
    private function checkWhoCanSell(): array
    {
        $settings = BuybackSetting::with(['corporation', 'sellerAlliance'])->where('enabled', true)->get();
        if ($settings->every(fn (BuybackSetting $setting) => $setting->sellerScope() === BuybackSetting::SELLERS_ANY)) {
            return ['status' => 'info', 'message' => 'Every enabled programme buys from any corporation, so no seller is checked'];
        }

        $details = [];
        $unknownAlliances = [];
        foreach ($settings as $setting) {
            $name = $setting->corporation->name ?? ('Corporation #' . $setting->corporation_id);
            $line = $setting->sellerScopeLabel();

            if ($setting->sellerScope() === BuybackSetting::SELLERS_ALLIANCE) {
                $members = Schema::hasTable('alliance_members')
                    ? DB::table('alliance_members')->where('alliance_id', $setting->seller_alliance_id)->count()
                    : 0;
                $line .= ', ' . $members . ' member ' . Str::plural('corporation', $members) . ' in SeAT';
                if ($members === 0) {
                    $unknownAlliances[] = $name;
                }
            }

            $details[$name] = $line;
        }

        if ($unknownAlliances !== []) {
            return [
                'status' => 'warn',
                'message' => 'SeAT holds no member corporations for the alliance that can sell to ' . implode(', ', $unknownAlliances)
                    . ', so every seller is looked up on EveWho, then zKillboard. SeAT fills the list in with its scheduled alliance update.',
                'details' => $details,
            ];
        }

        return [
            'status' => 'ok',
            'message' => 'Contracts from anyone outside a programme\'s sellers are flagged for review. A seller\'s alliance comes from SeAT first, then EveWho, then zKillboard.',
            'details' => $details,
        ];
    }

    /**
     * Whether each programme's contract sync is reading its feed. Judged
     * from the sync's own record of each run, never from contract rows: a
     * programme with no buyback contracts yet has none to update, and a sync
     * failing on every run updates none either.
     */
    private function checkContractSync(): array
    {
        if (! Schema::hasTable('buyback_manager_sync_status')) {
            return [
                'status' => 'warn',
                'message' => 'buyback_manager_sync_status table missing: migrations may not have run',
            ];
        }

        $settings = BuybackSetting::with('corporation')->where('enabled', true)->get();
        if ($settings->isEmpty()) {
            return ['status' => 'info', 'message' => 'No programme is enabled, so nothing is synced'];
        }

        $statuses = BuybackSyncStatus::whereIn('setting_id', $settings->pluck('id'))->get()->keyBy('setting_id');
        $status = 'ok';
        $problems = [];
        $details = [];

        foreach ($settings as $setting) {
            $name = $setting->corporation->name ?? ('Corporation #' . $setting->corporation_id);
            $run = $statuses->get($setting->id);

            if ($run === null) {
                $status = $status === 'ok' ? 'info' : $status;
                $details[$name] = 'Not run yet';
                continue;
            }

            $line = 'Last good read ' . ($run->last_ok_at ? $run->last_ok_at->diffForHumans() : 'never')
                . ', ' . $run->contracts_seen . ' seen, ' . $run->contracts_new . ' new, ' . $run->contracts_updated . ' changed';
            if ($run->last_error) {
                $line .= '. Error: ' . Str::limit($run->last_error, 300);
            }
            $details[$name] = $line;

            // A sync can finish cleanly on tables SeAT has stopped filling, so
            // a good run alone does not mean contracts are coming in.
            if ($run->feed_failing) {
                $details[$name] .= '. Contracts not coming in since ' . optional($run->feed_since)->diffForHumans() . ': ' . $run->feed_reason;
            }

            if ($run->isStale()) {
                $status = 'warn';
                $problems[] = $name . ' has not read its contracts since ' . ($run->last_ok_at ? $run->last_ok_at->diffForHumans() : 'it was set up');
            } elseif ($run->feed_failing) {
                $status = 'warn';
                $problems[] = $name . '\'s sync runs, but SeAT is not reading its contracts';
            } elseif ($run->last_error) {
                $status = 'warn';
                $problems[] = $name . ' read its contracts, but one failed';
            }
        }

        $message = match (true) {
            $problems !== [] => implode('. ', $problems),
            $status === 'info' => 'Waiting for the first sync run (every 15 minutes)',
            default => 'Every programme read its contracts in the last ' . BuybackSyncStatus::STALE_AFTER_MINUTES . ' minutes',
        };

        return [
            'status' => $status,
            'message' => $message,
            'details' => $details + [
                'Contracts tracked' => BuybackContract::count(),
                'Keys that matched nothing' => BuybackContract::unknownKey()->count(),
            ],
        ];
    }

    /**
     * Appraisal funnel health. Appraisals are the front of the whole flow
     * now, so "are keys being generated, and do any of them turn into
     * contracts?" is the single most useful liveness signal.
     */
    private function checkAppraisalActivity(): array
    {
        if (! Schema::hasTable('buyback_manager_appraisals')) {
            return [
                'status' => 'warn',
                'message' => 'buyback_manager_appraisals table missing: migrations may not have run',
            ];
        }

        $total = DB::table('buyback_manager_appraisals')->count();
        $last24h = DB::table('buyback_manager_appraisals')
            ->where('created_at', '>=', Carbon::now()->subDay())
            ->count();
        $matched = DB::table('buyback_manager_appraisals')->whereNotNull('matched_contract_id')->count();
        $latest = DB::table('buyback_manager_appraisals')->max('created_at');

        $conversion = $total > 0 ? round(($matched / $total) * 100, 1) : 0;

        if ($total === 0) {
            $status = 'info';
            $message = 'No appraisals generated yet';
        } else {
            $status = 'ok';
            $message = "{$total} appraisals, {$matched} matched to a contract ({$conversion}%)";
        }

        return [
            'status' => $status,
            'message' => $message,
            'details' => [
                'Total appraisals' => $total,
                'Created in last 24h' => $last24h,
                'Matched to a contract' => $matched,
                'Conversion rate' => $conversion . '%',
                'Most recent' => $latest ?: '-',
            ],
        ];
    }

    /**
     * What Buyback published to Manager Core in the last day, asked of
     * Manager Core rather than read from its tables, which are its own to
     * change.
     */
    private function checkEventLog(bool $forceRefresh): array
    {
        $bridge = ManagerCoreIntegration::bridge();
        if (! $bridge) {
            return [
                'status' => 'info',
                'message' => 'Event log unavailable. ' . ManagerCoreIntegration::unavailableReason(),
            ];
        }

        return $this->cached('event_log', 60, $forceRefresh, function () use ($bridge) {
            try {
                $report = (array) $bridge->call('ManagerCore', 'events.getRecentForPublisher', 'buyback-manager', 24, 20);

                $total = 0;
                $byEvent = [];
                foreach ($report['counts'] ?? [] as $row) {
                    $total += (int) ($row['count'] ?? 0);
                    $byEvent[($row['event_name'] ?? '?') . ' (' . ($row['status'] ?? '?') . ')'] = (int) ($row['count'] ?? 0);
                }

                return [
                    'status' => 'ok',
                    'message' => "{$total} buyback events published in the last 24h",
                    'details' => ['Total in last 24h' => $total] + $byEvent,
                ];
            } catch (\Throwable $e) {
                return [
                    'status' => 'warn',
                    'message' => 'Could not read the event log: ' . $e->getMessage(),
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

        // Fuzzwork prices a hub at its station, so each hub's station id has
        // to still be a station in the SDE.
        $stationNames = DB::table('staStations')
            ->whereIn('stationID', array_values(PriceProviderService::HUB_STATIONS))
            ->pluck('stationName', 'stationID');
        foreach (PriceProviderService::HUB_STATIONS as $hub => $stationId) {
            $items[] = [
                'category' => 'Constants',
                'name' => ucfirst($hub) . " station (stationID={$stationId})",
                'status' => isset($stationNames[$stationId]) ? 'ok' : 'error',
                'message' => isset($stationNames[$stationId])
                    ? $stationNames[$stationId] . ' resolves in SDE'
                    : "stationID {$stationId} not found in staStations",
            ];
        }

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
                            'message' => $present ? 'callable on the bridge' : 'NOT registered: update Manager Core',
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
                'message' => 'Not installed: capabilities not checked',
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
        $access = (new ProgrammeAccess())->entries($settings->pluck('id')->all());
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
                    $issues[] = 'MC provider selected. ' . ManagerCoreIntegration::unavailableReason();
                }
                if (empty($setting->manager_core_market)) {
                    $issues[] = 'MC provider selected but manager_core_market is empty';
                }

                // A named provider that cannot price leaves every item to the
                // fallback; one an admin's override sets aside does nothing.
                if (! empty($setting->manager_core_provider)) {
                    $mcSource = $this->priceProvider->managerCoreSourceFor($setting);
                    $mcProblem = $this->priceProvider->managerCoreProviderProblem($setting);
                    if ($mcSource['overridden']) {
                        $issues[] = 'Manager Core\'s Pricing Preferences override Buyback Manager, so the provider named for this corporation is set aside';
                    } elseif ($mcProblem !== null) {
                        $issues[] = $mcProblem;
                    }
                }
            }

            // A fallback that was chosen but cannot run. "None" is a choice,
            // not a fault.
            $provider = $this->priceProvider->providerOf($setting);
            $fallback = $this->priceProvider->fallbackFor($setting, $provider, $this->priceProvider->marketFor($setting, $provider));
            $fallbackChoice = (string) ($setting->fallback_provider ?? PriceProviderService::PROVIDER_FUZZWORK);
            if ($fallback['provider'] === null && ! in_array($fallbackChoice, ['', PriceProviderService::FALLBACK_NONE, $provider], true)) {
                $issues[] = $fallback['why'];
            }

            $priceStatus = $this->priceProvider->providerStatus($setting);
            if ($priceStatus && $priceStatus->failing) {
                $issues[] = 'Provider not answering since ' . optional($priceStatus->since)->toDateTimeString() . ': ' . Str::limit((string) $priceStatus->error, 200);
            }

            // Checked now rather than read from the last sync, so a token
            // fixed a minute ago shows as fixed.
            $feedProblem = (new ContractFeedHealth())->problem($setting, BuybackSyncStatus::where('setting_id', $setting->id)->first());
            if ($feedProblem !== null) {
                $issues[] = 'Contracts not coming in: ' . $feedProblem['reason'] . ' ' . $feedProblem['advice'];
            }

            // A name on the programme that gives nobody anything: gone from
            // SeAT, or without the permission its access needs.
            $named = collect($access[$setting->id] ?? []);
            foreach ($named as $entry) {
                $who = ($entry['subject_type'] === BuybackProgrammeAccess::ROLE ? 'Role ' : 'Account ') . $entry['name'];
                if ($entry['missing']) {
                    $issues[] = $who . ' is named ' . $entry['access'] . ' but is no longer in SeAT';
                } elseif ($entry['lacks']) {
                    $issues[] = $who . ' is named ' . $entry['access'] . ' but lacks ' . $entry['lacks'];
                }
            }
            $managers = $named->where('access', BuybackProgrammeAccess::MANAGER)->count();
            $viewers = $named->where('access', BuybackProgrammeAccess::VIEWER)->count();

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
                'named' => $managers + $viewers === 0
                    ? 'nobody (admins only)'
                    : $managers . ' ' . ($managers === 1 ? 'manager' : 'managers') . ', ' . $viewers . ' ' . ($viewers === 1 ? 'viewer' : 'viewers'),
                'fallback' => $fallback['provider'] !== null
                    ? $this->priceProvider->providerLabel($fallback['provider']) . ($fallback['note'] ? ' (Jita)' : '')
                    : 'none',
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
        $orphanedItems = DB::table('buyback_manager_contract_items as i')
            ->leftJoin('buyback_manager_contracts as c', 'i.contract_id', '=', 'c.id')
            ->whereNull('c.id')
            ->count();
        $issues[] = [
            'table' => 'buyback_manager_contract_items',
            'check' => 'Orphans (no matching buyback_manager_contracts row)',
            'count' => $orphanedItems,
            'status' => $orphanedItems > 0 ? 'warn' : 'ok',
        ];

        // Orphan pricing rules
        $orphanedRules = DB::table('buyback_manager_pricing_rules as r')
            ->leftJoin('buyback_manager_settings as s', 'r.setting_id', '=', 's.id')
            ->whereNull('s.id')
            ->count();
        $issues[] = [
            'table' => 'buyback_manager_pricing_rules',
            'check' => 'Orphans (no matching buyback_manager_settings row)',
            'count' => $orphanedRules,
            'status' => $orphanedRules > 0 ? 'warn' : 'ok',
        ];

        // Orphan appraisal items (parent appraisal pruned or deleted).
        if (Schema::hasTable('buyback_manager_appraisal_items') && Schema::hasTable('buyback_manager_appraisals')) {
            $orphanedAppraisalItems = DB::table('buyback_manager_appraisal_items as ai')
                ->leftJoin('buyback_manager_appraisals as a', 'ai.appraisal_id', '=', 'a.id')
                ->whereNull('a.id')
                ->count();
            $issues[] = [
                'table' => 'buyback_manager_appraisal_items',
                'check' => 'Orphans (no matching buyback_manager_appraisals row)',
                'count' => $orphanedAppraisalItems,
                'status' => $orphanedAppraisalItems > 0 ? 'warn' : 'ok',
            ];

            // Retention health. The prune runs inside the sync cycle, so a
            // pile of rows well past the window means the sync is not
            // running rather than that the retention is misconfigured.
            $itemDays = (int) (BuybackSetting::max('appraisal_item_retention_days') ?: 14);
            $overdueItems = DB::table('buyback_manager_appraisal_items as ai')
                ->join('buyback_manager_appraisals as a', 'ai.appraisal_id', '=', 'a.id')
                ->where('a.created_at', '<', Carbon::now()->subDays($itemDays + 1))
                ->count();
            $issues[] = [
                'table' => 'buyback_manager_appraisal_items',
                'check' => "Rows past the {$itemDays}-day retention window",
                'count' => $overdueItems,
                'status' => $overdueItems > 0 ? 'warn' : 'ok',
                'note' => $overdueItems > 0 ? 'Prune runs inside buyback-manager:sync-contracts. Check the schedule is firing' : null,
            ];
        }

        // Orphan location rules
        if (Schema::hasTable('buyback_manager_location_rules')) {
            $orphanedLocations = DB::table('buyback_manager_location_rules as l')
                ->leftJoin('buyback_manager_settings as s', 'l.setting_id', '=', 's.id')
                ->whereNull('s.id')
                ->count();
            $issues[] = [
                'table' => 'buyback_manager_location_rules',
                'check' => 'Orphans (no matching buyback_manager_settings row)',
                'count' => $orphanedLocations,
                'status' => $orphanedLocations > 0 ? 'warn' : 'ok',
            ];
        }

        // Settings without a CorporationInfo
        $orphanedSettings = DB::table('buyback_manager_settings as s')
            ->leftJoin('corporation_infos as ci', 's.corporation_id', '=', 'ci.corporation_id')
            ->whereNull('ci.corporation_id')
            ->count();
        $issues[] = [
            'table' => 'buyback_manager_settings',
            'check' => 'Settings referencing missing corporations',
            'count' => $orphanedSettings,
            'status' => $orphanedSettings > 0 ? 'warn' : 'ok',
        ];

        // Price cache rows that answered with no price on either side. They
        // are kept so the item is not asked for again within the TTL, and
        // stand in for nothing.
        if (Schema::hasTable('buyback_manager_price_cache')) {
            $empty = BuybackPriceCache::whereNull('buy_price')->whereNull('sell_price')->count();
            $issues[] = [
                'table' => 'buyback_manager_price_cache',
                'check' => 'Items asked for that no provider has priced yet',
                'count' => $empty,
                'status' => 'ok',
                'note' => $empty > 0 ? 'Quotes list these as not accepted (no market price)' : null,
            ];
        }

        // The ledger against what Manager Core says it holds for Buyback.
        // The daily resend closes any gap, so one that lasts names a problem.
        $bridge = ManagerCoreIntegration::bridge();
        if ($bridge && $bridge->hasCapability('ManagerCore', 'pricing.getSubscribedTypeCount')) {
            try {
                $bbLedger = BuybackSubscribedType::count();
                $mcSubs = (int) $bridge->call('ManagerCore', 'pricing.getSubscribedTypeCount', 'buyback-manager');

                $delta = abs($bbLedger - $mcSubs);
                $issues[] = [
                    'table' => 'buyback_manager_subscribed_types',
                    'check' => 'Local ledger vs MC subscriptions (drift)',
                    'count' => $delta,
                    'status' => $delta > 0 ? 'warn' : 'ok',
                    'note' => "BB ledger: {$bbLedger}, Manager Core: {$mcSubs}"
                        . ($delta > 0 ? '. The next daily resend sends the ledger again.' : ''),
                ];
            } catch (\Throwable $e) {
                $issues[] = [
                    'table' => 'buyback_manager_subscribed_types',
                    'check' => 'Local ledger vs MC subscriptions',
                    'count' => '?',
                    'status' => 'warn',
                    'note' => 'Could not ask Manager Core: ' . $e->getMessage(),
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

        return [
            'contract' => $contract,
            'esi_detail' => $esiDetail,
            'items' => $itemTrace,
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
     * WebhookController::testFire endpoint, which posts to that webhook
     * alone. No new endpoint needed.
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
                'attempts' => (int) $row->attempts,
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
