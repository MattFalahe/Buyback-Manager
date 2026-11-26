@extends('web::layouts.grids.12')

@section('title', 'Contract Details')
@section('page_header', 'Contract #' . $contract->contract_id)

@push('head')
    <link rel="stylesheet" href="{{ asset('vendor/buyback-manager/css/buyback-manager.css') }}?v=6">
@endpush

@section('content')
    <div class="buyback-manager-wrapper">
        @include('buyback-manager::buyback._open_in_game_banner', ['oig' => $oig])

        <!-- Contract Summary -->
        <div class="row">
            <div class="col-md-8">
                <div class="card card-dark">
                    <div class="card-header">
                        <h3 class="card-title">Contract Information</h3>
                    </div>
                    <div class="card-body">
                        <dl class="dl-horizontal">
                            <dt>Contract ID:</dt>
                            <dd>
                                {{ $contract->contract_id }}
                                @include('buyback-manager::buyback._open_in_game', ['contract' => $contract, 'oig' => $oig, 'withLabel' => true])
                            </dd>

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
                                <span class="contract-status {{ $contract->displayStatus() }}">
                                    {{ ucfirst(str_replace('_', ' ', $contract->displayStatus())) }}
                                </span>
                            </dd>

                            <dt>Appraisal key:</dt>
                            <dd>
                                @if($contract->appraisal_public_id)
                                    <code>{{ $contract->appraisal_public_id }}</code>
                                @elseif($contract->quoted_key)
                                    <code>{{ $contract->quoted_key }}</code>
                                    <span class="text-warning">matches no appraisal of this corporation</span>
                                @else
                                    <span class="text-muted">-</span>
                                @endif
                            </dd>

                            <dt>Total Items:</dt>
                            <dd>{{ number_format($contract->items_count) }}</dd>

                            <dt>Volume:</dt>
                            <dd>{{ $contract->total_volume !== null ? number_format((float) $contract->total_volume, 2) . ' m3' : '-' }}</dd>

                            <dt>Total Value:</dt>
                            @if($contract->isMatched())
                                <dd class="contract-value">
                                    {{ number_format($contract->total_value, 2) }} ISK
                                    @if(optional($contract->appraisal)->isMemberRate())
                                        <span class="badge badge-primary">Member rates</span>
                                    @endif
                                </dd>
                            @else
                                <dd><span class="text-muted">No quote</span></dd>
                            @endif

                            @if((float) $contract->haul_fee > 0)
                                <dt>Haul Fee:</dt>
                                <dd>{{ number_format((float) $contract->haul_fee, 2) }} ISK, already off the total value</dd>
                            @endif

                            <dt>Asked Price:</dt>
                            <dd>{{ $contract->asked_price !== null ? number_format((float) $contract->asked_price, 2) . ' ISK' : '-' }}</dd>

                            @if($contract->isFlagged())
                                <dt>Needs review:</dt>
                                <dd>
                                    @foreach($contract->flagLabels() as $label)
                                        <span class="d-block text-warning"><i class="fa fa-exclamation-triangle"></i> {{ $label }}</span>
                                    @endforeach
                                </dd>
                            @endif

                            <dt>Issued Date:</dt>
                            <dd>{{ $contract->issued_date->format('Y-m-d H:i:s') }}</dd>

                            <dt>Expires:</dt>
                            <dd>{{ $contract->expires_at ? $contract->expires_at->format('Y-m-d H:i:s') : '-' }}</dd>

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
        <div class="card card-dark">
            <div class="card-header">
                <h3 class="card-title">Contract Items</h3>
            </div>
            <div class="card-body">
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
        <div class="card card-dark">
            <div class="card-body">
                <a href="{{ route('buyback-manager.contracts.index') }}" class="btn btn-bb-secondary">
                    <i class="fa fa-arrow-left"></i> Back to Contracts
                </a>
            </div>
        </div>
    </div>
@endsection

@push('javascript')
    <script src="{{ asset('vendor/buyback-manager/js/buyback-manager.js') }}?v=2"></script>
@endpush
