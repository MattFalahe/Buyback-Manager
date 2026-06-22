@extends('web::layouts.grids.12')

@section('title', 'Buyback Manager - Diagnostics')
@section('page_header', 'Buyback Manager - Diagnostics')

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/buyback-manager/css/buyback-manager.css') }}?v=2">
<style>
    /* Diagnostic page — bespoke chrome, scoped to
       .buyback-manager-wrapper.diagnostic-page so it never leaks. The
       diag-* primitives match the canonical reference layout in SM. */

    .buyback-manager-wrapper.diagnostic-page .diag-tabs {
        display: flex;
        gap: 0;
        border-bottom: 2px solid #454d55;
        margin: 1.5rem 0 1.5rem 0;
        padding: 0;
        list-style: none;
        flex-wrap: wrap;
    }
    .buyback-manager-wrapper.diagnostic-page .diag-tab {
        padding: 0.6rem 1.2rem;
        color: #8b95a5;
        cursor: pointer;
        border-bottom: 3px solid transparent;
        font-weight: 500;
        font-size: 0.9rem;
        transition: all 0.15s;
        user-select: none;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    .buyback-manager-wrapper.diagnostic-page .diag-tab:hover {
        color: #c2c7d0;
        border-bottom-color: #3a4049;
    }
    .buyback-manager-wrapper.diagnostic-page .diag-tab.active {
        color: #6366f1;
        border-bottom-color: #6366f1;
    }
    .buyback-manager-wrapper.diagnostic-page .diag-tab-pane {
        display: none;
    }
    .buyback-manager-wrapper.diagnostic-page .diag-tab-pane.active {
        display: block;
    }

    .buyback-manager-wrapper.diagnostic-page .diag-loading {
        padding: 2rem;
        text-align: center;
        color: #94a3b8;
        font-size: 1rem;
    }
    .buyback-manager-wrapper.diagnostic-page .diag-loading i {
        margin-right: 0.5rem;
        color: #6366f1;
    }

    /* Tab intro box — mandatory per the diagnostic standard. */
    .buyback-manager-wrapper.diagnostic-page .diag-tab-intro {
        padding: 0.85rem 1.1rem;
        background: rgba(99, 102, 241, 0.08);
        border-left: 3px solid #6366f1;
        border-radius: 5px;
        margin-bottom: 1.25rem;
        color: #c2c7d0;
        font-size: 0.92rem;
        line-height: 1.5;
    }
    .buyback-manager-wrapper.diagnostic-page .diag-tab-intro strong {
        color: #c7d2fe;
    }
    .buyback-manager-wrapper.diagnostic-page .diag-tab-intro p {
        margin-bottom: 0.4rem;
    }
    .buyback-manager-wrapper.diagnostic-page .diag-tab-intro p:last-child {
        margin-bottom: 0;
    }
    .buyback-manager-wrapper.diagnostic-page .diag-tab-intro code {
        color: #a5b4fc;
        background: rgba(0, 0, 0, 0.25);
        padding: 0 0.25rem;
        border-radius: 3px;
    }

    .buyback-manager-wrapper.diagnostic-page .diag-section {
        background: #2a2f3a;
        border: 1px solid #454d55;
        border-radius: 8px;
        margin-bottom: 1.5rem;
        overflow: hidden;
    }
    .buyback-manager-wrapper.diagnostic-page .diag-section-header {
        padding: 0.8rem 1.2rem;
        background: #343a45;
        border-bottom: 1px solid #454d55;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
    .buyback-manager-wrapper.diagnostic-page .diag-section-title {
        margin: 0;
        font-size: 1.05rem;
        font-weight: 600;
        color: #fff;
    }
    .buyback-manager-wrapper.diagnostic-page .diag-section-body {
        padding: 1.2rem;
        color: #c2c7d0;
    }

    /* Semantic status badges — DO NOT CHANGE colors */
    .buyback-manager-wrapper.diagnostic-page .diag-badge {
        font-size: 0.78rem;
        font-weight: 700;
        padding: 0.25rem 0.55rem;
        border-radius: 0.25rem;
        letter-spacing: 0.04em;
        text-transform: uppercase;
    }
    .buyback-manager-wrapper.diagnostic-page .diag-badge.ok      { background: #1c6f3e; color: #d4f4e2; }
    .buyback-manager-wrapper.diagnostic-page .diag-badge.warn,
    .buyback-manager-wrapper.diagnostic-page .diag-badge.warning { background: #7a5a0f; color: #fff1c7; }
    .buyback-manager-wrapper.diagnostic-page .diag-badge.error,
    .buyback-manager-wrapper.diagnostic-page .diag-badge.danger  { background: #7a1d2b; color: #fbd5db; }
    .buyback-manager-wrapper.diagnostic-page .diag-badge.info    { background: #1d4d7a; color: #d0e4fb; }

    .buyback-manager-wrapper.diagnostic-page .diag-msg {
        margin: 0;
        color: #e2e8f0;
        font-size: 0.95rem;
    }

    .buyback-manager-wrapper.diagnostic-page .diag-detail-table {
        width: 100%;
        margin-top: 0.8rem;
        font-size: 0.85rem;
        border-collapse: collapse;
    }
    .buyback-manager-wrapper.diagnostic-page .diag-detail-table th,
    .buyback-manager-wrapper.diagnostic-page .diag-detail-table td {
        padding: 0.4rem 0.65rem;
        border-bottom: 1px solid #3a3f4a;
        color: #c2c7d0;
        text-align: left;
        vertical-align: top;
    }
    .buyback-manager-wrapper.diagnostic-page .diag-detail-table th {
        color: #8b95a5;
        font-weight: 600;
        text-transform: uppercase;
        font-size: 0.72rem;
        letter-spacing: 0.05em;
    }
    .buyback-manager-wrapper.diagnostic-page .diag-detail-table tr.row-ok td   { }
    .buyback-manager-wrapper.diagnostic-page .diag-detail-table tr.row-warn td { background: rgba(122, 90, 15, 0.08); }
    .buyback-manager-wrapper.diagnostic-page .diag-detail-table tr.row-error td { background: rgba(122, 29, 43, 0.10); }

    .buyback-manager-wrapper.diagnostic-page .diag-kv {
        display: grid;
        grid-template-columns: max-content 1fr;
        gap: 0.4rem 1rem;
        font-size: 0.9rem;
    }
    .buyback-manager-wrapper.diagnostic-page .diag-kv dt { color: #8b95a5; font-weight: 500; }
    .buyback-manager-wrapper.diagnostic-page .diag-kv dd { margin: 0; color: #e2e8f0; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }

    .buyback-manager-wrapper.diagnostic-page .diag-summary {
        padding: 1rem 1.25rem;
        border-radius: 8px;
        margin-bottom: 1.5rem;
        border: 1px solid #454d55;
        background: #2a2f3a;
        color: #e2e8f0;
    }
    .buyback-manager-wrapper.diagnostic-page .diag-summary.ok    { border-left: 4px solid #22c55e; }
    .buyback-manager-wrapper.diagnostic-page .diag-summary.warn  { border-left: 4px solid #eab308; }
    .buyback-manager-wrapper.diagnostic-page .diag-summary.error { border-left: 4px solid #ef4444; }

    .buyback-manager-wrapper.diagnostic-page code {
        background: rgba(0,0,0,0.4);
        color: #8be9fd;
        padding: 0.12em 0.3em;
        border-radius: 3px;
        font-size: 0.88em;
    }
</style>
@endpush

@section('content')
<div class="buyback-manager-wrapper diagnostic-page">

    {{-- Flash messages --}}
    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    {{-- Top-of-page summary --}}
    <div class="diag-summary {{ $summary['overall'] }}">
        <strong>Diagnostic Summary:</strong>
        {{ $summary['counts']['ok'] }} OK, {{ $summary['counts']['warn'] }} warnings, {{ $summary['counts']['error'] }} errors across {{ $summary['total'] }} checks.
        <div style="margin-top:0.5rem;">
            <a href="{{ route('buyback-manager.diagnostic.index') }}?refresh=1" class="btn btn-xs btn-info">
                <i class="fa fa-sync"></i> Force refresh caches
            </a>
            <form action="{{ route('buyback-manager.diagnostic.sync-now') }}" method="POST" style="display:inline-block; margin-left:0.5rem;">
                @csrf
                <button type="submit" class="btn btn-xs btn-bb-primary">
                    <i class="fa fa-bolt"></i> Trigger contract sync now
                </button>
            </form>
        </div>
    </div>

    <div class="card card-dark">
        <div class="card-body">

            {{-- Tab navigation --}}
            <ul class="diag-tabs" role="tablist">
                <li class="diag-tab active" data-diag-target="health">
                    <i class="fas fa-heartbeat"></i> Health Checks
                </li>
                <li class="diag-tab" data-diag-target="master-test">
                    <i class="fas fa-rocket"></i> Master Test
                </li>
                <li class="diag-tab" data-diag-target="system-validation">
                    <i class="fas fa-shield-alt"></i> System Validation
                </li>
                <li class="diag-tab" data-diag-target="settings-health">
                    <i class="fas fa-sliders-h"></i> Settings Health
                </li>
                <li class="diag-tab" data-diag-target="data-integrity">
                    <i class="fas fa-database"></i> Data Integrity
                </li>
                <li class="diag-tab" data-diag-target="contract-trace">
                    <i class="fas fa-route"></i> Contract Trace
                </li>
                <li class="diag-tab" data-diag-target="notification-testing">
                    <i class="fas fa-paper-plane"></i> Notification Testing
                </li>
            </ul>

            {{-- ============================================================
                 HEALTH CHECKS
                 ============================================================ --}}
            <div class="diag-tab-pane active" data-diag-pane="health">

                <div class="diag-tab-intro">
                    <p>
                        <strong>What this tab does:</strong> At-a-glance dashboard of plugin state. Nine read-only checks
                        across environment, plugin tables, SeAT/SDE tables, the scheduled sync job, buyback settings,
                        Manager Core integration, price cache, recent contract sync activity, and EventBus publishing.
                        Each shows <strong>OK / WARN / ERROR</strong> with one-line summary and an expandable detail block.
                    </p>
                    <p>
                        <strong>When to use:</strong> First place to look when troubleshooting. The summary banner above
                        tells you whether anything needs attention. Heavy checks (MC integration, event log) are cached
                        for 60s. Click <code>Force refresh</code> in the banner to recompute live.
                    </p>
                </div>

                @foreach($checks as $key => $c)
                    <div class="diag-section">
                        <div class="diag-section-header">
                            <h3 class="diag-section-title">{{ ucwords(str_replace('_', ' ', $key)) }}</h3>
                            <span class="diag-badge {{ $c['status'] }}">{{ strtoupper($c['status']) }}</span>
                        </div>
                        <div class="diag-section-body">
                            <p class="diag-msg">{{ $c['message'] }}</p>
                            @if(!empty($c['details']))
                                <dl class="diag-kv" style="margin-top:0.8rem;">
                                    @foreach($c['details'] as $k => $v)
                                        <dt>{{ $k }}</dt>
                                        <dd>{{ is_bool($v) ? ($v ? 'true' : 'false') : (is_array($v) ? implode(', ', $v) : (string) $v) }}</dd>
                                    @endforeach
                                </dl>
                            @endif
                            @if(!empty($c['rows']))
                                <table class="diag-detail-table">
                                    <thead><tr><th>Table</th><th>Rows</th></tr></thead>
                                    <tbody>
                                        @foreach($c['rows'] as $row)
                                            <tr>
                                                <td><code>{{ $row['table'] }}</code></td>
                                                <td>{{ $row['rows'] }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            @endif
                        </div>
                    </div>
                @endforeach

            </div>

            {{-- ============================================================
                 MASTER TEST
                 ============================================================ --}}
            <div class="diag-tab-pane" data-diag-pane="master-test">

                <div class="diag-tab-intro">
                    <p>
                        <strong>What this tab does:</strong> Aggregates every Health Check into a single pass/warn/fail
                        roll-up so you can see overall plugin health in one number. Same data sources as Health Checks
                        but presented as a flat table with per-check status badges.
                    </p>
                    <p>
                        <strong>When to use:</strong> Run after a deploy, after a Manager Core upgrade, or before a
                        release tag. Confirms nothing has regressed. If everything is OK, you can move on; if anything
                        is WARN/ERROR, drill into Health Checks for the detail.
                    </p>
                </div>

                <div class="diag-section">
                    <div class="diag-section-header">
                        <h3 class="diag-section-title">All checks roll-up</h3>
                        <span class="diag-badge {{ $summary['overall'] }}">
                            {{ $summary['counts']['ok'] }} OK · {{ $summary['counts']['warn'] }} WARN · {{ $summary['counts']['error'] }} ERROR
                        </span>
                    </div>
                    <div class="diag-section-body">
                        <table class="diag-detail-table">
                            <thead><tr><th>Check</th><th>Status</th><th>Message</th></tr></thead>
                            <tbody>
                                @foreach($checks as $key => $c)
                                    <tr class="row-{{ $c['status'] === 'error' ? 'error' : ($c['status'] === 'warn' ? 'warn' : 'ok') }}">
                                        <td><code>{{ $key }}</code></td>
                                        <td><span class="diag-badge {{ $c['status'] }}">{{ strtoupper($c['status']) }}</span></td>
                                        <td>{{ $c['message'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>

            {{-- ============================================================
                 SYSTEM VALIDATION (lazy)
                 ============================================================ --}}
            <div class="diag-tab-pane" data-diag-pane="system-validation" data-lazy="{{ $systemValidation === null ? 'true' : 'false' }}">

                <div class="diag-tab-intro">
                    <p>
                        <strong>What this tab does:</strong> Verifies the <strong>hardcoded</strong> parts of the plugin
                        are still valid: constant type IDs (Tritanium=34, Jita region=10000002), the
                        <code>PriceProviderService</code> class itself, and (when Manager Core is installed) the five
                        capabilities BB depends on (<code>pricing.getPrices</code>, <code>subscribeTypes</code>,
                        <code>unsubscribeTypes</code>, <code>appraisal.create</code>, <code>events.publish</code>).
                    </p>
                    <p>
                        <strong>When to use:</strong> After upgrading SeAT (in case CCP renamed a type), after upgrading
                        Manager Core (in case capability signatures changed), or when something works on dev but breaks
                        on prod. Cached for 30 minutes (constants rarely change).
                    </p>
                </div>

                @if($systemValidation === null)
                    <div class="diag-loading"><i class="fa fa-spinner fa-spin"></i> Loading system validation…</div>
                @else
                    <div class="diag-section">
                        <div class="diag-section-header">
                            <h3 class="diag-section-title">Constants &amp; dependencies</h3>
                            <span class="diag-badge {{ $systemValidation['status'] }}">{{ strtoupper($systemValidation['status']) }}</span>
                        </div>
                        <div class="diag-section-body">
                            <table class="diag-detail-table">
                                <thead><tr><th>Category</th><th>Name</th><th>Status</th><th>Message</th></tr></thead>
                                <tbody>
                                    @foreach($systemValidation['items'] as $item)
                                        <tr class="row-{{ $item['status'] === 'error' ? 'error' : ($item['status'] === 'warn' ? 'warn' : 'ok') }}">
                                            <td>{{ $item['category'] }}</td>
                                            <td><code>{{ $item['name'] }}</code></td>
                                            <td><span class="diag-badge {{ $item['status'] }}">{{ strtoupper($item['status']) }}</span></td>
                                            <td>{{ $item['message'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif

            </div>

            {{-- ============================================================
                 SETTINGS HEALTH (lazy)
                 ============================================================ --}}
            <div class="diag-tab-pane" data-diag-pane="settings-health" data-lazy="{{ $settingsHealth === null ? 'true' : 'false' }}">

                <div class="diag-tab-intro">
                    <p>
                        <strong>What this tab does:</strong> Per-corporation audit of every BuybackSetting row. Validates
                        the configured provider can actually run (API key present for Janice, MC installed for
                        manager-core), checks <code>base_percentage</code> is in range, and flags missing market keys.
                        Counts pricing rules attached per setting.
                    </p>
                    <p>
                        <strong>When to use:</strong> After a settings save when something seems off, or to spot the
                        "API key was set on dev but missing on prod" class of issue. Cached for 30 seconds so edits feel
                        live.
                    </p>
                </div>

                @if($settingsHealth === null)
                    <div class="diag-loading"><i class="fa fa-spinner fa-spin"></i> Loading settings audit…</div>
                @else
                    <div class="diag-section">
                        <div class="diag-section-header">
                            <h3 class="diag-section-title">Per-corp settings audit</h3>
                            <span class="diag-badge {{ $settingsHealth['status'] }}">{{ strtoupper($settingsHealth['status']) }}</span>
                        </div>
                        <div class="diag-section-body">
                            @if(empty($settingsHealth['rows']))
                                <p class="diag-msg" style="font-style:italic; color:#8b95a5;">No buyback settings configured yet. Add one in the Settings page.</p>
                            @else
                                <table class="diag-detail-table">
                                    <thead>
                                        <tr>
                                            <th>Corp</th>
                                            <th>Enabled</th>
                                            <th>Provider</th>
                                            <th>Base %</th>
                                            <th>Rules</th>
                                            <th>Fallback</th>
                                            <th>Status</th>
                                            <th>Issues</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($settingsHealth['rows'] as $row)
                                            <tr class="row-{{ $row['status'] === 'warn' ? 'warn' : 'ok' }}">
                                                <td>{{ $row['corporation_name'] }} <small style="color:#8b95a5;">({{ $row['corporation_id'] }})</small></td>
                                                <td>{{ $row['enabled'] ? 'yes' : 'no' }}</td>
                                                <td><code>{{ $row['provider'] }}</code></td>
                                                <td>{{ $row['base_percentage'] }}%</td>
                                                <td>{{ $row['rule_count'] }}</td>
                                                <td>{{ $row['fallback_to_jita'] ? 'yes' : 'no' }}</td>
                                                <td><span class="diag-badge {{ $row['status'] }}">{{ strtoupper($row['status']) }}</span></td>
                                                <td>
                                                    @if(empty($row['issues']))
                                                        <span style="color:#8b95a5;">—</span>
                                                    @else
                                                        <ul style="margin:0; padding-left:1rem;">
                                                            @foreach($row['issues'] as $issue)
                                                                <li style="color:#fff1c7;">{{ $issue }}</li>
                                                            @endforeach
                                                        </ul>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            @endif
                        </div>
                    </div>
                @endif

            </div>

            {{-- ============================================================
                 DATA INTEGRITY (lazy)
                 ============================================================ --}}
            <div class="diag-tab-pane" data-diag-pane="data-integrity" data-lazy="{{ $dataIntegrity === null ? 'true' : 'false' }}">

                <div class="diag-tab-intro">
                    <p>
                        <strong>What this tab does:</strong> DB-level consistency checks. Counts orphan rows (FK without
                        parent), stale rows in <code>buyback_price_cache</code>, and drift between BB's local
                        subscribed-types ledger and MC's <code>manager_core_type_subscriptions</code> table (when MC
                        installed). Read-only (no cleanup actions).
                    </p>
                    <p>
                        <strong>When to use:</strong> Quarterly health check, or after a manual DB intervention
                        (restoring a backup, running a custom SQL fix). Cached for 5 minutes.
                    </p>
                </div>

                @if($dataIntegrity === null)
                    <div class="diag-loading"><i class="fa fa-spinner fa-spin"></i> Loading integrity checks…</div>
                @else
                    <div class="diag-section">
                        <div class="diag-section-header">
                            <h3 class="diag-section-title">Cross-table consistency</h3>
                            <span class="diag-badge {{ $dataIntegrity['status'] }}">{{ strtoupper($dataIntegrity['status']) }}</span>
                        </div>
                        <div class="diag-section-body">
                            <table class="diag-detail-table">
                                <thead><tr><th>Table</th><th>Check</th><th>Count</th><th>Status</th><th>Note</th></tr></thead>
                                <tbody>
                                    @foreach($dataIntegrity['issues'] as $row)
                                        <tr class="row-{{ $row['status'] === 'warn' ? 'warn' : 'ok' }}">
                                            <td><code>{{ $row['table'] }}</code></td>
                                            <td>{{ $row['check'] }}</td>
                                            <td>{{ $row['count'] }}</td>
                                            <td><span class="diag-badge {{ $row['status'] }}">{{ strtoupper($row['status']) }}</span></td>
                                            <td>{{ $row['note'] ?? '' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif

            </div>

            {{-- ============================================================
                 CONTRACT TRACE
                 ============================================================ --}}
            <div class="diag-tab-pane" data-diag-pane="contract-trace" data-lazy="{{ $contractCatalog === null ? 'true' : 'false' }}">

                <div class="diag-tab-intro">
                    <p>
                        <strong>What this tab does:</strong> Pick a buyback contract from the picker below and walk
                        through what BB knows about it: the source <code>ContractDetail</code> from SeAT, every line
                        item with type/group/category resolution, the buyback price computed per item, the persisted
                        <code>BuybackContract</code> row, and (when Manager Core is installed) every
                        <code>buyback.contract.*</code> event published for this contract from
                        <code>manager_core_event_log</code>.
                    </p>
                    <p>
                        <strong>When to use:</strong> When a specific contract was valued unexpectedly. Most powerful
                        debugging surface in the plugin: answers "why did THIS contract show THIS value?".
                    </p>
                </div>

                @if($contractCatalog === null)
                    <div class="diag-loading"><i class="fa fa-spinner fa-spin"></i> Loading contract catalog…</div>
                @else
                    <div class="diag-section">
                        <div class="diag-section-header">
                            <h3 class="diag-section-title">Pick a contract</h3>
                        </div>
                        <div class="diag-section-body">
                            @if(empty($contractCatalog))
                                <p class="diag-msg" style="font-style:italic; color:#8b95a5;">No buyback contracts tracked yet. Trigger a contract sync from the Health Checks tab.</p>
                            @else
                                <form method="GET" action="{{ route('buyback-manager.diagnostic.index') }}">
                                    <input type="hidden" name="diag_tab" value="contract-trace">
                                    <div style="display:flex; gap:0.6rem; align-items:flex-end; flex-wrap:wrap;">
                                        <div style="flex:1; min-width:300px;">
                                            <label style="display:block; color:#8b95a5; font-size:0.8rem;">Contract</label>
                                            <select name="contract_id" class="form-control">
                                                <option value="">-- pick one --</option>
                                                @foreach($contractCatalog as $row)
                                                    <option value="{{ $row['id'] }}" @if($row['id'] == $traceContractId) selected @endif>
                                                        #{{ $row['contract_id'] }}, {{ $row['corporation_name'] }}, {{ $row['status'] }}, {{ number_format($row['total_value'], 2) }} ISK ({{ $row['items_count'] }} items)
                                                    </option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <button type="submit" class="btn btn-bb-primary">
                                            <i class="fa fa-search"></i> Trace
                                        </button>
                                    </div>
                                    <small style="display:block; color:#8b95a5; margin-top:0.4rem;">Catalog shows the 50 most-recently-issued contracts.</small>
                                </form>
                            @endif
                        </div>
                    </div>

                    @if($contractTrace !== null)
                        @if(isset($contractTrace['error']))
                            <div class="diag-section">
                                <div class="diag-section-body">
                                    <p class="diag-msg"><span class="diag-badge error">ERROR</span> {{ $contractTrace['error'] }}</p>
                                </div>
                            </div>
                        @else
                            @php $c = $contractTrace['contract']; @endphp

                            <div class="diag-section">
                                <div class="diag-section-header">
                                    <h3 class="diag-section-title">BuybackContract #{{ $c->contract_id }}</h3>
                                    <span class="diag-badge info">{{ strtoupper($c->status) }}</span>
                                </div>
                                <div class="diag-section-body">
                                    <dl class="diag-kv">
                                        <dt>BB internal id</dt><dd>{{ $c->id }}</dd>
                                        <dt>ESI contract_id</dt><dd>{{ $c->contract_id }}</dd>
                                        <dt>Corporation</dt><dd>{{ $c->corporation->name ?? '?' }} ({{ $c->corporation_id }})</dd>
                                        <dt>Issuer</dt><dd>{{ $c->issuer->name ?? '?' }} ({{ $c->issuer_id }})</dd>
                                        <dt>Status</dt><dd>{{ $c->status }}</dd>
                                        <dt>Total value</dt><dd>{{ number_format((float)$c->total_value, 2) }} ISK</dd>
                                        <dt>Items count</dt><dd>{{ $c->items_count }}</dd>
                                        <dt>Issued</dt><dd>{{ optional($c->issued_date)->toDateTimeString() ?: '—' }}</dd>
                                        <dt>Completed</dt><dd>{{ optional($c->completed_date)->toDateTimeString() ?: '—' }}</dd>
                                    </dl>
                                </div>
                            </div>

                            @php $esi = $contractTrace['esi_detail']; @endphp
                            <div class="diag-section">
                                <div class="diag-section-header">
                                    <h3 class="diag-section-title">ESI source (SeAT ContractDetail)</h3>
                                    <span class="diag-badge {{ $esi ? 'ok' : 'warn' }}">{{ $esi ? 'PRESENT' : 'MISSING' }}</span>
                                </div>
                                <div class="diag-section-body">
                                    @if($esi)
                                        <dl class="diag-kv">
                                            <dt>Type</dt><dd>{{ $esi->type }}</dd>
                                            <dt>Status (ESI)</dt><dd>{{ $esi->status }}</dd>
                                            <dt>Assignee id</dt><dd>{{ $esi->assignee_id }}</dd>
                                            <dt>Lines on ESI side</dt><dd>{{ $esi->lines->count() }}</dd>
                                        </dl>
                                        @if($c->status !== $esi->status)
                                            <p style="margin-top:0.6rem;"><span class="diag-badge warn">DRIFT</span> ESI status differs from BB-stored status. Next sync will reconcile.</p>
                                        @endif
                                    @else
                                        <p class="diag-msg">SeAT's contract_details row is missing for this contract_id. Could be a deleted ESI contract, or SeAT eveapi hasn't fetched it yet.</p>
                                    @endif
                                </div>
                            </div>

                            <div class="diag-section">
                                <div class="diag-section-header">
                                    <h3 class="diag-section-title">Items ({{ count($contractTrace['items']) }})</h3>
                                </div>
                                <div class="diag-section-body">
                                    <table class="diag-detail-table">
                                        <thead>
                                            <tr>
                                                <th>Type</th><th>Group</th><th>Qty</th>
                                                <th>Unit price</th><th>Line total</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach($contractTrace['items'] as $item)
                                                <tr>
                                                    <td>{{ $item['type_name'] }} <small style="color:#8b95a5;">({{ $item['type_id'] }})</small></td>
                                                    <td>{{ $item['group_name'] ?? '?' }}</td>
                                                    <td>{{ number_format($item['quantity']) }}</td>
                                                    <td>{{ number_format($item['unit_price'], 2) }} ISK</td>
                                                    <td>{{ number_format($item['total_value'], 2) }} ISK</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <div class="diag-section">
                                <div class="diag-section-header">
                                    <h3 class="diag-section-title">EventBus publishes</h3>
                                    <span class="diag-badge info">{{ is_array($contractTrace['events']) ? count($contractTrace['events']) : 0 }} events</span>
                                </div>
                                <div class="diag-section-body">
                                    @if(isset($contractTrace['events']['_error']))
                                        <p class="diag-msg"><span class="diag-badge warn">WARN</span> Could not read event log: {{ $contractTrace['events']['_error'] }}</p>
                                    @elseif(empty($contractTrace['events']))
                                        <p class="diag-msg" style="font-style:italic; color:#8b95a5;">No events published for this contract. (Manager Core absent? Or this contract was synced before EventBus publishing shipped.)</p>
                                    @else
                                        <table class="diag-detail-table">
                                            <thead>
                                                <tr><th>Event</th><th>When</th><th>Status</th><th>Previous → New</th><th>event_id</th></tr>
                                            </thead>
                                            <tbody>
                                                @foreach($contractTrace['events'] as $ev)
                                                    <tr>
                                                        <td><code>{{ $ev['event_name'] }}</code></td>
                                                        <td>{{ $ev['created_at'] }}</td>
                                                        <td>{{ $ev['status'] ?? '—' }}</td>
                                                        <td>{{ $ev['previous_status'] ?? '∅' }} → {{ $ev['status_field'] ?? '?' }}</td>
                                                        <td style="font-family:monospace; font-size:0.78rem;">{{ $ev['event_id'] ?? '—' }}</td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    @endif
                                </div>
                            </div>
                        @endif
                    @endif
                @endif

            </div>

            {{-- ============================================================
                 NOTIFICATION TESTING
                 ============================================================ --}}
            <div class="diag-tab-pane" data-diag-pane="notification-testing">

                <div class="diag-tab-intro">
                    <p>
                        <strong>What this tab does:</strong> Lists every configured Discord webhook
                        across all corporations with its scope, subscribed categories, and a
                        Test button that fires a synthetic <code>buyback.offer.published</code>
                        event through <code>WebhookDispatcher</code> exactly as a real event would.
                        Shows each webhook's last dispatch status alongside.
                    </p>
                    <p>
                        <strong>When to use:</strong> After adding a webhook, after a Discord role/channel
                        change, or when a real event didn't appear in Discord and you want to
                        verify the webhook itself works. Test-fires bypass the dedup ledger so
                        you can re-test as often as you need.
                    </p>
                    <p>
                        <strong>Heads up:</strong> Test-fires count against Discord's 30/min/webhook rate
                        limit. The local <code>WebhookDispatcher</code> rate cap is 25/min and applies to
                        tests too. If you hit it, wait a minute.
                    </p>
                </div>

                <div class="diag-section">
                    <div class="diag-section-header">
                        <h3 class="diag-section-title">Configured webhooks</h3>
                        <span class="diag-badge info">{{ count($notificationTesting['webhooks'] ?? []) }} configured</span>
                    </div>
                    <div class="diag-section-body">
                        @if(empty($notificationTesting['webhooks']))
                            <p class="diag-msg" style="font-style:italic; color:#8b95a5;">
                                No webhooks configured. Add one via Settings → Discord Webhooks.
                            </p>
                        @else
                            <table class="diag-detail-table">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Scope</th>
                                        <th>Categories</th>
                                        <th>Enabled</th>
                                        <th>Last dispatch</th>
                                        <th>Test</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($notificationTesting['webhooks'] as $row)
                                        <tr class="row-{{ $row['enabled'] ? 'ok' : 'warn' }}">
                                            <td>{{ $row['name'] }}</td>
                                            <td>
                                                @if($row['corporation_name'])
                                                    {{ $row['corporation_name'] }}
                                                @else
                                                    <em>Global</em>
                                                @endif
                                            </td>
                                            <td>
                                                @foreach($row['categories'] as $c)
                                                    <code style="font-size:0.78rem;">{{ $c }}</code>@if(! $loop->last), @endif
                                                @endforeach
                                            </td>
                                            <td>
                                                <span class="diag-badge {{ $row['enabled'] ? 'ok' : 'warn' }}">
                                                    {{ $row['enabled'] ? 'YES' : 'NO' }}
                                                </span>
                                            </td>
                                            <td>
                                                @if($row['last_dispatch'])
                                                    <span class="diag-badge {{ $row['last_dispatch_class'] }}">{{ $row['last_dispatch_status'] }}</span>
                                                    <small style="color:#8b95a5; display:block;">{{ $row['last_dispatch'] }}</small>
                                                @else
                                                    <small style="color:#8b95a5; font-style:italic;">never</small>
                                                @endif
                                            </td>
                                            <td>
                                                @if($row['enabled'])
                                                    <form method="POST" action="{{ route('buyback-manager.settings.webhooks.test', $row['id']) }}" style="display:inline;">
                                                        @csrf
                                                        <button type="submit" class="btn btn-xs btn-info" title="Send a synthetic buyback.offer.published event">
                                                            <i class="fa fa-flask"></i> Test
                                                        </button>
                                                    </form>
                                                @else
                                                    <span class="diag-badge">disabled</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @endif
                    </div>
                </div>

                <div class="diag-section">
                    <div class="diag-section-header">
                        <h3 class="diag-section-title">Recent dispatches (last 24h)</h3>
                        <span class="diag-badge info">{{ count($notificationTesting['recent'] ?? []) }} entries</span>
                    </div>
                    <div class="diag-section-body">
                        @if(empty($notificationTesting['recent']))
                            <p class="diag-msg" style="font-style:italic; color:#8b95a5;">
                                No dispatches in the last 24 hours.
                            </p>
                        @else
                            <table class="diag-detail-table">
                                <thead>
                                    <tr>
                                        <th>When</th>
                                        <th>Webhook</th>
                                        <th>Event</th>
                                        <th>Status</th>
                                        <th>Error</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($notificationTesting['recent'] as $row)
                                        <tr class="row-{{ $row['status_class'] }}">
                                            <td>{{ $row['sent_at'] }}</td>
                                            <td>{{ $row['webhook_name'] }}</td>
                                            <td><code style="font-size:0.78rem;">{{ $row['event_name'] }}</code></td>
                                            <td><span class="diag-badge {{ $row['status_class'] }}">{{ $row['status'] }}</span></td>
                                            <td><small style="color:#8b95a5;">{{ \Illuminate\Support\Str::limit($row['error'] ?? '', 80) }}</small></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @endif
                    </div>
                </div>

            </div>

        </div>{{-- /.card-body --}}
    </div>{{-- /.card.card-dark --}}

</div>

@push('javascript')
<script>
(function ($) {
    'use strict';

    const $tabs = $('.diag-tab');
    const $panes = $('.diag-tab-pane');

    function setActive(target) {
        $tabs.removeClass('active');
        $tabs.filter('[data-diag-target="' + target + '"]').addClass('active');

        $panes.removeClass('active').hide();
        $panes.filter('[data-diag-pane="' + target + '"]').addClass('active').show();

        // Lazy-load redirect: if the activated pane is data-lazy=true,
        // bounce to ?diag_tab=<target> so the server populates it.
        const pane = document.querySelector('[data-diag-pane="' + target + '"]');
        if (pane && pane.getAttribute('data-lazy') === 'true') {
            try {
                const url = new URL(window.location.href);
                url.searchParams.set('diag_tab', target);
                window.location.replace(url.toString());
            } catch (e) {
                window.location.href = window.location.pathname + '?diag_tab=' + encodeURIComponent(target);
            }
        }
    }

    $tabs.on('click', function () {
        const target = $(this).data('diag-target');
        if (target) setActive(target);
    });

    // Default landing tab: ALWAYS Health Checks. URL deep-links
    // (?diag_tab=X) DO win so form submissions land on the right tab.
    const validTargets = $tabs.map(function () { return $(this).data('diag-target'); }).get();
    let urlTab = null;
    try {
        const params = new URLSearchParams(window.location.search);
        urlTab = params.get('diag_tab');
    } catch (e) {}

    if (urlTab && validTargets.includes(urlTab)) {
        setActive(urlTab);
    } else {
        setActive('health');
    }
})(jQuery);
</script>
@endpush

@endsection
