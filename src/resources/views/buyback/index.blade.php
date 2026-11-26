@extends('web::layouts.grids.12')

@section('title', 'Buyback Manager - Contracts')
@section('page_header', 'Buyback Manager - Contracts')

@push('head')
    <link rel="stylesheet" href="{{ asset('vendor/buyback-manager/css/buyback-manager.css') }}?v=6">
@endpush

@section('content')
    <div class="buyback-manager-wrapper">
        @include('buyback-manager::buyback._open_in_game_banner', ['oig' => $oig])

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
                                <label for="review-filter">Show</label>
                                <select name="review" id="review-filter" class="form-control">
                                    @foreach(['' => 'All contracts', 'active' => 'Awaiting acceptance', 'flagged' => 'Needs review', 'unknown_key' => 'Key matched nothing', 'expired' => 'Expired'] as $value => $label)
                                        <option value="{{ $value }}" {{ (string) request('review') === (string) $value ? 'selected' : '' }}>{{ $label }}</option>
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
                @if($syncStatuses->isNotEmpty())
                    <div class="small text-muted mt-2">
                        <i class="fa fa-sync-alt"></i> Last checked:
                        @foreach($syncStatuses as $sync)
                            <span class="ml-2">
                                {{ $sync['name'] }}:
                                @if($sync['status'] === null)
                                    <span class="text-muted">not yet</span>
                                @elseif($sync['status']->isStale())
                                    <span class="text-danger" title="{{ $sync['status']->last_error }}">
                                        <i class="fa fa-exclamation-triangle"></i>
                                        last good read {{ $sync['status']->last_ok_at ? $sync['status']->last_ok_at->diffForHumans() : 'never' }}
                                    </span>
                                @elseif($sync['status']->last_error)
                                    <span class="text-warning" title="{{ $sync['status']->last_error }}">
                                        {{ $sync['status']->last_ok_at->diffForHumans() }}, with an error
                                    </span>
                                @else
                                    {{ $sync['status']->last_ok_at->diffForHumans() }}
                                @endif
                                @if($sync['manual'])
                                    <span class="text-muted">(contracts to this target are confirmed by hand)</span>
                                @endif
                            </span>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        <!-- Contracts Table -->
        <div class="card card-dark">
            <div class="card-header">
                <h3 class="card-title">Buyback Contracts</h3>
                <div class="card-tools">
                    <a href="{{ route('buyback-manager.contracts.export', request()->only('corporation_id', 'review')) }}"
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
                            <th>Appraisal</th>
                            <th>Corporation</th>
                            <th>Issuer</th>
                            <th>Status</th>
                            <th>Review</th>
                            <th>Items</th>
                            <th>Volume</th>
                            <th>Quoted</th>
                            <th>Issued Date</th>
                            <th>Completed Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($contracts as $contract)
                            <tr class="contract-row" data-contract-id="{{ $contract->id }}">
                                <td data-order="{{ $contract->contract_id }}" data-search="{{ $contract->contract_id }}">
                                    {{ $contract->contract_id }}
                                    @include('buyback-manager::buyback._open_in_game', ['contract' => $contract, 'oig' => $oig])
                                </td>
                                <td>
                                    @if($contract->appraisal_public_id)
                                        <code>{{ $contract->appraisal_public_id }}</code>
                                    @elseif($contract->quoted_key)
                                        <code>{{ $contract->quoted_key }}</code>
                                        <small class="text-warning d-block">matched nothing</small>
                                    @else
                                        <span class="text-muted">-</span>
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
                                    <span class="contract-status {{ $contract->displayStatus() }}">
                                        {{ ucfirst(str_replace('_', ' ', $contract->displayStatus())) }}
                                    </span>
                                </td>
                                <td>
                                    @if($contract->isFlagged())
                                        <span class="badge badge-danger" title="{{ implode(' | ', $contract->flagLabels()) }}">
                                            <i class="fa fa-exclamation-triangle"></i> {{ count($contract->flags()) }}
                                        </span>
                                        @if($contract->deviation_percent !== null && abs((float) $contract->deviation_percent) > 0.001)
                                            <small class="text-muted d-block">
                                                asked {{ (float) $contract->deviation_percent > 0 ? '+' : '' }}{{ number_format((float) $contract->deviation_percent, 2) }}%
                                            </small>
                                        @endif
                                    @else
                                        <span class="badge badge-success"><i class="fa fa-check"></i> OK</span>
                                    @endif
                                </td>
                                <td class="text-center">{{ number_format($contract->items_count) }}</td>
                                <td class="text-right">{{ $contract->total_volume !== null ? number_format((float) $contract->total_volume, 2) . ' m3' : '-' }}</td>
                                <td class="contract-value">
                                    @if($contract->isMatched())
                                        {{ number_format($contract->total_value, 2) }} ISK
                                        <button class="btn btn-xs btn-bb-secondary copy-contract-value"
                                                data-value="{{ $contract->total_value }}"
                                                data-toggle="tooltip"
                                                title="Copy value">
                                            <i class="fa fa-copy"></i>
                                        </button>
                                    @else
                                        <span class="text-muted">No quote</span>
                                        <small class="text-muted d-block">asked {{ number_format((float) $contract->asked_price, 2) }} ISK</small>
                                    @endif
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
    <script src="{{ asset('vendor/buyback-manager/js/buyback-manager.js') }}?v=2"></script>
@endpush
