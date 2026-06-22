@extends('web::layouts.grids.12')

@section('title', 'Buyback Appraisal Result')
@section('page_header', 'Buyback Appraisal Result')

@push('head')
    <link rel="stylesheet" href="{{ asset('vendor/buyback-manager/css/buyback-manager.css') }}?v=1">
@endpush

@section('full')
<div class="buyback-manager-wrapper">
<div class="row">
    <div class="col-md-8">
        <div class="card card-dark">
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
                @if(! empty($truncated))
                    <div class="alert alert-warning">
                        <i class="fa fa-exclamation-triangle"></i>
                        <strong>Heads up.</strong> Your paste exceeded the parser's 1000-item cap.
                        Items beyond that were dropped. Total below reflects only the first 1000
                        resolved items. Split the paste into smaller batches if you need to appraise the rest.
                    </div>
                @endif
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
                    <table class="table table-bb-styled table-compact">
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
        <div class="card card-dark">
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
                    <h5><i class="fas fa-lock"></i> Lock this quote</h5>
                    <p>
                        Publish this as an <strong>offer</strong> to lock the
                        <strong>{{ number_format($total_buyback_value, 2) }} ISK</strong>
                        payout. You'll get a shareable URL and step-by-step
                        instructions for creating the in-game contract.
                    </p>
                    <p style="font-size:0.85rem; color:#6c757d; margin-bottom:0;">
                        The locked value is honoured even if market prices move before the contract is created.
                    </p>
                </div>

                <form method="POST" action="{{ route('buyback-manager.offers.publish') }}">
                    @csrf
                    <input type="hidden" name="corporation_id" value="{{ $corporation->corporation_id }}">
                    {{-- Multi-line paste data inside a hidden textarea (NOT
                         a hidden <input value="...">) — attribute-value
                         whitespace normalization in some browsers strips
                         the newlines from a hidden input, breaking the
                         standalone parser's line splitting on re-appraisal. --}}
                    <textarea name="items" hidden>{{ $raw_input }}</textarea>
                    <div class="btn-group-vertical btn-block">
                        <button type="submit" class="btn btn-bb-primary btn-block">
                            <i class="fas fa-tag"></i> Publish as Offer
                        </button>
                        <a href="{{ route('buyback.appraisal.index') }}" class="btn btn-bb-secondary btn-block" style="margin-top:0.4rem;">
                            <i class="fas fa-calculator"></i> New Appraisal
                        </a>
                    </div>
                </form>
            </div>
        </div>

        @if($raw_input)
        <div class="card card-dark">
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
</div>
@endsection
