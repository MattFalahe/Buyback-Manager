@extends('web::layouts.grids.12')

@section('title', 'Buyback Manager - Settings')
@section('page_header', 'Buyback Settings')

@push('head')
    <link rel="stylesheet" href="{{ asset('vendor/buyback-manager/css/buyback-manager.css') }}?v=3">
    <style>
        /* Inner settings sidebar — matches the canonical pattern from
           Mining Manager / Structure Manager. Scoped to
           .buyback-manager-wrapper.bb-settings-page so it never leaks. */

        .buyback-manager-wrapper.bb-settings-page .settings-wrapper {
            display: flex;
            gap: 20px;
        }
        .buyback-manager-wrapper.bb-settings-page .settings-sidebar {
            flex: 0 0 260px;
        }
        .buyback-manager-wrapper.bb-settings-page .settings-content {
            flex: 1;
            min-width: 0;
        }

        .buyback-manager-wrapper.bb-settings-page .nav-pills .nav-link {
            color: #e2e8f0;
            border-radius: 5px;
            margin-bottom: 5px;
            transition: all 0.2s;
            padding: 0.7rem 1rem;
        }
        .buyback-manager-wrapper.bb-settings-page .nav-pills .nav-link:hover {
            background: rgba(102, 126, 234, 0.18);
        }
        .buyback-manager-wrapper.bb-settings-page .nav-pills .nav-link.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #fff;
        }
        .buyback-manager-wrapper.bb-settings-page .nav-pills .nav-link i {
            width: 22px;
            text-align: center;
            margin-right: 0.5rem;
        }

        .buyback-manager-wrapper.bb-settings-page .settings-section {
            display: none;
        }
        .buyback-manager-wrapper.bb-settings-page .settings-section.active {
            display: block;
        }

        .buyback-manager-wrapper.bb-settings-page .nav-header {
            padding: 0.5rem 0.9rem;
            text-transform: uppercase;
            font-size: 0.72rem;
            letter-spacing: 0.05em;
            color: #8b95a5;
        }

        @media (max-width: 992px) {
            .buyback-manager-wrapper.bb-settings-page .settings-wrapper {
                flex-direction: column;
            }
            .buyback-manager-wrapper.bb-settings-page .settings-sidebar {
                flex: 1;
            }
        }

        /* Role pill chrome (shared by webhooks panel + routing map). */
        .buyback-manager-wrapper .bb-role-pill {
            display: inline-flex; align-items: center; gap: 0.3rem;
            padding: 0.15rem 0.55rem;
            background: rgba(99, 102, 241, 0.18);
            border: 1px solid rgba(99, 102, 241, 0.4);
            border-radius: 999px; font-size: 0.78rem; color: #c7d2fe;
        }
        .buyback-manager-wrapper .bb-role-pill.is-user {
            background: rgba(108, 117, 125, 0.18); border-color: rgba(108, 117, 125, 0.4); color: #c2c7d0;
        }
        .buyback-manager-wrapper .bb-role-pill.is-unknown {
            background: rgba(122, 90, 15, 0.18); border-color: rgba(234, 179, 8, 0.4); color: #fff1c7;
        }
        .buyback-manager-wrapper .bb-role-color-dot {
            display: inline-block; width: 9px; height: 9px; border-radius: 50%; border: 1px solid rgba(0,0,0,0.3);
        }
        .buyback-manager-wrapper .bb-role-input-group { display: flex; gap: 0.4rem; align-items: stretch; }
        .buyback-manager-wrapper .bb-role-input-group input { flex-grow: 1; }
    </style>
@endpush

