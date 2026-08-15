@extends('web::layouts.grids.12')

@section('title', 'Buyback Manager - Analytics')
@section('page_header', 'Buyback Analytics')

@push('head')
    <link rel="stylesheet" href="{{ asset('vendor/buyback-manager/css/buyback-manager.css') }}?v=4">
    <style>
        .buyback-manager-wrapper .bb-metric {
            background: var(--bb-dark-card, rgba(255,255,255,0.03));
            border: 1px solid var(--bb-border, #2b3038);
            border-radius: 10px;
            padding: 14px 16px;
            height: 100%;
        }
        .buyback-manager-wrapper .bb-metric .label {
            font-size: 12px; color: #8b95a5; text-transform: uppercase; letter-spacing: 0.04em;
        }
        .buyback-manager-wrapper .bb-metric .value {
            font-size: 22px; font-weight: 600; color: #e6edf3; margin-top: 2px;
        }
        .buyback-manager-wrapper .bb-metric .sub { font-size: 12px; color: #6e7681; }

        /* Horizontal bars: the ranking is the point, so the bar is a
           proportion of the top row rather than a full chart library. */
        .buyback-manager-wrapper .bb-bar-row { margin-bottom: 9px; }
        .buyback-manager-wrapper .bb-bar-head {
            display: flex; justify-content: space-between; font-size: 13px; margin-bottom: 3px;
        }
        .buyback-manager-wrapper .bb-bar-track {
            height: 6px; border-radius: 3px; background: rgba(255,255,255,0.06); overflow: hidden;
        }
        .buyback-manager-wrapper .bb-bar-fill {
            height: 100%; border-radius: 3px;
            background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
        }
        .buyback-manager-wrapper .bb-funnel-step {
            display: flex; align-items: center; gap: 10px; padding: 7px 0;
        }
        .buyback-manager-wrapper .bb-funnel-step .n {
            font-size: 20px; font-weight: 600; color: #e6edf3; min-width: 90px;
        }
    </style>
@endpush

@php
    // Shared renderer for the ranked lists. Scaling every bar against the
    // largest value keeps the comparison honest across panels.
    $bbBars = function (array $rows, string $valueKey = 'isk') {
        $max = 0;
        foreach ($rows as $r) { $max = max($max, (float) $r[$valueKey]); }
        return $max ?: 1;
    };
@endphp

@section('content')
<div class="buyback-manager-wrapper">

    {{-- Filters --}}
    <div class="card card-dark">
        <div class="card-body">
            <form method="GET" action="{{ route('buyback-manager.analytics.index') }}" class="row">
                <div class="col-md-4">
                    <div class="form-group">
                        <label for="corporation_id">Corporation</label>
                        <select name="corporation_id" id="corporation_id" class="form-control">
                            <option value="">All corporations</option>
                            @foreach($corporations as $corpId => $corpName)
                                <option value="{{ $corpId }}" {{ (int) $corporationId === (int) $corpId ? 'selected' : '' }}>
                                    {{ $corpName }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="form-group">
                        <label for="days">Period</label>
                        <select name="days" id="days" class="form-control">
                            @foreach([7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 90 days', 180 => 'Last 180 days', 365 => 'Last year'] as $d => $lbl)
                                <option value="{{ $d }}" {{ $days === $d ? 'selected' : '' }}>{{ $lbl }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="form-group">
                        <label>&nbsp;</label>
                        <button type="submit" class="btn btn-bb-primary form-control">
                            <i class="fa fa-filter"></i> Apply
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    {{-- Headline --}}
    <div class="row" style="margin-bottom:1rem;">
        <div class="col-md-3">
            <div class="bb-metric">
                <div class="label">Total paid</div>
                <div class="value">{{ number_format($headline['total_paid'], 2) }}</div>
                <div class="sub">ISK across {{ number_format($headline['contracts']) }} buybacks</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="bb-metric">
                <div class="label">Average buyback</div>
                <div class="value">{{ number_format($headline['average'], 2) }}</div>
                <div class="sub">ISK per contract</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="bb-metric">
                <div class="label">Sellers</div>
                <div class="value">{{ number_format($headline['sellers']) }}</div>
                <div class="sub">distinct characters</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="bb-metric">
                <div class="label">Items bought</div>
                <div class="value">{{ number_format($headline['items']) }}</div>
                <div class="sub">line items</div>
            </div>
        </div>
    </div>

    {{-- Funnel --}}
    <div class="card card-dark">
        <div class="card-header">
            <h3 class="card-title"><i class="fa fa-filter"></i> Quote to payout</h3>
            <div class="card-tools">
                <span class="badge badge-info">{{ $funnel['conversion'] }}% converted</span>
            </div>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-4">
                    <div class="bb-funnel-step">
                        <span class="n">{{ number_format($funnel['appraisals']) }}</span>
                        <span class="text-muted">appraisals issued</span>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="bb-funnel-step">
                        <span class="n">{{ number_format($funnel['matched']) }}</span>
                        <span class="text-muted">keys used in a contract</span>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="bb-funnel-step">
                        <span class="n">{{ number_format($funnel['paid']) }}</span>
                        <span class="text-muted">completed and paid</span>
                    </div>
                </div>
            </div>
            <p class="text-muted" style="margin-bottom:0;">
                The gap between quotes issued and keys used is the share of people who were given a
                price and did not sell. A falling conversion rate usually means the rates have drifted
                behind the market.
            </p>
        </div>
    </div>

    <div class="row">
        {{-- Top items --}}
        <div class="col-md-6">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title"><i class="fa fa-cube"></i> Most bought items</h3>
                </div>
                <div class="card-body">
                    @if(empty($topItems))
                        <p class="text-muted text-center" style="margin-bottom:0;">Nothing bought in this period.</p>
                    @else
                        @php $max = $bbBars($topItems); @endphp
                        @foreach($topItems as $row)
                            <div class="bb-bar-row">
                                <div class="bb-bar-head">
                                    <span>{{ $row['name'] }}</span>
                                    <span class="text-muted">{{ number_format($row['isk'], 2) }} ISK &middot; {{ number_format($row['qty']) }}x</span>
                                </div>
                                <div class="bb-bar-track">
                                    <div class="bb-bar-fill" style="width: {{ max(2, round(($row['isk'] / $max) * 100)) }}%;"></div>
                                </div>
                            </div>
                        @endforeach
                    @endif
                </div>
            </div>
        </div>

        {{-- Top groups + categories --}}
        <div class="col-md-6">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title"><i class="fa fa-layer-group"></i> By group</h3>
                </div>
                <div class="card-body">
                    @if(empty($topGroups))
                        <p class="text-muted text-center" style="margin-bottom:0;">No data for this period.</p>
                    @else
                        @php $max = $bbBars($topGroups); @endphp
                        @foreach($topGroups as $row)
                            <div class="bb-bar-row">
                                <div class="bb-bar-head">
                                    <span>{{ $row['name'] }}</span>
                                    <span class="text-muted">{{ number_format($row['isk'], 2) }} ISK</span>
                                </div>
                                <div class="bb-bar-track">
                                    <div class="bb-bar-fill" style="width: {{ max(2, round(($row['isk'] / $max) * 100)) }}%;"></div>
                                </div>
                            </div>
                        @endforeach
                    @endif
                </div>
            </div>

            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title"><i class="fa fa-th-large"></i> By category</h3>
                </div>
                <div class="card-body">
                    @if(empty($topCategories))
                        <p class="text-muted text-center" style="margin-bottom:0;">No data for this period.</p>
                    @else
                        @php $max = $bbBars($topCategories); @endphp
                        @foreach($topCategories as $row)
                            <div class="bb-bar-row">
                                <div class="bb-bar-head">
                                    <span>{{ $row['name'] }}</span>
                                    <span class="text-muted">{{ number_format($row['isk'], 2) }} ISK</span>
                                </div>
                                <div class="bb-bar-track">
                                    <div class="bb-bar-fill" style="width: {{ max(2, round(($row['isk'] / $max) * 100)) }}%;"></div>
                                </div>
                            </div>
                        @endforeach
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        {{-- Top sellers --}}
        <div class="col-md-6">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title"><i class="fa fa-users"></i> Top sellers</h3>
                </div>
                <div class="card-body">
                    @if(empty($topSellers))
                        <p class="text-muted text-center" style="margin-bottom:0;">No sellers in this period.</p>
                    @else
                        <table class="table table-bb-styled table-compact">
                            <thead>
                                <tr>
                                    <th>Character</th>
                                    <th class="text-right">Buybacks</th>
                                    <th class="text-right">ISK paid</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($topSellers as $row)
                                    <tr>
                                        <td>
                                            <img src="https://images.evetech.net/characters/{{ $row['character_id'] }}/portrait?size=32"
                                                 style="width:22px; height:22px; vertical-align:middle; margin-right:6px;">
                                            {{ $row['name'] }}
                                        </td>
                                        <td class="text-right">{{ number_format($row['contracts']) }}</td>
                                        <td class="text-right">{{ number_format($row['isk'], 2) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
            </div>
        </div>

        {{-- Review flags --}}
        <div class="col-md-6">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title"><i class="fa fa-exclamation-triangle"></i> Why contracts were flagged</h3>
                </div>
                <div class="card-body">
                    @if(empty($flags))
                        <p class="text-muted text-center" style="margin-bottom:0;">Nothing flagged in this period.</p>
                    @else
                        <table class="table table-bb-styled table-compact">
                            <tbody>
                                @foreach($flags as $row)
                                    <tr>
                                        <td>{{ $row['label'] }}</td>
                                        <td class="text-right">{{ number_format($row['count']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                        <p class="text-muted" style="margin-bottom:0;">
                            A lot of stale-quote flags means the stale window is too short for how long people take to
                            haul. A lot of wrong-location flags means the accepted locations are not clear enough.
                        </p>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Quoted but not sold --}}
    <div class="card card-dark">
        <div class="card-header">
            <h3 class="card-title"><i class="fa fa-times-circle"></i> Quoted but never sold</h3>
        </div>
        <div class="card-body">
            @if(empty($quotedNotSold))
                <p class="text-muted text-center" style="margin-bottom:0;">
                    No unsold quotes on record.
                </p>
            @else
                @php $max = $bbBars($quotedNotSold); @endphp
                @foreach($quotedNotSold as $row)
                    <div class="bb-bar-row">
                        <div class="bb-bar-head">
                            <span>{{ $row['name'] }}</span>
                            <span class="text-muted">{{ number_format($row['isk'], 2) }} ISK &middot; {{ number_format($row['quotes']) }} quotes</span>
                        </div>
                        <div class="bb-bar-track">
                            <div class="bb-bar-fill" style="width: {{ max(2, round(($row['isk'] / $max) * 100)) }}%; background: linear-gradient(90deg,#eab308 0%,#f97316 100%);"></div>
                        </div>
                    </div>
                @endforeach
            @endif
            <div class="alert alert-info" style="margin-top:12px; margin-bottom:0;">
                <i class="fa fa-info-circle"></i>
                Items people were quoted for but never contracted, ranked by the ISK that walked away. A large
                figure usually means the rate on that item is not competitive.
                <strong>This panel only covers the appraisal item-retention window</strong> (the line-item rows are
                pruned early), unlike every other panel here, which covers the full period.
            </div>
        </div>
    </div>

</div>
@endsection
