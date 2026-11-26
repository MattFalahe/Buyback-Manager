@extends('web::layouts.grids.12')

@section('title', 'Buyback Manager - Settings')
@section('page_header', 'Buyback Settings')

@push('head')
    @include("buyback-manager::settings._settings_styles")
    <link rel="stylesheet" href="{{ asset('vendor/buyback-manager/css/buyback-manager.css') }}?v=7">
    <style>
        /* Inner settings sidebar, matching the canonical pattern from
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
                        <li class="nav-item">
                            <a href="#" class="nav-link" data-tab="access">
                                <i class="fas fa-user-shield"></i> Programme Access
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
                                        $market = $pricing[$setting->id]['market'] ?? 'Jita';
                                        $priceStatus = $pricing[$setting->id]['status'] ?? null;
                                        $fallbackLabel = $priceStatus && $priceStatus->fallback
                                            ? ($providers[$priceStatus->fallback]['name'] ?? ucfirst($priceStatus->fallback))
                                            : null;
                                        $targetType = $setting->target_type ?? 'my_corp';
                                        $targetLabel = match($targetType) {
                                            'player' => 'Player: ' . $setting->targetDisplayLabel(),
                                            'corp' => 'Corp: ' . $setting->targetDisplayLabel(),
                                            default => 'My Corporation',
                                        };
                                        $targetBadge = match($targetType) {
                                            'player' => 'warning',
                                            'corp' => 'info',
                                            default => 'secondary',
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
                                                <span class="badge badge-success">Enabled</span>
                                            @else
                                                <span class="badge badge-secondary">Disabled</span>
                                            @endif
                                        </td>
                                        <td>{{ $setting->base_percentage }}%</td>
                                        <td>
                                            <span class="badge badge-{{ $targetBadge }}">{{ $targetLabel }}</span>
                                            <br><small class="text-muted">Sellers: {{ $setting->sellerScopeLabel() }}</small>
                                            @if($setting->hasMemberRates())
                                                <br><small class="text-muted">Members of {{ $setting->memberGroupName() }}: +{{ rtrim(rtrim(number_format($setting->memberBonus(), 2, '.', ''), '0'), '.') }} points</small>
                                            @endif
                                        </td>
                                        <td>
                                            <span class="badge badge-info">{{ $providerLabel }}</span>
                                            @if($priceStatus && $priceStatus->failing)
                                                <br><span class="badge badge-danger" title="{{ $priceStatus->error }}">Not answering since {{ optional($priceStatus->since)->diffForHumans() }}</span>
                                                @if($fallbackLabel)
                                                    <br><small class="text-muted">{{ $fallbackLabel }} {{ $priceStatus->fallback_ok ? 'is standing in' : 'is not answering either' }}</small>
                                                @else
                                                    <br><small class="text-muted">No fallback provider; quotes use the last cached prices</small>
                                                @endif
                                            @endif
                                        </td>
                                        <td>
                                            {{ $market }}
                                            @if(! empty($pricing[$setting->id]['mc_problem']))
                                                <br><small class="text-warning"><i class="fa fa-exclamation-triangle"></i> {{ $pricing[$setting->id]['mc_problem'] }}</small>
                                            @endif
                                        </td>
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
                                                    data-fallback-provider="{{ $setting->fallback_provider ?? 'fuzzwork' }}"
                                                    data-has-janice-key="{{ $setting->janice_api_key ? 1 : 0 }}"
                                                    data-janice-market="{{ $setting->janice_market }}"
                                                    data-janice-price-method="{{ $setting->janice_price_method }}"
                                                    data-manager-core-market="{{ $setting->manager_core_market }}"
                                                    data-manager-core-provider="{{ $setting->manager_core_provider }}"
                                                    data-fallback-to-jita="{{ $setting->fallback_to_jita ? 1 : 0 }}"
                                                    data-price-cache-ttl-minutes="{{ $setting->price_cache_ttl_minutes ?? 60 }}"
                                                    data-max-deviation-percent="{{ $setting->max_deviation_percent ?? '1.00' }}"
                                                    data-thin-market-guard-percent="{{ $setting->thin_market_guard_percent ?? '10.00' }}"
                                                    data-appraisal-stale-hours="{{ $setting->appraisal_stale_hours ?? 48 }}"
                                                    data-target-type="{{ $setting->target_type ?? 'my_corp' }}"
                                                    data-target-corporation-id="{{ $setting->target_corporation_id }}"
                                                    data-target-corporation-name="{{ $setting->target_corporation_name }}"
                                                    data-seller-scope="{{ $setting->sellerScope() === \BuybackManager\Models\BuybackSetting::SELLERS_ANY ? 'any' : 'members' }}"
                                                    data-member-scope="{{ $setting->memberScope() }}"
                                                    data-seller-alliance-id="{{ $setting->seller_alliance_id }}"
                                                    data-member-bonus="{{ number_format($setting->memberBonus(), 2, '.', '') }}"
                                                    data-open-in-game="{{ $setting->open_in_game ? 1 : 0 }}"
                                                    data-auto-nudge-hours="{{ $setting->auto_nudge_hours ?? 48 }}"
                                                    data-appraisal-item-retention-days="{{ $setting->appraisal_item_retention_days ?? 14 }}"
                                                    data-appraisal-retention-days="{{ $setting->appraisal_retention_days ?? 180 }}">
                                                <i class="fa fa-edit"></i>
                                            </button>
                                            <a href="{{ route('buyback-manager.settings.public.edit', $setting->id) }}"
                                               class="btn btn-xs {{ $setting->publicPageOpen() ? 'btn-success' : 'btn-default' }}"
                                               title="Public landing page{{ $setting->publicPageOpen() ? ' (live)' : '' }}">
                                                <i class="fa fa-globe"></i>
                                            </a>
                                            <a href="{{ route('buyback-manager.settings.locations.index', $setting->id) }}"
                                               class="btn btn-xs btn-default"
                                               title="Buyback locations">
                                                <i class="fa fa-map-marker-alt"></i>
                                            </a>
                                            @if($managesEveryCorporation)
                                                <form action="{{ route('buyback-manager.settings.destroy', $setting->id) }}"
                                                      method="POST"
                                                      style="display: inline;"
                                                      onsubmit="return confirm('Delete this programme? Its pricing rules, locations and named managers and viewers go with it. Its contracts and appraisals are kept.');">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="btn btn-xs btn-danger">
                                                        <i class="fa fa-trash"></i>
                                                    </button>
                                                </form>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="8" class="text-center text-muted">
                                            {{ $managesEveryCorporation ? 'No settings configured yet. Add your first corporation below.' : 'You are not named a manager of any buyback programme yet. A Buyback Manager admin names managers under Programme Access.' }}
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                {{-- Add new corporation: an admin's, since only an admin names who runs it --}}
                @if($managesEveryCorporation)
                <div class="card card-dark">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fa fa-plus"></i> Add new corporation</h3>
                    </div>
                    <form action="{{ route('buyback-manager.settings.store') }}" method="POST" class="bb-settings-form">
                        @csrf
                        <div class="card-body">

                            {{-- Run by --}}
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="corporation_id">Run by <span class="text-danger">*</span></label>
                                        <select name="corporation_id" id="corporation_id" class="form-control" required>
                                            <option value="">-- Select Corporation --</option>
                                            @foreach($corporations as $corporation)
                                                <option value="{{ $corporation->corporation_id }}">{{ $corporation->name }}</option>
                                            @endforeach
                                        </select>
                                        <small class="text-muted">The corporation that runs this programme and sets its rates. Where contracts go and who can sell are set below.</small>
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

                            {{-- Contracts --}}
                            <hr>
                            <h5 class="bb-section-heading"><i class="fa fa-file-contract"></i> Contracts</h5>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="target_type">Where do sellers send the contract? <span class="text-danger">*</span></label>
                                        <select name="target_type" id="target_type" class="form-control bb-target-selector" required>
                                            <option value="my_corp" selected>My Corporation (any member with contract rights accepts)</option>
                                            <option value="corp">Specific Corporation</option>
                                            <option value="player">Specific Player</option>
                                        </select>
                                        <small class="text-muted bb-target-help">
                                            <span class="bb-th-my_corp">Sellers contract the corporation running it. Any director can accept.</span>
                                            <span class="bb-th-corp" style="display:none;">Sellers contract a specific corporation (e.g. a holding/buyback corp).</span>
                                            <span class="bb-th-player" style="display:none;">Sellers contract one designated character who accepts or rejects.</span>
                                        </small>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label for="max_deviation_percent">Price tolerance (%)</label>
                                        <input type="number" name="max_deviation_percent" id="max_deviation_percent" class="form-control"
                                               value="1.00" min="0" max="100" step="0.01">
                                        <small class="text-muted">Flag a contract if the asked ISK drifts further than this from the quote. Contracts under {{ number_format(\BuybackManager\Services\ContractService::PRICE_CHECK_FROM) }} ISK are not checked.</small>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label for="appraisal_stale_hours">Quote freshness (hours)</label>
                                        <input type="number" name="appraisal_stale_hours" id="appraisal_stale_hours" class="form-control"
                                               value="48" min="1" max="720" step="1">
                                        <small class="text-muted">Older quotes are flagged as stale.</small>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label for="auto_nudge_hours">Auto-nudge (hours)</label>
                                        <input type="number" name="auto_nudge_hours" id="auto_nudge_hours" class="form-control"
                                               value="48" min="0" max="720" step="1">
                                        <small class="text-muted">Remind if a contract sits unaccepted. 0 disables.</small>
                                    </div>
                                </div>
                                <div class="col-md-9">
                                    <div class="form-group">
                                        <label>Open in game</label>
                                        <div class="checkbox" style="margin-top:0;">
                                            <label>
                                                <input type="checkbox" name="open_in_game" value="1">
                                                Show a button that opens a contract in the EVE client
                                            </label>
                                        </div>
                                        @include('buyback-manager::settings._open_in_game_status')
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
                                        <small class="text-muted">External corps BB can't see are <strong>instructions-only</strong>: a director confirms those contracts by hand.</small>
                                    </div>
                                </div>
                            </div>

                            {{-- Sellers and member rates --}}
                            <hr>
                            <h5 class="bb-section-heading"><i class="fa fa-users"></i> Sellers and member rates</h5>
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label for="member_scope">Members</label>
                                        <select name="member_scope" id="member_scope" class="form-control bb-member-scope">
                                            <option value="corporation" selected>The corporation running it</option>
                                            <option value="alliance">An alliance</option>
                                        </select>
                                        <small class="text-muted">A SeAT account with any character in this group is a member, and so are all its characters.</small>
                                    </div>
                                </div>
                                <div class="col-md-4 bb-member-alliance-field" style="display:none;">
                                    <div class="form-group">
                                        <label for="seller_alliance_id">Alliance <span class="text-danger">*</span></label>
                                        <select name="seller_alliance_id" id="seller_alliance_id" class="form-control">
                                            <option value="">-- Select Alliance --</option>
                                            @foreach($alliances as $alliance)
                                                <option value="{{ $alliance->alliance_id }}">{{ $alliance->name }}{{ $alliance->ticker ? ' [' . $alliance->ticker . ']' : '' }}</option>
                                            @endforeach
                                        </select>
                                        <small class="text-muted">Listed once SeAT holds a corporation in it. A seller's alliance comes from SeAT, then EveWho, then zKillboard.</small>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label for="member_bonus">Member bonus</label>
                                        <div class="input-group">
                                            <input type="number" name="member_bonus" id="member_bonus" class="form-control"
                                                   value="0" min="0" max="100" step="0.01">
                                            <span class="input-group-addon">points</span>
                                        </div>
                                        <small class="text-muted">Added to every rule for members signed in to SeAT, up to 100%. Rules paying 0% stay at 0%. 0 means one rate for everyone.</small>
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="seller_scope">Who can sell?</label>
                                        <select name="seller_scope" id="seller_scope" class="form-control">
                                            <option value="any" selected>Anyone</option>
                                            <option value="members">Members only</option>
                                        </select>
                                        <small class="text-muted">A contract from anyone else is still tracked, but flagged for a director. It never stops a sale.</small>
                                    </div>
                                </div>
                            </div>

                            {{-- Records --}}
                            <hr>
                            <h5 class="bb-section-heading"><i class="fa fa-archive"></i> Records</h5>
                            <div class="row">
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label for="appraisal_item_retention_days">Keep item detail (days)</label>
                                        <input type="number" name="appraisal_item_retention_days" id="appraisal_item_retention_days" class="form-control"
                                               value="14" min="1" max="365" step="1">
                                        <small class="text-muted">Line-by-line appraisal rows.</small>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label for="appraisal_retention_days">Keep appraisals (days)</label>
                                        <input type="number" name="appraisal_retention_days" id="appraisal_retention_days" class="form-control"
                                               value="180" min="1" max="3650" step="1">
                                        <small class="text-muted">Totals kept for statistics.</small>
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
                                                    @if(!($info['available'] ?? true)) ({{ $info['unavailable_label'] ?? 'not installed' }}) @endif
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
                                            <label for="price_source">Fuzzwork market</label>
                                            <select name="price_source" id="price_source" class="form-control">
                                                <option value="jita">Jita 4-4</option>
                                                <option value="amarr">Amarr VIII</option>
                                                <option value="dodixie">Dodixie IX</option>
                                                <option value="hek">Hek VIII</option>
                                                <option value="rens">Rens VI</option>
                                                <option value="region">Another region</option>
                                            </select>
                                            <small class="text-muted">A hub is priced at its main station, such as Jita 4-4. Another region takes every order in it.</small>
                                        </div>
                                    </div>
                                    <div class="col-md-6 region-id-field">
                                        <div class="form-group">
                                            <label for="region_id">Region ID</label>
                                            <input type="number" name="region_id" id="region_id" class="form-control"
                                                   placeholder="e.g. 10000060 (Delve)">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {{-- Janice key: needed when Janice is the provider or the fallback --}}
                            <div class="bb-janice-key-config" style="display: none;">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label for="janice_api_key">Janice API key</label>
                                            <input type="password" name="janice_api_key" id="janice_api_key" class="form-control"
                                                   placeholder="Your Janice API key" autocomplete="new-password">
                                            <small class="text-muted">
                                                Get a key at <a href="https://janice.e-351.com" target="_blank" rel="noopener">janice.e-351.com</a>.
                                                Once saved it is never shown again; leave the field blank to keep it.
                                            </small>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {{-- Janice-only fields --}}
                            <div class="bb-janice-config" style="display: none;">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label for="janice_market">Janice market</label>
                                            <select name="janice_market" id="janice_market" class="form-control">
                                                <option value="jita">Jita</option>
                                                <option value="amarr">Amarr</option>
                                                <option value="dodixie">Dodixie</option>
                                                <option value="hek">Hek</option>
                                                <option value="rens">Rens</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label for="janice_price_method">Price method</label>
                                            <select name="janice_price_method" id="janice_price_method" class="form-control">
                                                <option value="buy">Buy (max buy order)</option>
                                                <option value="sell">Sell (min sell order)</option>
                                                <option value="split">Split (between the two)</option>
                                            </select>
                                            <small class="text-muted">The side a pricing rule with no side of its own pays on. With another provider that is the sell side.</small>
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
                                                $mcMarketNotes = collect($mcMarkets ?? [])->filter(fn ($m) => ! empty($m['note']))->values();
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
                                            @foreach($mcMarketNotes as $m)
                                                <div class="text-warning small" style="margin-top:4px;">
                                                    <i class="fa fa-info-circle"></i>
                                                    <strong>{{ $m['label'] }}:</strong> {{ $m['note'] }}
                                                </div>
                                            @endforeach
                                            @if($mcOverrideMarket)
                                                <div class="text-warning" style="margin-top:4px;">
                                                    <i class="fa fa-exclamation-triangle"></i>
                                                    Manager Core's Pricing Preferences set Buyback Manager's market to <strong>{{ $mcOverrideMarket }}</strong>, so that is where quotes price.
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        @include('buyback-manager::settings._mc_provider', ['fieldId' => 'manager_core_provider'])
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="fallback_provider">Fallback provider</label>
                                        <select name="fallback_provider" id="fallback_provider" class="form-control bb-fallback-selector">
                                            @foreach($providers as $key => $info)
                                                <option value="{{ $key }}" @if(!($info['available'] ?? true)) disabled @endif>
                                                    {{ $info['name'] }}
                                                    @if(!($info['available'] ?? true)) ({{ $info['unavailable_label'] ?? 'not installed' }}) @endif
                                                </option>
                                            @endforeach
                                            <option value="none">None</option>
                                        </select>
                                        <small class="text-muted">
                                            Prices the quote when the provider fails outright, and any item it could not answer for.
                                            It prices the same market, or Jita when it cannot price there. Anything still missing
                                            keeps the last price Buyback Manager saw.
                                        </small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="price_cache_ttl_minutes">Cache TTL (minutes)</label>
                                        <input type="number" name="price_cache_ttl_minutes" id="price_cache_ttl_minutes"
                                               class="form-control" value="60" min="0" max="1440" step="1">
                                        <small class="text-muted">
                                            How long a Fuzzwork or Janice price is used before asking again. 0 asks every time.
                                            Manager Core keeps its own prices, so this does not apply to it.
                                        </small>
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="thin_market_guard_percent">Thin-market guard (%)</label>
                                        <input type="number" name="thin_market_guard_percent" id="thin_market_guard_percent"
                                               class="form-control" value="10" min="0" max="1000" step="0.01">
                                        <small class="text-muted">
                                            When the top buy order stands more than this far above the average of the top 5%
                                            of buy orders, the item is priced at that average and the quote says so. A lone high
                                            order on a thin market cannot then set the price. Only Fuzzwork sends that average, so
                                            only Fuzzwork prices are guarded, asked directly or through Manager Core. 0 turns it off.
                                        </small>
                                    </div>
                                </div>
                            </div>

                            <div class="checkbox" style="margin-top:8px;">
                                <label>
                                    <input type="checkbox" name="fallback_to_jita" value="1" checked>
                                    Use Jita's price for a side with no orders at the market
                                </label>
                                <br>
                                <small class="text-muted">Only for markets other than Jita. Without it, an item with no orders on the side its rule pays on is not accepted.</small>
                            </div>

                        </div>
                        <div class="card-footer">
                            <button type="submit" class="btn btn-bb-primary">
                                <i class="fa fa-save"></i> Save setting
                            </button>
                        </div>
                    </form>
                </div>
                @endif
            </div>

            {{-- ============================================================
                 TAB: PROGRAMME ACCESS
                 ============================================================ --}}
            <div class="settings-section" data-section="access">
                @include('buyback-manager::settings._access_panel')
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
                            or categories. A rule for the exact item decides; otherwise an exclusion
                            wins, then <strong>group &gt; category &gt; base</strong>.
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
                                            <td><span class="badge badge-info">{{ $rules->count() }}</span></td>
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
                            <li><strong>Configure a corporation</strong> in the Configured Corps tab: set the contract target and the pricing provider.</li>
                            <li><strong>Set the rates</strong> on the Pricing Rules page: the default rate, any price exceptions, and anything you refuse to buy.</li>
                            <li><strong>The seller appraises</strong> on the Appraisal page, or on the public page with no login at all. They get a quote and a single-use <strong>appraisal key</strong> on a shareable page.</li>
                            <li><strong>The seller creates the EVE contract</strong> to the configured target, sets the price to the quoted value, and pastes the key into the contract Description.</li>
                            <li><strong>BB matches the contract</strong> to the appraisal within 15 minutes (sync cycle), compares what was asked against what was quoted, and flags anything that does not line up.</li>
                            <li><strong>Discord webhooks fire</strong> for the categories you configured: matched, needs review, completed, cancelled, and so on.</li>
                        </ol>
                        <div class="alert alert-info" style="margin-top:1rem;">
                            <strong><i class="fa fa-shield-alt"></i> Contract target options:</strong>
                            <ul style="margin-bottom:0;">
                                <li><strong>My Corporation</strong>: members contract the corp; anyone with corp contract rights can accept. Auto-confirmed.</li>
                                <li><strong>Specific Corporation</strong>: contract goes to another corp (e.g. a holding/director corp, visible to that corp's directors). Detected automatically if SeAT holds that corp's director token; an external corp typed by name is instructions-only, so a director confirms it by hand.</li>
                                <li><strong>Specific Player</strong>: one designated character receives and accepts (private, only they see it). BB reads that character's own contract feed, so they must be registered in SeAT with the contract-read scope.</li>
                            </ul>
                        </div>
                        <div class="alert alert-warning">
                            <strong><i class="fa fa-lightbulb-o"></i> Pricing tip:</strong>
                            A quote is priced by the provider, then by the fallback provider when the provider
                            fails, then from the last price Buyback Manager saw. Install Manager Core to price
                            at a citadel market it tracks.
                        </div>
                    </div>
                </div>
            </div>

        </div>{{-- /.settings-content --}}
    </div>{{-- /.settings-wrapper --}}
</div>{{-- /.buyback-manager-wrapper --}}

{{-- ============================================================
     EDIT MODAL (stays outside the tab structure: Bootstrap modal)
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
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Run by</label>
                                <p class="form-control-static" id="edit_corporation_name"></p>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="checkbox" style="margin-top:25px;">
                                <label>
                                    <input type="checkbox" name="enabled" id="edit_enabled" value="1">
                                    Enable buyback
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="alert alert-info" style="margin-bottom:0;">
                        <i class="fa fa-percent"></i>
                        The <strong>default rate</strong> and what the programme buys are managed on the
                        <strong>Pricing Rules</strong> page, alongside the exceptions and exclusions they work with.
                    </div>

                    <hr>
                    <h5 class="bb-section-heading"><i class="fa fa-file-contract"></i> Contracts</h5>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="edit_target_type">Contract goes to</label>
                                <select name="target_type" id="edit_target_type" class="form-control bb-target-selector" required>
                                    <option value="my_corp">My Corporation (any member with contract rights accepts)</option>
                                    <option value="corp">Specific Corporation</option>
                                    <option value="player">Specific Player</option>
                                </select>
                                <small class="text-muted">A change applies to new contracts. Ones already open keep the target they were sent to.</small>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label for="edit_max_deviation_percent">Tolerance (%)</label>
                                <input type="number" name="max_deviation_percent" id="edit_max_deviation_percent" class="form-control" min="0" max="100" step="0.01">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label for="edit_appraisal_stale_hours">Quote freshness (h)</label>
                                <input type="number" name="appraisal_stale_hours" id="edit_appraisal_stale_hours" class="form-control" min="1" max="720" step="1">
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-3">
                            <div class="form-group">
                                <label for="edit_auto_nudge_hours">Auto-nudge (hours)</label>
                                <input type="number" name="auto_nudge_hours" id="edit_auto_nudge_hours" class="form-control" min="0" max="720" step="1">
                                <small class="text-muted">0 disables.</small>
                            </div>
                        </div>
                        <div class="col-md-9">
                            <div class="form-group">
                                <label>Open in game</label>
                                <div class="checkbox" style="margin-top:0;">
                                    <label>
                                        <input type="checkbox" name="open_in_game" id="edit_open_in_game" value="1">
                                        Show a button that opens a contract in the EVE client
                                    </label>
                                </div>
                                @include('buyback-manager::settings._open_in_game_status')
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
                    <h5 class="bb-section-heading"><i class="fa fa-users"></i> Sellers and member rates</h5>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label for="edit_member_scope">Members</label>
                                <select name="member_scope" id="edit_member_scope" class="form-control bb-member-scope">
                                    <option value="corporation">The corporation running it</option>
                                    <option value="alliance">An alliance</option>
                                </select>
                                <small class="text-muted">Any SeAT account with a character in this group, and all its characters.</small>
                            </div>
                        </div>
                        <div class="col-md-4 bb-member-alliance-field" style="display:none;">
                            <div class="form-group">
                                <label for="edit_seller_alliance_id">Alliance <span class="text-danger">*</span></label>
                                <select name="seller_alliance_id" id="edit_seller_alliance_id" class="form-control">
                                    <option value="">-- Select Alliance --</option>
                                    @foreach($alliances as $alliance)
                                        <option value="{{ $alliance->alliance_id }}">{{ $alliance->name }}{{ $alliance->ticker ? ' [' . $alliance->ticker . ']' : '' }}</option>
                                    @endforeach
                                </select>
                                <small class="text-muted">Listed once SeAT holds a corporation in it.</small>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label for="edit_member_bonus">Member bonus</label>
                                <div class="input-group">
                                    <input type="number" name="member_bonus" id="edit_member_bonus" class="form-control" min="0" max="100" step="0.01">
                                    <span class="input-group-addon">points</span>
                                </div>
                                <small class="text-muted">On top of every rule, up to 100%. 0 is none.</small>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="edit_seller_scope">Who can sell?</label>
                                <select name="seller_scope" id="edit_seller_scope" class="form-control">
                                    <option value="any">Anyone</option>
                                    <option value="members">Members only</option>
                                </select>
                                <small class="text-muted">Anyone else is flagged for a director, never refused. Applies to new contracts.</small>
                            </div>
                        </div>
                    </div>

                    <hr>
                    <h5 class="bb-section-heading"><i class="fa fa-archive"></i> Records</h5>
                    <div class="row">
                        <div class="col-md-3">
                            <div class="form-group">
                                <label for="edit_appraisal_item_retention_days">Keep item detail (days)</label>
                                <input type="number" name="appraisal_item_retention_days" id="edit_appraisal_item_retention_days" class="form-control" min="1" max="365" step="1">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label for="edit_appraisal_retention_days">Keep appraisals (days)</label>
                                <input type="number" name="appraisal_retention_days" id="edit_appraisal_retention_days" class="form-control" min="1" max="3650" step="1">
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
                                            @if(!($info['available'] ?? true)) ({{ $info['unavailable_label'] ?? 'not installed' }}) @endif
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
                                    <label for="edit_price_source">Fuzzwork market</label>
                                    <select name="price_source" id="edit_price_source" class="form-control">
                                        <option value="jita">Jita 4-4</option>
                                        <option value="amarr">Amarr VIII</option>
                                        <option value="dodixie">Dodixie IX</option>
                                        <option value="hek">Hek VIII</option>
                                        <option value="rens">Rens VI</option>
                                        <option value="region">Another region</option>
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

                    <div class="bb-janice-key-config" style="display: none;">
                        <div class="row">
                            <div class="col-md-12">
                                <div class="form-group">
                                    <label for="edit_janice_api_key">Janice API key</label>
                                    <input type="password" name="janice_api_key" id="edit_janice_api_key" class="form-control" autocomplete="new-password">
                                    <div class="checkbox bb-forget-janice-key" style="display: none; margin-bottom:0;">
                                        <label>
                                            <input type="checkbox" name="forget_janice_api_key" id="edit_forget_janice_api_key" value="1">
                                            Remove the saved key
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="bb-janice-config" style="display: none;">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="edit_janice_market">Janice market</label>
                                    <select name="janice_market" id="edit_janice_market" class="form-control">
                                        <option value="jita">Jita</option>
                                        <option value="amarr">Amarr</option>
                                        <option value="dodixie">Dodixie</option>
                                        <option value="hek">Hek</option>
                                        <option value="rens">Rens</option>
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
                                    @foreach($mcMarketNotes as $m)
                                        <div class="text-warning small" style="margin-top:4px;">
                                            <i class="fa fa-info-circle"></i>
                                            <strong>{{ $m['label'] }}:</strong> {{ $m['note'] }}
                                        </div>
                                    @endforeach
                                    @if($mcOverrideMarket)
                                        <small class="text-warning">
                                            <i class="fa fa-exclamation-triangle"></i>
                                            Manager Core's Pricing Preferences set Buyback Manager's market to <strong>{{ $mcOverrideMarket }}</strong>, so that is where quotes price.
                                        </small>
                                    @endif
                                </div>
                            </div>
                            <div class="col-md-6">
                                @include('buyback-manager::settings._mc_provider', ['fieldId' => 'edit_manager_core_provider'])
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="edit_fallback_provider">Fallback provider</label>
                                <select name="fallback_provider" id="edit_fallback_provider" class="form-control bb-fallback-selector">
                                    @foreach($providers as $key => $info)
                                        <option value="{{ $key }}" @if(!($info['available'] ?? true)) disabled @endif>
                                            {{ $info['name'] }}
                                            @if(!($info['available'] ?? true)) ({{ $info['unavailable_label'] ?? 'not installed' }}) @endif
                                        </option>
                                    @endforeach
                                    <option value="none">None</option>
                                </select>
                                <small class="text-muted">Prices the quote when the provider fails outright, and any item it could not answer for.</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="edit_price_cache_ttl_minutes">Cache TTL (minutes)</label>
                                <input type="number" name="price_cache_ttl_minutes" id="edit_price_cache_ttl_minutes"
                                       class="form-control" min="0" max="1440" step="1">
                                <small class="text-muted">Fuzzwork and Janice only. 0 asks every time.</small>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="edit_thin_market_guard_percent">Thin-market guard (%)</label>
                                <input type="number" name="thin_market_guard_percent" id="edit_thin_market_guard_percent"
                                       class="form-control" min="0" max="1000" step="0.01">
                                <small class="text-muted">A top buy order further than this above the top-5% average is passed over for the average. Fuzzwork prices only, asked directly or through Manager Core. 0 turns it off.</small>
                            </div>
                        </div>
                    </div>

                    <div class="checkbox">
                        <label>
                            <input type="checkbox" name="fallback_to_jita" id="edit_fallback_to_jita" value="1">
                            Use Jita's price for a side with no orders at the market
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
    <script src="{{ asset('vendor/buyback-manager/js/buyback-manager.js') }}?v=2"></script>
    <script>
        // Provider descriptions for the helper text under the dropdown.
        const providerDescriptions = @json(array_map(fn($p) => $p['description'] ?? '', $providers));
        const testProviderUrl = @json(route('buyback-manager.settings.test-provider'));
        // What Manager Core can price each of its markets through.
        const mcProviders = @json((object) ($mcProviders ?? []));

        // Inside AdminLTE's content wrapper the modal opens under its own
        // backdrop, so it lives on the body.
        $('#editSettingModal').appendTo('body');

        // The provider list follows the Manager Core market picked beside it.
        // A provider Manager Core cannot use there is listed but disabled,
        // with the reason on hover, except the one already saved, which
        // stays selectable so saving says why it no longer works rather
        // than dropping it quietly.
        function bbFillMcProviders($form, chosen) {
            const $select = $form.find('.bb-mc-provider');
            if (!$select.length) { return; }

            const market = $form.find('[name=manager_core_market]').val() || 'jita';
            const providers = mcProviders[market] || {};
            const keep = chosen !== undefined ? chosen : ($select.val() || '');
            const own = Object.keys(providers).find(function (key) { return providers[key].own; });

            $select.empty().append($('<option value=""></option>').text(
                own ? "The market's own (" + providers[own].label + ')' : "The market's own"
            ));
            Object.keys(providers).forEach(function (key) {
                const p = providers[key];
                if (p.own) { return; }
                const $option = $('<option></option>').val(key).text(p.label + (p.ok ? '' : ' (cannot price here)'));
                if (!p.ok) {
                    $option.attr('title', p.reason || '');
                    if (key !== keep) { $option.prop('disabled', true); }
                }
                $select.append($option);
            });

            $select.val($select.find('option').filter(function () { return this.value === keep && !this.disabled; }).length ? keep : '');
        }

        $(document).on('change', '[name=manager_core_market]', function () {
            bbFillMcProviders($(this).closest('.bb-settings-form, .modal-content'));
        });
        $('.bb-settings-form').each(function () { bbFillMcProviders($(this)); });

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
            $('#edit_corporation_name').text(btn.data('corporation-name') || '');
            $('#edit_character_id').val(btn.data('character-id'));
            $('#edit_price_source').val(btn.data('price-source'));
            $('#edit_region_id').val(btn.data('region-id'));
            $('#edit_enabled').prop('checked', btn.data('enabled') == 1);
            $('#edit_price_provider').val(btn.data('price-provider') || 'fuzzwork');
            $('#edit_fallback_provider').val(btn.data('fallback-provider') || 'fuzzwork');
            // The saved key never reaches the page: a blank field keeps it.
            const hasKey = btn.data('has-janice-key') == 1;
            $('#edit_janice_api_key').val('').attr('placeholder', hasKey ? 'Saved. Leave blank to keep it.' : 'Your Janice API key');
            $('#edit_forget_janice_api_key').prop('checked', false);
            $('#editSettingModal .bb-forget-janice-key').toggle(hasKey);
            $('#edit_janice_market').val(btn.data('janice-market') || 'jita');
            $('#edit_janice_price_method').val(btn.data('janice-price-method') || 'buy');
            $('#edit_manager_core_market').val(btn.data('manager-core-market') || 'jita');
            bbFillMcProviders($('#edit_manager_core_market').closest('.bb-settings-form'), String(btn.data('manager-core-provider') || ''));
            $('#edit_fallback_to_jita').prop('checked', btn.data('fallback-to-jita') == 1);
            $('#edit_price_cache_ttl_minutes').val(btn.data('price-cache-ttl-minutes') ?? 60);
            $('#edit_target_type').val(btn.data('target-type') || 'my_corp');
            $('#edit_target_corporation_id').val((btn.data('target-corporation-id') || '').toString());
            $('#edit_target_corporation_name').val(btn.data('target-corporation-name') || '');
            $('#edit_seller_scope').val(btn.data('seller-scope') || 'any');
            $('#edit_member_scope').val(btn.data('member-scope') || 'corporation');
            $('#edit_seller_alliance_id').val((btn.data('seller-alliance-id') || '').toString());
            $('#edit_member_bonus').val(btn.data('member-bonus') ?? '0.00');
            $('#edit_open_in_game').prop('checked', btn.data('open-in-game') == 1);
            $('#edit_max_deviation_percent').val(btn.data('max-deviation-percent') || '1.00');
            $('#edit_thin_market_guard_percent').val(btn.data('thin-market-guard-percent') ?? '10.00');
            $('#edit_appraisal_stale_hours').val(btn.data('appraisal-stale-hours') || 48);
            $('#edit_auto_nudge_hours').val(btn.data('auto-nudge-hours') ?? 48);
            $('#edit_appraisal_item_retention_days').val(btn.data('appraisal-item-retention-days') || 14);
            $('#edit_appraisal_retention_days').val(btn.data('appraisal-retention-days') || 180);

            // Re-run visibility toggles so the right sub-panels are open.
            $('#edit_price_provider').trigger('change');
            $('#edit_price_source').trigger('change');
            $('#edit_target_type').trigger('change');
            $('#edit_member_scope').trigger('change');
        });

        $('.bb-member-scope').on('change', function() {
            const $form = $(this).closest('.bb-settings-form, .modal-content');
            $form.find('.bb-member-alliance-field').toggle($(this).val() === 'alliance');
        }).trigger('change');

        // ============================================================
        // Contract target toggle: show the right sub-fields + helper
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
        // Provider switch: show only the relevant per-provider panel
        // ============================================================
        function bbTogglePricingPanels($form) {
            const provider = $form.find('[name=price_provider]').val();
            const fallback = $form.find('[name=fallback_provider]').val();

            $form.find('.bb-fuzzwork-config').toggle(provider === 'fuzzwork');
            $form.find('.bb-janice-config').toggle(provider === 'janice');
            // The key is needed whether Janice prices or stands in.
            $form.find('.bb-janice-key-config').toggle(provider === 'janice' || fallback === 'janice');
            $form.find('.bb-mc-config').toggle(provider === 'manager-core');
        }

        $('.bb-provider-selector').on('change', function() {
            const provider = $(this).val();
            const $form = $(this).closest('.bb-settings-form, .modal-content');
            const $fallback = $form.find('[name=fallback_provider]');

            // A provider cannot stand in for itself.
            $fallback.find('option').each(function () {
                const own = $(this).val() === provider;
                $(this).prop('hidden', own);
            });
            if ($fallback.val() === provider || !$fallback.val()) {
                $fallback.val(provider === 'fuzzwork' ? 'none' : 'fuzzwork');
            }

            bbTogglePricingPanels($form);

            const desc = providerDescriptions[provider] || '';
            $form.find('.bb-provider-description').text(desc);
        }).trigger('change');

        $('.bb-fallback-selector').on('change', function() {
            bbTogglePricingPanels($(this).closest('.bb-settings-form, .modal-content'));
        });

        // ============================================================
        // Test Connection: AJAX live-test of the in-form pricing config
        // ============================================================
        $('.bb-test-provider-btn').on('click', function() {
            const $btn = $(this);
            const $form = $btn.closest('.bb-settings-form, .modal-content');
            const $result = $form.find('.bb-test-result');

            const provider = $form.find('[name=price_provider]').val();
            const payload = {
                _token: $('meta[name=csrf-token]').attr('content') || $form.find('[name=_token]').val(),
                provider: provider,
                // Lets a blank key field test with the saved key.
                id: $form.find('[name=id]').val() || '',
                price_source: $form.find('[name=price_source]').val() || 'jita',
                region_id: $form.find('[name=region_id]').val() || '',
                janice_api_key: $form.find('[name=janice_api_key]').val() || '',
                janice_market: $form.find('[name=janice_market]').val() || 'jita',
                manager_core_market: $form.find('[name=manager_core_market]').val() || 'jita',
                manager_core_provider: $form.find('[name=manager_core_provider]').val() || '',
            };

            // Provider errors are shown as text, never as markup.
            function show(ok, message) {
                // A badge does not wrap by default, and a provider's error can be long.
                const $label = $('<span class="badge" style="white-space: normal; text-align: left;"></span>').addClass(ok ? 'badge-success' : 'badge-danger');
                $label.append($('<i class="fa"></i>').addClass(ok ? 'fa-check' : 'fa-times')).append(' ').append(document.createTextNode(message));
                $result.empty().append($label);
            }

            $btn.prop('disabled', true);
            $result.html('<i class="fa fa-spinner fa-spin"></i> Testing...');

            $.post(testProviderUrl, payload)
                .done(function(response) {
                    show(!!response.success, response.message || (response.success ? 'Connection OK' : 'Test failed'));
                })
                .fail(function(xhr) {
                    show(false, (xhr.responseJSON && xhr.responseJSON.message) || 'Test request failed');
                })
                .always(function() {
                    $btn.prop('disabled', false);
                });
        });
    </script>
@endpush
