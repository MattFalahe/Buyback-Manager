@extends('web::layouts.grids.12')

@section('title', 'Buyback Manager - Contracts')
@section('page_header', 'Buyback Manager - Contracts')

@push('head')
    <link rel="stylesheet" href="{{ asset('vendor/buyback-manager/css/buyback-manager.css') }}?v=1">
@endpush

@section('content')
    <div class="buyback-manager-wrapper">
        <!-- Filter Box -->
        <div class="card card-dark">
            <div class="card-body">
                <form method="GET" action="{{ route('buyback-manager.contracts.index') }}">
                    <div class="row">
                        <div class="col-md-4">
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
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>&nbsp;</label>
                                <button type="submit" class="btn btn-bb-primary form-control">
                                    <i class="fa fa-filter"></i> Filter
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Contracts Table -->
        <div class="card card-dark">
            <div class="card-header">
                <h3 class="card-title">Buyback Contracts</h3>
                <div class="card-tools">
                    <a href="{{ route('buyback-manager.contracts.export', request()->only('corporation_id')) }}"
                       class="btn btn-sm btn-bb-secondary">
                        <i class="fa fa-download"></i> Export
                    </a>
                </div>
            </div>
            <div class="card-body">
                <table class="table table-bb-styled buyback-datatable">
                    <thead>
                        <tr>
                            <th>Contract ID</th>
                            <th>Offer</th>
                            <th>Corporation</th>
                            <th>Issuer</th>
                            <th>Status</th>
                            <th>Items</th>
                            <th>Total Value</th>
                            <th>Issued Date</th>
                            <th>Completed Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($contracts as $contract)
                            <tr class="contract-row" data-contract-id="{{ $contract->id }}">
                                <td>{{ $contract->contract_id }}</td>
                                <td>
                                    @if($contract->offer_public_id)
                                        <a href="{{ route('buyback-manager.offers.show', $contract->offer_public_id) }}">
                                            <code>{{ $contract->offer_public_id }}</code>
                                        </a>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td>
                                    <img src="https://images.evetech.net/corporations/{{ $contract->corporation_id }}/logo?size=32"
                                         style="width: 24px; height: 24px; vertical-align: middle; margin-right: 5px;">
                                    {{ $contract->corporation->name ?? 'Unknown' }}
                                </td>
                                <td>
                                    <img src="https://images.evetech.net/characters/{{ $contract->issuer_id }}/portrait?size=32" 
                                         style="width: 24px; height: 24px; vertical-align: middle; margin-right: 5px;">
                                    {{ $contract->issuer->name ?? 'Unknown' }}
                                </td>
                                <td>
                                    <span class="contract-status {{ $contract->status }}">
                                        {{ ucfirst(str_replace('_', ' ', $contract->status)) }}
                                    </span>
                                </td>
                                <td class="text-center">{{ number_format($contract->items_count) }}</td>
                                <td class="contract-value">
                                    {{ number_format($contract->total_value, 2) }} ISK
                                    <button class="btn btn-xs btn-bb-secondary copy-contract-value"
                                            data-value="{{ $contract->total_value }}"
                                            data-toggle="tooltip"
                                            title="Copy value">
                                        <i class="fa fa-copy"></i>
                                    </button>
                                </td>
                                <td>{{ $contract->issued_date->format('Y-m-d H:i') }}</td>
                                <td>{{ $contract->completed_date ? $contract->completed_date->format('Y-m-d H:i') : '-' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if($contracts->hasPages())
                <div class="card-footer">
                    {{ $contracts->links() }}
                </div>
            @endif
        </div>
    </div>
@endsection

@push('javascript')
    <script src="{{ asset('vendor/buyback-manager/js/buyback-manager.js') }}"></script>
@endpush
