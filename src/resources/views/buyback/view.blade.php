@extends('web::layouts.grids.12')

@section('title', 'Contract Details')
@section('page_header', 'Contract #' . $contract->contract_id)

@push('head')
    <link rel="stylesheet" href="{{ asset('vendor/buyback-manager/css/buyback-manager.css') }}?v=7">
@endpush

@section('content')
    @php
        $matched = $contract->isMatched();
        $haulFee = (float) $contract->haul_fee;
        $asked = $contract->asked_price !== null ? (float) $contract->asked_price : null;
        $deviation = $contract->deviation_percent !== null ? (float) $contract->deviation_percent : null;
        $priceFlagged = in_array(\BuybackManager\Models\BuybackContract::FLAG_PRICE_MISMATCH, $contract->flags(), true);
        // Flags were set when the contract was first seen. Say a price went
        // unchecked only where no flag from an older rule says otherwise.
        $priceUnchecked = $matched && $asked !== null && ! $priceFlagged
            && ! \BuybackManager\Services\ContractService::checksPrice((float) $contract->total_value, $asked);
        $deviationText = $deviation === null ? null
            : (abs($deviation) < 0.005 ? 'exactly as quoted' : ($deviation > 0 ? '+' : '') . number_format($deviation, 2) . '% on the quote');
        $units = $contract->items->sum('quantity');
        $lineTotal = (float) $contract->items->sum('total_value');
    @endphp
    <div class="buyback-manager-wrapper bb-contract-page">
        @include('buyback-manager::buyback._open_in_game_banner', ['oig' => $oig])

        <div class="card card-dark">
            <div class="card-header">
                <h3 class="card-title">
                    <i class="fas fa-file-contract"></i> Contract {{ $contract->contract_id }}
                    <span class="contract-status {{ $contract->displayStatus() }}">{{ ucfirst(str_replace('_', ' ', $contract->displayStatus())) }}</span>
                </h3>
                <div class="card-tools">
                    <a href="{{ route('buyback-manager.contracts.index') }}" class="btn btn-sm btn-bb-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Contracts
                    </a>
                </div>
            </div>
            <div class="card-body">
                <div class="bb-figures">
                    <div class="bb-figure bb-figure-accent">
                        <div class="bb-figure-label">Quoted</div>
                        @if($matched)
                            <div class="bb-figure-value">{{ number_format((float) $contract->total_value, 2) }} <small>ISK</small></div>
                            <div class="bb-figure-sub">
                                {{ $haulFee > 0 ? 'after a ' . number_format($haulFee, 2) . ' ISK haul fee' : 'as quoted' }}
                                @if(optional($contract->appraisal)->isMemberRate())
                                    <span class="badge badge-primary">Member rates</span>
                                @endif
                            </div>
                        @else
                            <div class="bb-figure-value bb-figure-none">No quote</div>
                            <div class="bb-figure-sub">its key matched no appraisal</div>
                        @endif
                    </div>
                    <div class="bb-figure">
                        <div class="bb-figure-label">Asked</div>
                        <div class="bb-figure-value">{{ $asked !== null ? number_format($asked, 2) : '-' }} <small>ISK</small></div>
                        <div class="bb-figure-sub">
                            @if($deviationText === null)
                                nothing to compare with
                            @elseif($priceFlagged)
                                <span class="text-warning">{{ $deviationText }}</span>
                            @elseif($priceUnchecked)
                                {{ $deviationText }}, not checked under {{ number_format(\BuybackManager\Services\ContractService::PRICE_CHECK_FROM) }} ISK
                            @else
                                <span class="text-success">{{ $deviationText }}</span>
                            @endif
                        </div>
                    </div>
                    <div class="bb-figure">
                        <div class="bb-figure-label">Items</div>
                        <div class="bb-figure-value">{{ number_format($contract->items_count) }}</div>
                        <div class="bb-figure-sub">{{ $units > 0 ? number_format($units) . ($units == 1 ? ' unit' : ' units') : 'item types' }}</div>
                    </div>
                    <div class="bb-figure">
                        <div class="bb-figure-label">Volume</div>
                        <div class="bb-figure-value">{{ $contract->total_volume !== null ? number_format((float) $contract->total_volume, 2) : '-' }} <small>m3</small></div>
                        <div class="bb-figure-sub">what the contract carries</div>
                    </div>
                </div>

                @if($contract->isFlagged())
                    <div class="warning-box">
                        <i class="fas fa-exclamation-triangle"></i>
                        <div>
                            <strong>Needs review</strong>
                            @foreach($contract->flagLabels() as $label)
                                <span class="d-block">{{ $label }}</span>
                            @endforeach
                        </div>
                    </div>
                @endif

                <div class="row">
                    <div class="col-lg-7">
                        <h5 class="bb-facts-heading"><i class="fas fa-handshake"></i> Contract</h5>
                        <dl class="bb-facts">
                            <dt>Contract ID</dt>
                            <dd>
                                {{ $contract->contract_id }}
                                @include('buyback-manager::buyback._open_in_game', ['contract' => $contract, 'oig' => $oig, 'withLabel' => true])
                            </dd>

                            <dt>Programme</dt>
                            <dd>
                                <img src="https://images.evetech.net/corporations/{{ $contract->corporation_id }}/logo?size=32" alt="">
                                {{ $contract->corporation->name ?? 'Unknown' }}
                            </dd>

                            <dt>Seller</dt>
                            <dd>
                                <img src="https://images.evetech.net/characters/{{ $contract->issuer_id }}/portrait?size=32" alt="" class="bb-portrait">
                                {{ $contract->issuer->name ?? 'Unknown' }}
                            </dd>

                            @if($contract->send_to)
                                <dt>Contract to</dt>
                                <dd>{{ $contract->send_to }}</dd>
                            @endif

                            <dt>Appraisal key</dt>
                            <dd>
                                @if($contract->appraisal_public_id)
                                    <code class="bb-key">{{ $contract->appraisal_public_id }}</code>
                                    @if($quote)
                                        <button type="button" class="btn btn-xs btn-bb-primary bb-appraisal-details-open">
                                            <i class="fas fa-search"></i> Details
                                        </button>
                                    @endif
                                @elseif($contract->quoted_key)
                                    <code class="bb-key">{{ $contract->quoted_key }}</code>
                                    <span class="text-warning">matches no appraisal of this corporation</span>
                                @else
                                    <span class="text-muted">-</span>
                                @endif
                            </dd>
                        </dl>
                    </div>
                    <div class="col-lg-5">
                        <h5 class="bb-facts-heading"><i class="far fa-clock"></i> Dates</h5>
                        <dl class="bb-facts">
                            <dt>Issued</dt>
                            <dd>{{ $contract->issued_date->format('Y-m-d H:i:s') }}</dd>

                            <dt>Expires</dt>
                            <dd>{{ $contract->expires_at ? $contract->expires_at->format('Y-m-d H:i:s') : '-' }}</dd>

                            <dt>Completed</dt>
                            <dd>{{ $contract->completed_date ? $contract->completed_date->format('Y-m-d H:i:s') : 'Pending' }}</dd>
                        </dl>
                    </div>
                </div>
            </div>
        </div>

        <div class="card card-dark">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-boxes"></i> Contract items</h3>
            </div>
            <div class="card-body p-0">
                @if($contract->items->isEmpty())
                    <div class="empty-state">
                        <i class="fas fa-box-open"></i>
                        <div class="empty-state-description">
                            {{ $matched ? 'No lines were kept for this contract.' : 'No priced lines: the key on this contract matched no appraisal.' }}
                        </div>
                    </div>
                @else
                    <div class="table-responsive-bb-wrap">
                        <table class="table table-bb-styled bb-contract-items">
                            <thead>
                                <tr>
                                    <th>Item</th>
                                    <th class="text-right">Quantity</th>
                                    <th class="text-right">Unit price</th>
                                    <th class="text-right">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($contract->items as $item)
                                    <tr>
                                        <td>
                                            <img src="https://images.evetech.net/types/{{ $item->type_id }}/icon?size=32" class="bb-type-icon" alt="">
                                            {{ $item->type->typeName ?? ('Type #' . $item->type_id) }}
                                        </td>
                                        <td class="text-right">{{ number_format($item->quantity) }}</td>
                                        <td class="text-right">{{ number_format((float) $item->unit_price, 2) }} ISK</td>
                                        <td class="text-right bb-isk">{{ number_format((float) $item->total_value, 2) }} ISK</td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot>
                                @if($haulFee > 0)
                                    <tr class="bb-minor">
                                        <td colspan="3">Quote</td>
                                        <td class="text-right">{{ number_format((float) $contract->total_value + $haulFee, 2) }} ISK</td>
                                    </tr>
                                    <tr class="bb-minor">
                                        <td colspan="3">Haul fee where the contract was made</td>
                                        <td class="text-right">-{{ number_format($haulFee, 2) }} ISK</td>
                                    </tr>
                                @endif
                                <tr>
                                    <td colspan="3">Total</td>
                                    <td class="text-right bb-isk">{{ number_format($matched ? (float) $contract->total_value : $lineTotal, 2) }} ISK</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    </div>

    @if($quote)
        @include('buyback-manager::buyback._appraisal_details', ['contract' => $contract, 'quote' => $quote])
    @endif
@endsection

@push('javascript')
    <script src="{{ asset('vendor/buyback-manager/js/buyback-manager.js') }}?v=2"></script>
    <script>
        $(document).on('click', '.bb-appraisal-details-open', function (e) {
            e.preventDefault();
            // AdminLTE's layout stacks a modal under its backdrop unless the modal hangs off <body>.
            $('#bb-appraisal-details').appendTo('body').modal('show');
        });
    </script>
@endpush
