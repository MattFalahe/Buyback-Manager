@extends('web::layouts.grids.12')

@section('title', 'Buyback Manager - Statistics')
@section('page_header', 'Buyback Statistics')

@push('head')
    <link rel="stylesheet" href="{{ asset('web/css/buyback-manager/buyback-manager.css') }}">
@endpush

@section('content')
    <div class="buyback-manager-wrapper">
        <!-- Filter Box -->
        <div class="filter-box">
            <form method="GET" action="{{ route('buyback-manager.statistics.index') }}" id="filter-form">
                <div class="row">
                    <div class="col-md-3">
                        <div class="form-group">
                            <label for="corporation-filter">Corporation</label>
                            <select name="corporation_id" id="corporation-filter" class="form-control">
                                <option value="">All Corporations</option>
                                @foreach($corporations as $corpId => $corpName)
                                    <option value="{{ $corpId }}" {{ request('corporation_id') == $corpId ? 'selected' : '' }}>
                                        {{ $corpName }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label for="date-from">Date From</label>
                            <input type="date" name="date_from" id="date-from" class="form-control" 
                                   value="{{ request('date_from', $dateFrom) }}">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label for="date-to">Date To</label>
                            <input type="date" name="date_to" id="date-to" class="form-control" 
                                   value="{{ request('date_to', $dateTo) }}">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>&nbsp;</label>
                            <button type="submit" class="btn btn-primary form-control">
                                <i class="fa fa-filter"></i> Apply Filter
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </div>

        <!-- Summary Stats -->
        <div class="row">
            <div class="col-md-4">
                <div class="stats-box">
                    <i class="fa fa-dollar-sign stats-icon"></i>
                    <div class="stats-number">{{ number_format($totalValue / 1000000, 2) }}M</div>
                    <div class="stats-label">Total Value (ISK)</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stats-box">
                    <i class="fa fa-file-contract stats-icon"></i>
                    <div class="stats-number">{{ number_format($totalContracts) }}</div>
                    <div class="stats-label">Total Contracts</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stats-box">
                    <i class="fa fa-boxes stats-icon"></i>
                    <div class="stats-number">{{ number_format($totalItems) }}</div>
                    <div class="stats-label">Total Items</div>
                </div>
            </div>
        </div>

        <!-- Charts -->
        <div class="row">
            <div class="col-md-12">
                <div class="box box-primary">
                    <div class="box-header with-border">
                        <h3 class="box-title">Daily Buyback Activity</h3>
                    </div>
                    <div class="box-body">
                        <div style="height: 400px;">
                            <canvas id="daily-chart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Top Contributors -->
        <div class="row">
            <div class="col-md-12">
                <div class="box box-success">
                    <div class="box-header with-border">
                        <h3 class="box-title">Top Contributors</h3>
                    </div>
                    <div class="box-body">
                        @forelse($topContributors as $index => $contributor)
                            <div class="top-contributor">
                                <div>
                                    <span class="contributor-rank">{{ $index + 1 }}</span>
                                    <img src="https://images.evetech.net/characters/{{ $contributor->issuer_id }}/portrait?size=32" 
                                         style="width: 32px; height: 32px; vertical-align: middle; margin-right: 10px;">
                                    <span class="contributor-name">{{ $contributor->issuer->name ?? 'Unknown' }}</span>
                                </div>
                                <div class="contributor-stats">
                                    <div style="font-size: 18px; font-weight: bold; color: #00a65a;">
                                        {{ number_format($contributor->total, 2) }} ISK
                                    </div>
                                    <div style="font-size: 12px; color: #666;">
                                        {{ number_format($contributor->count) }} contracts
                                    </div>
                                </div>
                            </div>
                        @empty
                            <p class="text-muted text-center">No data available for the selected period</p>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>

        <!-- Additional Stats -->
        <div class="row">
            <div class="col-md-6">
                <div class="box box-warning">
                    <div class="box-header with-border">
                        <h3 class="box-title">Average Contract Value</h3>
                    </div>
                    <div class="box-body">
                        <div style="text-align: center; padding: 20px;">
                            <div style="font-size: 36px; font-weight: bold; color: #f39c12;">
                                {{ $totalContracts > 0 ? number_format($totalValue / $totalContracts, 2) : '0.00' }} ISK
                            </div>
                            <div style="color: #666; margin-top: 10px;">Per Contract</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="box box-info">
                    <div class="box-header with-border">
                        <h3 class="box-title">Average Items Per Contract</h3>
                    </div>
                    <div class="box-body">
                        <div style="text-align: center; padding: 20px;">
                            <div style="font-size: 36px; font-weight: bold; color: #3c8dbc;">
                                {{ $totalContracts > 0 ? number_format($totalItems / $totalContracts, 0) : '0' }}
                            </div>
                            <div style="color: #666; margin-top: 10px;">Items Per Contract</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('javascript')
    <script src="{{ asset('web/js/buyback-manager/chart.min.js') }}"></script>
    <script>
        // Pass data to JavaScript
        window.chartData = {
            daily: @json($dailyStats)
        };
    </script>
    <script src="{{ asset('web/js/buyback-manager/buyback-manager.js') }}"></script>
    <script src="{{ asset('web/js/buyback-manager/statistics.js') }}"></script>
@endpush
