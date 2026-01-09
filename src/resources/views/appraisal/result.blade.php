@extends('web::layouts.grids.12')

@section('title', 'Buyback Appraisal Result')
@section('page_header', 'Buyback Appraisal Result')

@section('full')
<div class="row">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">
                    <i class="fas fa-calculator"></i> Buyback Appraisal
                </h3>
                <div class="card-tools">
                    <span class="badge badge-info">{{ strtoupper($market) }}</span>
                    <span class="badge badge-success">{{ $corporation->name }}</span>
                </div>
            </div>
            <div class="card-body">
                <div class="row mb-4">
                    <div class="col-md-4">
                        <div class="info-box bg-success">
                            <span class="info-box-icon"><i class="fas fa-hand-holding-usd"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Buyback Value</span>
                                <span class="info-box-number">{{ number_format($total_buyback_value, 2) }} ISK</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="info-box bg-info">
                            <span class="info-box-icon"><i class="fas fa-chart-line"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Market Value</span>
                                <span class="info-box-number">{{ number_format($total_market_value, 2) }} ISK</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="info-box bg-warning">
                            <span class="info-box-icon"><i class="fas fa-percent"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Percentage</span>
                                <span class="info-box-number">{{ number_format($average_percentage, 2) }}%</span>
                            </div>
                        </div>
                    </div>
                </div>

                <h4>Items ({{ count($items) }})</h4>
                <div class="table-responsive">
                    <table class="table table-sm table-striped">
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th class="text-right">Quantity</th>
                                <th class="text-right">Market Price</th>
                                <th class="text-right">%</th>
                                <th class="text-right">Buyback Price</th>
                                <th class="text-right">Total Buyback</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($items as $item)
                            <tr>
                                <td>{{ $item['type_name'] }}</td>
                                <td class="text-right">{{ number_format($item['quantity']) }}</td>
                                <td class="text-right">{{ number_format($item['market_price'], 2) }}</td>
                                <td class="text-right">
                                    <span class="badge badge-{{ $item['percentage'] >= 100 ? 'success' : ($item['percentage'] >= 90 ? 'warning' : 'secondary') }}">
                                        {{ number_format($item['percentage'], 1) }}%
                                    </span>
                                </td>
                                <td class="text-right">{{ number_format($item['buyback_price'], 2) }}</td>
                                <td class="text-right">{{ number_format($item['total_buyback'], 2) }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="font-weight-bold">
                                <td colspan="5">Total</td>
                                <td class="text-right">{{ number_format($total_buyback_value, 2) }} ISK</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-info-circle"></i> Information</h3>
            </div>
            <div class="card-body">
                <dl class="row">
                    <dt class="col-sm-6">Corporation</dt>
                    <dd class="col-sm-6">{{ $corporation->name }}</dd>

                    <dt class="col-sm-6">Market</dt>
                    <dd class="col-sm-6">{{ strtoupper($market) }}</dd>

                    <dt class="col-sm-6">Items</dt>
                    <dd class="col-sm-6">{{ count($items) }}</dd>

                    <dt class="col-sm-6">Market Value</dt>
                    <dd class="col-sm-6">{{ number_format($total_market_value, 2) }} ISK</dd>

                    <dt class="col-sm-6">Buyback Value</dt>
                    <dd class="col-sm-6">{{ number_format($total_buyback_value, 2) }} ISK</dd>

                    <dt class="col-sm-6">Average %</dt>
                    <dd class="col-sm-6">{{ number_format($average_percentage, 2) }}%</dd>
                </dl>

                <hr>

                <div class="callout callout-success">
                    <h5><i class="fas fa-info-circle"></i> Next Steps</h5>
                    <p>To receive <strong>{{ number_format($total_buyback_value, 2) }} ISK</strong>, create an item exchange contract with these items to:</p>
                    <p class="text-center"><strong>{{ $corporation->name }}</strong></p>
                </div>

                <div class="btn-group-vertical btn-block">
                    <a href="{{ route('buyback.appraisal.index') }}" class="btn btn-primary">
                        <i class="fas fa-calculator"></i> New Appraisal
                    </a>
                </div>
            </div>
        </div>

        @if($raw_input)
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-file-alt"></i> Raw Input</h3>
            </div>
            <div class="card-body">
                <pre class="bg-dark text-light p-2" style="max-height: 300px; overflow-y: auto; font-size: 0.85em;">{{ $raw_input }}</pre>
            </div>
        </div>
        @endif
    </div>
</div>
@endsection
