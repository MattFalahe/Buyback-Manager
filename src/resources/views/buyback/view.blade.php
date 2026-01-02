@extends('web::layouts.grids.12')

@section('title', 'Contract Details')
@section('page_header', 'Contract #' . $contract->contract_id)

@push('head')
    <link rel="stylesheet" href="{{ asset('web/css/buyback-manager/buyback-manager.css') }}">
@endpush

@section('content')
    <div class="buyback-manager-wrapper">
        <!-- Contract Summary -->
        <div class="row">
            <div class="col-md-8">
                <div class="box box-primary">
                    <div class="box-header with-border">
                        <h3 class="box-title">Contract Information</h3>
                    </div>
                    <div class="box-body">
                        <dl class="dl-horizontal">
                            <dt>Contract ID:</dt>
                            <dd>{{ $contract->contract_id }}</dd>

                            <dt>Corporation:</dt>
                            <dd>
                                <img src="https://images.evetech.net/corporations/{{ $contract->corporation_id }}/logo?size=32" 
                                     style="width: 24px; height: 24px; vertical-align: middle; margin-right: 5px;">
                                {{ $contract->corporation->name ?? 'Unknown' }}
                            </dd>

                            <dt>Issuer:</dt>
                            <dd>
                                <img src="https://images.evetech.net/characters/{{ $contract->issuer_id }}/portrait?size=32" 
                                     style="width: 24px; height: 24px; vertical-align: middle; margin-right: 5px;">
                                {{ $contract->issuer->name ?? 'Unknown' }}
                            </dd>

                            <dt>Status:</dt>
                            <dd>
                                <span class="contract-status {{ $contract->status }}">
                                    {{ ucfirst(str_replace('_', ' ', $contract->status)) }}
                                </span>
                            </dd>

                            <dt>Total Items:</dt>
                            <dd>{{ number_format($contract->items_count) }}</dd>

                            <dt>Total Value:</dt>
                            <dd class="contract-value">{{ number_format($contract->total_value, 2) }} ISK</dd>

                            <dt>Issued Date:</dt>
                            <dd>{{ $contract->issued_date->format('Y-m-d H:i:s') }}</dd>

                            <dt>Completed Date:</dt>
                            <dd>{{ $contract->completed_date ? $contract->completed_date->format('Y-m-d H:i:s') : 'Pending' }}</dd>
                        </dl>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="stats-box">
                    <i class="fa fa-boxes stats-icon"></i>
                    <div class="stats-number">{{ number_format($contract->items_count) }}</div>
                    <div class="stats-label">Total Items</div>
                </div>
                <div class="stats-box">
                    <i class="fa fa-dollar-sign stats-icon"></i>
                    <div class="stats-number">{{ number_format($contract->total_value / 1000000, 2) }}M</div>
                    <div class="stats-label">Total Value (ISK)</div>
                </div>
            </div>
        </div>

        <!-- Contract Items -->
        <div class="box box-success">
            <div class="box-header with-border">
                <h3 class="box-title">Contract Items</h3>
            </div>
            <div class="box-body">
                <div class="contract-items-list">
                    @foreach($contract->items as $item)
                        <div class="contract-item">
                            <div class="item-details">
                                <img src="https://images.evetech.net/types/{{ $item->type_id }}/icon?size=32" 
                                     class="item-icon" 
                                     alt="{{ $item->type->typeName ?? 'Unknown' }}">
                                <span class="item-name">{{ $item->type->typeName ?? 'Unknown' }}</span>
                                <span class="item-quantity">x {{ number_format($item->quantity) }}</span>
                            </div>
                            <div class="item-value">
                                <div>{{ number_format($item->unit_price, 2) }} ISK each</div>
                                <div style="color: #00a65a; font-size: 16px;">{{ number_format($item->total_value, 2) }} ISK</div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <!-- Actions -->
        <div class="box box-default">
            <div class="box-body">
                <a href="{{ route('buyback-manager.contracts.index') }}" class="btn btn-default">
                    <i class="fa fa-arrow-left"></i> Back to Contracts
                </a>
            </div>
        </div>
    </div>
@endsection

@push('javascript')
    <script src="{{ asset('web/js/buyback-manager/buyback-manager.js') }}"></script>
@endpush