@section('content')
<div class="buyback-manager-wrapper bb-settings-page">

    @if(session('success'))
        <div class="alert alert-success alert-dismissible">
            <button type="button" class="close" data-dismiss="alert">&times;</button>
            <i class="fa fa-check"></i> {{ session('success') }}
        </div>
    @endif

    @if(session('error'))
        <div class="alert alert-danger alert-dismissible">
            <button type="button" class="close" data-dismiss="alert">&times;</button>
            <i class="fa fa-exclamation-triangle"></i> {{ session('error') }}
        </div>
    @endif

    @if($errors->any())
        <div class="alert alert-danger alert-dismissible">
            <button type="button" class="close" data-dismiss="alert">&times;</button>
            <h4><i class="fa fa-exclamation-triangle"></i> Error!</h4>
            <ul>
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="settings-wrapper">

        {{-- Sidebar nav --}}
        <div class="settings-sidebar">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title"><i class="fa fa-cog"></i> Settings Menu</h3>
                </div>
                <div class="card-body p-0">
                    <ul class="nav nav-pills flex-column" style="padding: 0.5rem;">
                        <li class="nav-header">Corporations</li>
                        <li class="nav-item">
                            <a href="#" class="nav-link active" data-tab="corporations">
                                <i class="fa fa-building"></i> Configured Corps
                                <span class="badge badge-secondary float-right">{{ $settings->count() }}</span>
                            </a>
                        </li>

                        <li class="nav-header" style="margin-top:0.4rem;">Pricing</li>
                        <li class="nav-item">
                            <a href="#" class="nav-link" data-tab="rules">
                                <i class="fa fa-percent"></i> Pricing Rules
                            </a>
                        </li>

                        <li class="nav-header" style="margin-top:0.4rem;">Notifications</li>
                        <li class="nav-item">
                            <a href="#" class="nav-link" data-tab="webhooks">
                                <i class="fab fa-discord"></i> Discord Webhooks
                                <span class="badge badge-secondary float-right">{{ $webhooks->count() }}</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="#" class="nav-link" data-tab="routing-map">
                                <i class="fa fa-project-diagram"></i> Routing Map
                            </a>
                        </li>

                        <li class="nav-header" style="margin-top:0.4rem;">Reference</li>
                        <li class="nav-item">
                            <a href="#" class="nav-link" data-tab="help">
                                <i class="fa fa-info-circle"></i> How It Works
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        {{-- Content panel --}}
        <div class="settings-content">

            {{-- ============================================================
                 TAB: CONFIGURED CORPS (default)
                 ============================================================ --}}
            <div class="settings-section active" data-section="corporations">

                <div class="card card-dark">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fa fa-building"></i> Corporation Buyback Settings</h3>
                    </div>
                    <div class="card-body">
                        <table class="table table-bb-styled">
                            <thead>
                                <tr>
                                    <th>Corporation</th>
                                    <th>Enabled</th>
                                    <th>Base %</th>
                                    <th>Contract target</th>
                                    <th>Provider</th>
                                    <th>Market</th>
                                    <th>Rules</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($settings as $setting)
                                    @php
                                        $provider = $setting->price_provider ?? 'fuzzwork';
                                        $providerLabel = $providers[$provider]['name'] ?? ucfirst($provider);
                                        $market = match($provider) {
                                            'janice' => $setting->janice_market ?? 'jita',
                                            'manager-core' => $setting->manager_core_market ?? 'jita',
                                            default => $setting->price_source ?? 'jita',
                                        };
                                        $targetType = $setting->target_type ?? 'my_corp';
                                        $targetLabel = match($targetType) {
                                            'player' => 'Player: ' . $setting->targetDisplayLabel(),
                                            'corp' => 'Corp: ' . $setting->targetDisplayLabel(),
                                            default => 'My Corporation',
                                        };
                                        $targetBadge = match($targetType) {
                                            'player' => 'warning',
                                            'corp' => 'info',
                                            default => 'default',
                                        };
                                    @endphp
                                    <tr>
                                        <td>
                                            <img src="https://images.evetech.net/corporations/{{ $setting->corporation_id }}/logo?size=32"
                                                 style="width: 24px; height: 24px; vertical-align: middle; margin-right: 5px;">
                                            {{ $setting->corporation->name ?? 'Unknown' }}
                                        </td>
                                        <td>
                                            @if($setting->enabled)
                                                <span class="label label-success">Enabled</span>
                                            @else
                                                <span class="label label-default">Disabled</span>
                                            @endif
                                        </td>
                                        <td>{{ $setting->base_percentage }}%</td>
                                        <td>
                                            <span class="label label-{{ $targetBadge }}">{{ $targetLabel }}</span>
                                        </td>
                                        <td><span class="label label-info">{{ $providerLabel }}</span></td>
                                        <td>{{ ucfirst($market) }}</td>
                                        <td>
                                            <a href="{{ route('buyback-manager.settings.rules', $setting->id) }}" class="btn btn-xs btn-info">
                                                <i class="fa fa-cog"></i> {{ $setting->pricingRules->count() }}
                                            </a>
                                        </td>
                                        <td>
                                            <button type="button" class="btn btn-xs btn-warning edit-setting-btn"
                                                    data-toggle="modal"
                                                    data-target="#editSettingModal"
                                                    data-id="{{ $setting->id }}"
                                                    data-corporation-id="{{ $setting->corporation_id }}"
                                                    data-corporation-name="{{ $setting->corporation->name ?? 'Unknown' }}"
                                                    data-character-id="{{ $setting->character_id }}"
                                                    data-enabled="{{ $setting->enabled }}"
                                                    data-base-percentage="{{ $setting->base_percentage }}"
                                                    data-price-source="{{ $setting->price_source }}"
                                                    data-region-id="{{ $setting->region_id }}"
                                                    data-price-provider="{{ $setting->price_provider ?? 'fuzzwork' }}"
                                                    data-janice-api-key="{{ $setting->janice_api_key }}"
                                                    data-janice-market="{{ $setting->janice_market }}"
                                                    data-janice-price-method="{{ $setting->janice_price_method }}"
                                                    data-manager-core-market="{{ $setting->manager_core_market }}"
                                                    data-manager-core-variant="{{ $setting->manager_core_variant }}"
                                                    data-fallback-to-jita="{{ $setting->fallback_to_jita ? 1 : 0 }}"
                                                    data-price-cache-ttl-minutes="{{ $setting->price_cache_ttl_minutes ?? 60 }}"
                                                    data-buyback-mode="{{ $setting->buyback_mode ?? 'public' }}"
                                                    data-target-type="{{ $setting->target_type ?? 'my_corp' }}"
                                                    data-target-corporation-id="{{ $setting->target_corporation_id }}"
                                                    data-target-corporation-name="{{ $setting->target_corporation_name }}"
                                                    data-offer-lock-hours="{{ $setting->offer_lock_hours ?? 24 }}"
                                                    data-private-auto-nudge-hours="{{ $setting->private_auto_nudge_hours ?? 48 }}">
                                                <i class="fa fa-edit"></i>
                                            </button>
                                            <a href="{{ route('buyback-manager.settings.public.edit', $setting->id) }}"
                                               class="btn btn-xs {{ $setting->public_page_enabled ? 'btn-success' : 'btn-default' }}"
                                               title="Public landing page{{ $setting->public_page_enabled ? ' (live)' : '' }}">
                                                <i class="fa fa-globe"></i>
                                            </a>
                                            <form action="{{ route('buyback-manager.settings.destroy', $setting->id) }}"
                                                  method="POST"
                                                  style="display: inline;"
                                                  onsubmit="return confirm('Are you sure you want to delete this setting?');">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-xs btn-danger">
                                                    <i class="fa fa-trash"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="8" class="text-center text-muted">No settings configured yet. Add your first corporation below.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                {{-- Add new corporation --}}
                <div class="card card-dark">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fa fa-plus"></i> Add new corporation</h3>
                    </div>
                    <form action="{{ route('buyback-manager.settings.store') }}" method="POST" class="bb-settings-form">
                        @csrf
                        <div class="card-body">

                            {{-- General --}}
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="corporation_id">Corporation <span class="text-danger">*</span></label>
                                        <select name="corporation_id" id="corporation_id" class="form-control" required>
                                            <option value="">-- Select Corporation --</option>
                                            @foreach($corporations as $corporation)
                                                <option value="{{ $corporation->corporation_id }}">{{ $corporation->name }}</option>
                                            @endforeach
                                        </select>
                                        <small class="text-muted">The corporation this buyback program serves. Its members appraise + publish offers.</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="base_percentage">Base percentage <span class="text-danger">*</span></label>
                                        <div class="input-group">
                                            <input type="number" name="base_percentage" id="base_percentage"
                                                   class="form-control" step="0.01" min="0" max="100" value="90" required>
                                            <span class="input-group-addon">%</span>
                                        </div>
                                        <small class="text-muted">Default rate when no item/group/category rule matches.</small>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-12">
                                    <div class="checkbox">
                                        <label>
                                            <input type="checkbox" name="enabled" value="1" checked>
                                            Enable buyback for this corporation
                                        </label>
                                    </div>
                                </div>
                            </div>

                            {{-- Contract Target --}}
                            <hr>
                            <h5 class="bb-section-heading"><i class="fa fa-shield-alt"></i> Contract Target</h5>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="target_type">Where do members send the contract? <span class="text-danger">*</span></label>
                                        <select name="target_type" id="target_type" class="form-control bb-target-selector" required>
                                            <option value="my_corp" selected>My Corporation (anyone in corp can buy)</option>
                                            <option value="corp">Specific Corporation</option>
                                            <option value="player">Specific Player</option>
                                        </select>
                                        <small class="text-muted bb-target-help">
                                            <span class="bb-th-my_corp">Members contract the corporation above. Any director can accept.</span>
                                            <span class="bb-th-corp" style="display:none;">Members contract a specific corporation (e.g. a holding/buyback corp).</span>
                                            <span class="bb-th-player" style="display:none;">Members contract one designated character who accepts or rejects.</span>
                                        </small>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label for="offer_lock_hours">Offer lock (hours)</label>
                                        <input type="number" name="offer_lock_hours" id="offer_lock_hours" class="form-control"
                                               value="24" min="1" max="168" step="1">
                                        <small class="text-muted">Frozen quote validity (1–168 h).</small>
                                    </div>
                                </div>
                                <div class="col-md-3 bb-target-player-fields" style="display:none;">
                                    <div class="form-group">
                                        <label for="private_auto_nudge_hours">Auto-nudge (hours)</label>
                                        <input type="number" name="private_auto_nudge_hours" id="private_auto_nudge_hours" class="form-control"
                                               value="48" min="0" max="168" step="1">
                                        <small class="text-muted">Ping queue if idle. 0 disables.</small>
                                    </div>
                                </div>
                            </div>

                            {{-- Player target fields --}}
                            <div class="row bb-target-player-fields" style="display:none;">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="character_id">Designated character <span class="text-danger">*</span></label>
                                        <input type="number" name="character_id" id="character_id" class="form-control"
                                               placeholder="EVE character ID of the buyback operator">
                                        <small class="text-muted">
                                            This character receives + accepts/rejects the contracts. For auto-detection, this
                                            character must be registered in SeAT with the <code>read_character_contracts</code>
                                            scope (BB reads their personal contract feed).
                                        </small>
                                    </div>
                                </div>
                            </div>

                            {{-- Specific corporation target fields --}}
                            <div class="row bb-target-corp-fields" style="display:none;">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="target_corporation_id">Pick a known corporation</label>
                                        <select name="target_corporation_id" id="target_corporation_id" class="form-control bb-target-corp-picker">
                                            <option value="">-- type a name below instead --</option>
                                            @foreach($corporations as $corporation)
                                                <option value="{{ $corporation->corporation_id }}">{{ $corporation->name }}</option>
                                            @endforeach
                                        </select>
                                        <small class="text-muted">Resolvable corps auto-confirm contracts (if SeAT syncs that corp).</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="target_corporation_name">…or type a corporation name</label>
                                        <input type="text" name="target_corporation_name" id="target_corporation_name" class="form-control"
                                               maxlength="255" placeholder="e.g. Goonswarm Federation Holdings">
                                        <small class="text-muted">External corps BB can't see are <strong>instructions-only</strong> (offer stays pending for manual confirmation).</small>
                                    </div>
                                </div>
                            </div>

                            {{-- Pricing Provider --}}
                            <hr>
                            <h5 class="bb-section-heading"><i class="fa fa-tags"></i> Pricing Provider</h5>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="price_provider">Provider <span class="text-danger">*</span></label>
                                        <select name="price_provider" id="price_provider" class="form-control bb-provider-selector" required>
                                            @foreach($providers as $key => $info)
                                                <option value="{{ $key }}"
                                                        @if(!($info['available'] ?? true)) disabled @endif
                                                        @if($key === 'fuzzwork') selected @endif>
                                                    {{ $info['name'] }}
                                                    @if(!($info['available'] ?? true)) (not installed) @endif
                                                </option>
                                            @endforeach
                                        </select>
                                        <small class="text-muted bb-provider-description"></small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label>&nbsp;</label>
                                    <div>
                                        <button type="button" class="btn btn-info bb-test-provider-btn">
                                            <i class="fa fa-flask"></i> Test Connection
                                        </button>
                                        <span class="bb-test-result"></span>
                                    </div>
                                </div>
                            </div>

                            {{-- Fuzzwork-only fields (hidden when provider != fuzzwork) --}}
                            <div class="bb-fuzzwork-config">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label for="price_source">Fuzzwork Region</label>
                                            <select name="price_source" id="price_source" class="form-control">
                                                <option value="jita">Jita (The Forge)</option>
                                                <option value="region">Custom Region</option>
                                            </select>
                                            <small class="text-muted">Region selector used only by the Fuzzwork provider.</small>
                                        </div>
                                    </div>
                                    <div class="col-md-6 region-id-field">
                                        <div class="form-group">
                                            <label for="region_id">Region ID (if Custom)</label>
                                            <input type="number" name="region_id" id="region_id" class="form-control"
                                                   placeholder="e.g., 10000043 (Domain/Amarr)">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {{-- Janice-only fields --}}
                            <div class="bb-janice-config" style="display: none;">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label for="janice_api_key">Janice API key</label>
                                            <input type="text" name="janice_api_key" id="janice_api_key" class="form-control"
                                                   placeholder="Your Janice API key" autocomplete="off">
                                            <small class="text-muted">Get a free key at <a href="https://janice.e-351.com" target="_blank">janice.e-351.com</a></small>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="form-group">
                                            <label for="janice_market">Janice market</label>
                                            <select name="janice_market" id="janice_market" class="form-control">
                                                <option value="jita">Jita</option>
                                                <option value="amarr">Amarr</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="form-group">
                                            <label for="janice_price_method">Price method</label>
                                            <select name="janice_price_method" id="janice_price_method" class="form-control">
                                                <option value="buy">Buy (max buy order)</option>
                                                <option value="sell">Sell (min sell order)</option>
                                                <option value="split">Split (effective)</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {{-- Manager Core-only fields --}}
                            <div class="bb-mc-config" style="display: none;">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label for="manager_core_market">MC Market</label>
                                            @php
                                                $hubMarkets = collect($mcMarkets ?? [])->where('type', 'hub')->values();
                                                $citadelMarkets = collect($mcMarkets ?? [])->where('type', 'citadel')->values();
                                            @endphp
                                            <select name="manager_core_market" id="manager_core_market" class="form-control">
                                                @if(empty($mcMarkets))
                                                    <option value="jita">Jita (default; MC not installed or no markets configured)</option>
                                                @else
                                                    @if($hubMarkets->isNotEmpty())
                                                        <optgroup label="Hub markets">
                                                            @foreach($hubMarkets as $m)
                                                                <option value="{{ $m['key'] }}">{{ $m['label'] }}</option>
                                                            @endforeach
                                                        </optgroup>
                                                    @endif
                                                    @if($citadelMarkets->isNotEmpty())
                                                        <optgroup label="Citadel markets">
                                                            @foreach($citadelMarkets as $m)
                                                                <option value="{{ $m['key'] }}">{{ $m['label'] }}</option>
                                                            @endforeach
                                                        </optgroup>
                                                    @endif
                                                @endif
                                            </select>
                                            <small class="text-muted">
                                                @if(empty($mcMarkets))
                                                    Install Manager Core and add markets (Manager Core → Markets) to enable citadel + regional pricing.
                                                @else
                                                    Loaded from Manager Core's market list ({{ count($mcMarkets) }} available).
                                                @endif
                                                <br>An admin can override this market in <strong>Manager Core → Pricing Preferences</strong> (Buyback Manager registers there when MC is the provider). The MC override wins over this field.
                                            </small>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label for="manager_core_variant">Price variant</label>
                                            <select name="manager_core_variant" id="manager_core_variant" class="form-control">
                                                <option value="min">min (cheapest sell / highest buy)</option>
                                                <option value="max">max</option>
                                                <option value="avg">avg</option>
                                                <option value="median">median</option>
                                                <option value="percentile">percentile (5%)</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-8">
                                    <div class="checkbox" style="margin-top:8px;">
                                        <label>
                                            <input type="checkbox" name="fallback_to_jita" value="1" checked>
                                            Fall back to Jita when the configured market returns 0 for some items
                                        </label>
                                        <br>
                                        <small class="text-muted">Recommended. Without this, items the configured provider can't price will be valued at 0.</small>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label for="price_cache_ttl_minutes">Cache TTL (minutes)</label>
                                        <input type="number" name="price_cache_ttl_minutes" id="price_cache_ttl_minutes"
                                               class="form-control" value="60" min="0" max="1440" step="1">
                                        <small class="text-muted">
                                            Fuzzwork / Janice only. 0 disables caching.
                                            Manager Core has its own cache and skips this.
                                        </small>
                                    </div>
                                </div>
                            </div>

                        </div>
                        <div class="card-footer">
                            <button type="submit" class="btn btn-bb-primary">
                                <i class="fa fa-save"></i> Save setting
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            {{-- ============================================================
                 TAB: PRICING RULES
                 ============================================================ --}}
            <div class="settings-section" data-section="rules">
                <div class="card card-dark">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fa fa-percent"></i> Pricing Rules</h3>
                    </div>
                    <div class="card-body">
                        <p>
                            Pricing rules let you override the base percentage for specific items, groups,
                            or categories. Priority: <strong>item &gt; group &gt; category &gt; base</strong>.
                            Use exclusions to refuse certain items entirely.
                        </p>
                        @if($settings->isEmpty())
                            <p class="text-muted" style="font-style:italic;">
                                Add a corporation in the Configured Corps tab first. Rules attach to a corp's setting.
                            </p>
                        @else
                            <table class="table table-bb-styled">
                                <thead>
                                    <tr>
                                        <th>Corporation</th>
                                        <th>Base %</th>
                                        <th>Rule count</th>
                                        <th>By type</th>
                                        <th>Edit</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($settings as $setting)
                                        @php
                                            $rules = $setting->pricingRules;
                                            $byType = $rules->groupBy('type')->map->count();
                                        @endphp
                                        <tr>
                                            <td>
                                                <img src="https://images.evetech.net/corporations/{{ $setting->corporation_id }}/logo?size=32"
                                                     style="width: 24px; height: 24px; vertical-align: middle; margin-right: 5px;">
                                                {{ $setting->corporation->name ?? 'Unknown' }}
                                            </td>
                                            <td>{{ $setting->base_percentage }}%</td>
                                            <td><span class="label label-info">{{ $rules->count() }}</span></td>
                                            <td>
                                                <small class="text-muted">
                                                    item: {{ $byType['item'] ?? 0 }},
                                                    group: {{ $byType['group'] ?? 0 }},
                                                    category: {{ $byType['category'] ?? 0 }}
                                                </small>
                                            </td>
                                            <td>
                                                <a href="{{ route('buyback-manager.settings.rules', $setting->id) }}" class="btn btn-xs btn-bb-primary">
                                                    <i class="fa fa-cog"></i> Edit rules
                                                </a>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @endif
                    </div>
                </div>
            </div>

            {{-- ============================================================
                 TAB: DISCORD WEBHOOKS (embedded inline)
                 ============================================================ --}}
            <div class="settings-section" data-section="webhooks">
                <div class="alert alert-info">
                    <strong><i class="fa fa-info-circle"></i> Routing model:</strong>
                    BB routes notifications to <em>channels via webhooks</em>, never to individual users.
                    The knobs are category routing, role mentions, and per-corp webhook scoping.
                    See the <a href="#" class="js-goto-routing-map">Routing Map</a> for a resolved view of who gets pinged for what.
                </div>
                @include('buyback-manager::settings._webhooks_panel')
            </div>

            {{-- ============================================================
                 TAB: ROUTING MAP
                 ============================================================ --}}
            <div class="settings-section" data-section="routing-map">
                @include('buyback-manager::settings._routing_map')
            </div>

            {{-- ============================================================
                 TAB: HELP & INFO
                 ============================================================ --}}
            <div class="settings-section" data-section="help">
                <div class="card card-dark">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fa fa-info-circle"></i> How It Works</h3>
                    </div>
                    <div class="card-body">
                        <ol style="line-height:1.7;">
                            <li><strong>Configure a corporation</strong> in the Configured Corps tab: set the base buyback percentage, the contract target, and the pricing provider.</li>
                            <li><strong>Add Pricing Rules</strong> for items, groups, or categories that should pay a different rate (or be excluded entirely).</li>
                            <li><strong>Members appraise</strong> on the Appraisal page, then publish their quote as an offer. The offer URL is shareable on Discord / corp chat.</li>
                            <li><strong>Member creates the EVE contract</strong> to the configured target, per the step-by-step instructions on the offer detail page.</li>
                            <li><strong>BB matches the contract</strong> to the offer within 15 minutes (sync cycle). The frozen offer value is what gets paid. Market movement between publish and contract doesn't change the payout.</li>
                            <li><strong>Discord webhooks fire</strong> on each lifecycle event (new offer, matched, unmatched, completed, rejected) per the categories you've configured.</li>
                        </ol>
                        <div class="alert alert-info" style="margin-top:1rem;">
                            <strong><i class="fa fa-shield-alt"></i> Contract target options:</strong>
                            <ul style="margin-bottom:0;">
                                <li><strong>My Corporation</strong>: members contract the corp; anyone with corp contract rights can accept. Auto-confirmed.</li>
                                <li><strong>Specific Corporation</strong>: contract goes to another corp (e.g. a holding/director corp, visible to that corp's directors). Auto-confirmed if SeAT holds that corp's director token; an external corp typed by name is instructions-only (offer stays pending for manual confirmation).</li>
                                <li><strong>Specific Player</strong>: one designated character receives and accepts/rejects (private, only they see it). BB reads that character's own contract feed, so they must be registered in SeAT with the contract-read scope. After rejecting in-game, they can record a reason on the offer page.</li>
                            </ul>
                        </div>
                        <div class="alert alert-warning">
                            <strong><i class="fa fa-lightbulb-o"></i> Pricing tip:</strong>
                            Janice handles rich paste formats (fitted ships, EFT fits, BPCs) for the manual
                            appraisal UI. Install Manager Core to price contracts at your local nullsec
                            citadel or any regional market.
                        </div>
                    </div>
                </div>
            </div>

        </div>{{-- /.settings-content --}}
    </div>{{-- /.settings-wrapper --}}
</div>{{-- /.buyback-manager-wrapper --}}

{{-- ============================================================
     EDIT MODAL (stays outside the tab structure — Bootstrap modal)
     ============================================================ --}}
<div class="modal fade" id="editSettingModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form action="{{ route('buyback-manager.settings.store') }}" method="POST" class="bb-settings-form">
                @csrf
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                    <h4 class="modal-title">Edit Buyback Setting</h4>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="id" id="edit_id">
                    <input type="hidden" name="corporation_id" id="edit_corporation_id">

                    <div class="row">
                        <div class="col-md-12">
                            <div class="form-group">
                                <label for="edit_base_percentage">Base percentage</label>
                                <div class="input-group" style="max-width:200px;">
                                    <input type="number" name="base_percentage" id="edit_base_percentage"
                                           class="form-control" step="0.01" min="0" max="100" required>
                                    <span class="input-group-addon">%</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <hr>
                    <h5 class="bb-section-heading"><i class="fa fa-shield-alt"></i> Contract Target</h5>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="edit_target_type">Where do members send the contract?</label>
                                <select name="target_type" id="edit_target_type" class="form-control bb-target-selector" required>
                                    <option value="my_corp">My Corporation (anyone in corp can buy)</option>
                                    <option value="corp">Specific Corporation</option>
                                    <option value="player">Specific Player</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label for="edit_offer_lock_hours">Lock (h)</label>
                                <input type="number" name="offer_lock_hours" id="edit_offer_lock_hours" class="form-control" min="1" max="168" step="1">
                            </div>
                        </div>
                        <div class="col-md-3 bb-target-player-fields" style="display:none;">
                            <div class="form-group">
                                <label for="edit_private_auto_nudge_hours">Auto-nudge (h)</label>
                                <input type="number" name="private_auto_nudge_hours" id="edit_private_auto_nudge_hours" class="form-control" min="0" max="168" step="1">
                            </div>
                        </div>
                    </div>
                    <div class="row bb-target-player-fields" style="display:none;">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="edit_character_id">Designated character <span class="text-danger">*</span></label>
                                <input type="number" name="character_id" id="edit_character_id" class="form-control"
                                       placeholder="EVE character ID">
                                <small class="text-muted">Receives + accepts/rejects contracts.</small>
                            </div>
                        </div>
                    </div>
                    <div class="row bb-target-corp-fields" style="display:none;">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="edit_target_corporation_id">Pick a known corporation</label>
                                <select name="target_corporation_id" id="edit_target_corporation_id" class="form-control bb-target-corp-picker">
                                    <option value="">-- type a name below instead --</option>
                                    @foreach($corporations as $corporation)
                                        <option value="{{ $corporation->corporation_id }}">{{ $corporation->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="edit_target_corporation_name">…or type a corporation name</label>
                                <input type="text" name="target_corporation_name" id="edit_target_corporation_name" class="form-control" maxlength="255">
                                <small class="text-muted">External corps = instructions-only.</small>
                            </div>
                        </div>
                    </div>

                    <hr>
                    <h5 class="bb-section-heading"><i class="fa fa-tags"></i> Pricing Provider</h5>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="edit_price_provider">Provider</label>
                                <select name="price_provider" id="edit_price_provider" class="form-control bb-provider-selector" required>
                                    @foreach($providers as $key => $info)
                                        <option value="{{ $key }}" @if(!($info['available'] ?? true)) disabled @endif>
                                            {{ $info['name'] }}
                                            @if(!($info['available'] ?? true)) (not installed) @endif
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label>&nbsp;</label>
                            <div>
                                <button type="button" class="btn btn-info bb-test-provider-btn">
                                    <i class="fa fa-flask"></i> Test Connection
                                </button>
                                <span class="bb-test-result"></span>
                            </div>
                        </div>
                    </div>

                    {{-- Fuzzwork-only fields (edit modal) --}}
                    <div class="bb-fuzzwork-config">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="edit_price_source">Fuzzwork Region</label>
                                    <select name="price_source" id="edit_price_source" class="form-control">
                                        <option value="jita">Jita (The Forge)</option>
                                        <option value="region">Custom Region</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6 region-id-field">
                                <div class="form-group">
                                    <label for="edit_region_id">Region ID</label>
                                    <input type="number" name="region_id" id="edit_region_id" class="form-control">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="bb-janice-config" style="display: none;">
                        <div class="row">
                            <div class="col-md-12">
                                <div class="form-group">
                                    <label for="edit_janice_api_key">Janice API key</label>
                                    <input type="text" name="janice_api_key" id="edit_janice_api_key" class="form-control" autocomplete="off">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="edit_janice_market">Janice market</label>
                                    <select name="janice_market" id="edit_janice_market" class="form-control">
                                        <option value="jita">Jita</option>
                                        <option value="amarr">Amarr</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="edit_janice_price_method">Price method</label>
                                    <select name="janice_price_method" id="edit_janice_price_method" class="form-control">
                                        <option value="buy">Buy</option>
                                        <option value="sell">Sell</option>
                                        <option value="split">Split</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="bb-mc-config" style="display: none;">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="edit_manager_core_market">MC Market</label>
                                    <select name="manager_core_market" id="edit_manager_core_market" class="form-control">
                                        @if(empty($mcMarkets))
                                            <option value="jita">Jita (default)</option>
                                        @else
                                            @if($hubMarkets->isNotEmpty())
                                                <optgroup label="Hub markets">
                                                    @foreach($hubMarkets as $m)
                                                        <option value="{{ $m['key'] }}">{{ $m['label'] }}</option>
                                                    @endforeach
                                                </optgroup>
                                            @endif
                                            @if($citadelMarkets->isNotEmpty())
                                                <optgroup label="Citadel markets">
                                                    @foreach($citadelMarkets as $m)
                                                        <option value="{{ $m['key'] }}">{{ $m['label'] }}</option>
                                                    @endforeach
                                                </optgroup>
                                            @endif
                                        @endif
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="edit_manager_core_variant">Price variant</label>
                                    <select name="manager_core_variant" id="edit_manager_core_variant" class="form-control">
                                        <option value="min">min</option>
                                        <option value="max">max</option>
                                        <option value="avg">avg</option>
                                        <option value="median">median</option>
                                        <option value="percentile">percentile</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="edit_price_cache_ttl_minutes">Cache TTL (minutes)</label>
                                <input type="number" name="price_cache_ttl_minutes" id="edit_price_cache_ttl_minutes"
                                       class="form-control" min="0" max="1440" step="1">
                                <small class="text-muted">Fuzzwork / Janice. 0 disables. MC bypasses BB's cache.</small>
                            </div>
                        </div>
                    </div>

                    <div class="checkbox">
                        <label>
                            <input type="checkbox" name="fallback_to_jita" id="edit_fallback_to_jita" value="1">
                            Fall back to Jita on zero-price items
                        </label>
                    </div>

                    <div class="checkbox">
                        <label>
                            <input type="checkbox" name="enabled" id="edit_enabled" value="1">
                            Enable buyback
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-bb-primary">Save changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Discord role-picker modal + JS (used by the inline webhooks panel) --}}
@include('buyback-manager::settings._role_picker')
@endsection

@push('javascript')
    <script src="{{ asset('vendor/buyback-manager/js/buyback-manager.js') }}"></script>
    <script>
        // Provider descriptions for the helper text under the dropdown.
        const providerDescriptions = @json(array_map(fn($p) => $p['description'] ?? '', $providers));
        const testProviderUrl = @json(route('buyback-manager.settings.test-provider'));

        function bbActivateTab(target) {
            const $link = $('.bb-settings-page .nav-pills .nav-link[data-tab="' + target + '"]');
            if (!$link.length) return;
            $('.bb-settings-page .nav-pills .nav-link').removeClass('active');
            $link.addClass('active');
            $('.bb-settings-page .settings-section').removeClass('active');
            $('.bb-settings-page .settings-section[data-section="' + target + '"]').addClass('active');
        }

        // ============================================================
        // Inner-page tab switcher (settings sidebar nav-pills)
        // ============================================================
        $('.bb-settings-page .nav-pills .nav-link[data-tab]').on('click', function(e) {
            e.preventDefault();
            const target = $(this).data('tab');

            // Toggle nav-link active state
            $('.bb-settings-page .nav-pills .nav-link').removeClass('active');
            $(this).addClass('active');

            // Toggle settings-section visibility
            $('.bb-settings-page .settings-section').removeClass('active');
            $('.bb-settings-page .settings-section[data-section="' + target + '"]').addClass('active');
            // Reflect the active tab in the URL hash so webhook form
            // submissions (which redirect to #webhooks) land back here.
            try { history.replaceState(null, '', '#' + target); } catch (e) {}
        });

        // On load, open the tab named in the URL hash (webhook actions
        // redirect to settings#webhooks; routing-map deep links, etc.).
        (function () {
            const hash = (window.location.hash || '').replace('#', '');
            if (hash) { bbActivateTab(hash); }
        })();

        // "Routing Map" inline link inside the webhooks tab.
        $(document).on('click', '.js-goto-routing-map', function (e) {
            e.preventDefault();
            bbActivateTab('routing-map');
        });

        // ============================================================
        // Populate edit modal when the edit button is clicked
        // ============================================================
        $('.edit-setting-btn').on('click', function() {
            const btn = $(this);
            $('#edit_id').val(btn.data('id'));
            $('#edit_corporation_id').val(btn.data('corporation-id'));
            $('#edit_character_id').val(btn.data('character-id'));
            $('#edit_base_percentage').val(btn.data('base-percentage'));
            $('#edit_price_source').val(btn.data('price-source'));
            $('#edit_region_id').val(btn.data('region-id'));
            $('#edit_enabled').prop('checked', btn.data('enabled') == 1);
            $('#edit_price_provider').val(btn.data('price-provider') || 'fuzzwork');
            $('#edit_janice_api_key').val(btn.data('janice-api-key') || '');
            $('#edit_janice_market').val(btn.data('janice-market') || 'jita');
            $('#edit_janice_price_method').val(btn.data('janice-price-method') || 'buy');
            $('#edit_manager_core_market').val(btn.data('manager-core-market') || 'jita');
            $('#edit_manager_core_variant').val(btn.data('manager-core-variant') || 'min');
            $('#edit_fallback_to_jita').prop('checked', btn.data('fallback-to-jita') == 1);
            $('#edit_price_cache_ttl_minutes').val(btn.data('price-cache-ttl-minutes') ?? 60);
            $('#edit_target_type').val(btn.data('target-type') || 'my_corp');
            $('#edit_target_corporation_id').val((btn.data('target-corporation-id') || '').toString());
            $('#edit_target_corporation_name').val(btn.data('target-corporation-name') || '');
            $('#edit_offer_lock_hours').val(btn.data('offer-lock-hours') || 24);
            $('#edit_private_auto_nudge_hours').val(btn.data('private-auto-nudge-hours') || 48);

            // Re-run visibility toggles so the right sub-panels are open.
            $('#edit_price_provider').trigger('change');
            $('#edit_price_source').trigger('change');
            $('#edit_target_type').trigger('change');
        });

        // ============================================================
        // Contract target toggle — show the right sub-fields + helper
        // ============================================================
        $('.bb-target-selector').on('change', function() {
            const t = $(this).val();
            const $form = $(this).closest('.bb-settings-form, .modal-content');
            $form.find('.bb-target-player-fields').toggle(t === 'player');
            $form.find('.bb-target-corp-fields').toggle(t === 'corp');
            // Per-target helper text (add form only; edit modal has none).
            $form.find('.bb-th-my_corp').toggle(t === 'my_corp');
            $form.find('.bb-th-corp').toggle(t === 'corp');
            $form.find('.bb-th-player').toggle(t === 'player');
        }).trigger('change');

        // When a known corp is picked, clear the free-text name (and vice
        // versa) so only one corp target source is submitted.
        $('.bb-target-corp-picker').on('change', function() {
            const $form = $(this).closest('.bb-settings-form, .modal-content');
            if ($(this).val()) {
                $form.find('input[name="target_corporation_name"]').val('');
            }
        });
        $(document).on('input', 'input[name="target_corporation_name"]', function() {
            const $form = $(this).closest('.bb-settings-form, .modal-content');
            if ($(this).val()) {
                $form.find('.bb-target-corp-picker').val('');
            }
        });

        // ============================================================
        // price_source <-> region_id visibility (only relevant when
        // provider is Fuzzwork; the .bb-fuzzwork-config wrapper handles
        // the outer hide for non-Fuzzwork providers)
        // ============================================================
        $('#price_source, #edit_price_source').on('change', function() {
            const $form = $(this).closest('.bb-settings-form, .modal-content');
            const $regionField = $form.find('.region-id-field');
            $regionField.toggle($(this).val() === 'region');
        }).trigger('change');

        // ============================================================
        // Provider switch — show only the relevant per-provider panel
        // ============================================================
        $('.bb-provider-selector').on('change', function() {
            const provider = $(this).val();
            const $form = $(this).closest('.bb-settings-form, .modal-content');

            $form.find('.bb-fuzzwork-config').toggle(provider === 'fuzzwork');
            $form.find('.bb-janice-config').toggle(provider === 'janice');
            $form.find('.bb-mc-config').toggle(provider === 'manager-core');

            const desc = providerDescriptions[provider] || '';
            $form.find('.bb-provider-description').text(desc);
        }).trigger('change');

        // ============================================================
        // Test Connection — AJAX live-test of the in-form pricing config
        // ============================================================
        $('.bb-test-provider-btn').on('click', function() {
            const $btn = $(this);
            const $form = $btn.closest('.bb-settings-form, .modal-content');
            const $result = $form.find('.bb-test-result');

            const provider = $form.find('[name=price_provider]').val();
            const payload = {
                _token: $('meta[name=csrf-token]').attr('content') || $form.find('[name=_token]').val(),
                provider: provider,
                janice_api_key: $form.find('[name=janice_api_key]').val() || '',
                janice_market: $form.find('[name=janice_market]').val() || 'jita',
                janice_price_method: $form.find('[name=janice_price_method]').val() || 'buy',
                manager_core_market: $form.find('[name=manager_core_market]').val() || 'jita',
                manager_core_variant: $form.find('[name=manager_core_variant]').val() || 'min',
            };

            $btn.prop('disabled', true);
            $result.html('<i class="fa fa-spinner fa-spin"></i> Testing...');

            $.post(testProviderUrl, payload)
                .done(function(response) {
                    if (response.success) {
                        $result.html('<span class="label label-success"><i class="fa fa-check"></i> ' + (response.message || 'Connection OK') + '</span>');
                    } else {
                        $result.html('<span class="label label-danger"><i class="fa fa-times"></i> ' + (response.message || 'Test failed') + '</span>');
                    }
                })
                .fail(function(xhr) {
                    const msg = (xhr.responseJSON && xhr.responseJSON.message) || 'Test request failed';
                    $result.html('<span class="label label-danger"><i class="fa fa-times"></i> ' + msg + '</span>');
                })
                .always(function() {
                    $btn.prop('disabled', false);
                });
        });
    </script>
@endpush
