@extends('web::layouts.grids.12')

@section('title', 'Buyback Appraisal Result')
@section('page_header', 'Buyback Appraisal Result')

@push('head')
    <link rel="stylesheet" href="{{ asset('vendor/buyback-manager/css/buyback-manager.css') }}?v=6">
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
                    <span class="badge badge-info">{{ $market }}</span>
                    <span class="badge badge-success">{{ $corporation->name }}</span>
                    @if(($rate_tier ?? null) === \BuybackManager\Models\BuybackAppraisal::RATE_MEMBER)
                        <span class="badge badge-primary" title="{{ rtrim(rtrim(number_format((float) ($member_bonus ?? 0), 2, '.', ''), '0'), '.') }} points on top of every rule">Member rates</span>
                    @endif
                </div>
            </div>
            <div class="card-body">
                @php
                    $excluded = $excluded ?? [];
                    $unrecognised = $unrecognised ?? [];
                    $unpricedCount = count(array_filter($excluded, fn ($ex) => ($ex['reason'] ?? null) === 'unpriced'));
                    $haulFees = $haul_fees ?? [];
                    $thinCount = count(array_filter($items, fn ($item) => ! empty($item['thin_market'])));
                    $assembledItems = $volume_assembled ?? [];
                @endphp
                @if(! empty($truncated))
                    <div class="alert alert-warning">
                        <i class="fa fa-exclamation-triangle"></i>
                        <strong>Heads up.</strong> Your paste held more than one quote can take
                        (1,000 item types, or 5,000 lines), so the rest was left out of the total below.
                        Split the paste into smaller batches to appraise the rest.
                    </div>
                @endif
                @if(! empty($excluded))
                    <div class="alert alert-warning">
                        <i class="fa fa-exclamation-triangle"></i>
                        <strong>{{ count($excluded) }} {{ \Illuminate\Support\Str::plural('item', count($excluded)) }} not accepted.</strong>
                        They are <strong>not valued or included</strong> in the total below, so leave them
                        out of the contract. Ask a buyback director for a custom quote on items we do not buy.
                        @if($unpricedCount > 0)
                            Items with no market price had none anywhere we look right now; appraise them again later.
                        @endif
                        <ul style="margin:0.5rem 0 0; padding-left:1.2rem;">
                            @foreach($excluded as $ex)
                                <li>
                                    {{ $ex['type_name'] }} &times; {{ number_format($ex['quantity']) }}
                                    @if(($ex['reason'] ?? null) === 'unpriced')
                                        <span class="badge badge-secondary">no market price</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
                @if(! empty($unrecognised))
                    <div class="alert alert-warning">
                        <i class="fa fa-question-circle"></i>
                        <strong>{{ count($unrecognised) }} {{ \Illuminate\Support\Str::plural('line', count($unrecognised)) }} not recognised.</strong>
                        These did not name an item, so nothing on them is in the total below. Check the
                        spelling, or copy the items from the game.
                        <ul style="margin:0.5rem 0 0; padding-left:1.2rem;">
                            @foreach($unrecognised as $line)
                                <li><code>{{ $line }}</code></li>
                            @endforeach
                        </ul>
                    </div>
                @endif
                @if(! empty($pricing_note))
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> {{ $pricing_note }}
                    </div>
                @endif
                @if($thinCount > 0)
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i>
                        {{ $thinCount }} {{ \Illuminate\Support\Str::plural('item', $thinCount) }} marked <strong>thin market</strong>
                        {{ $thinCount === 1 ? 'was' : 'were' }} priced at the average of the top 5% of buy orders, because the
                        top buy order stood more than {{ rtrim(rtrim(number_format((float) ($setting->thin_market_guard_percent ?? 10), 2), '0'), '.') }}% above it.
                    </div>
                @endif
                @if(! empty($haulFees) && ! empty($assembledItems))
                    <div class="alert alert-warning">
                        <i class="fa fa-exclamation-triangle"></i>
                        Counted at their assembled volume, since their packaged volume is not known yet:
                        {{ implode(', ', $assembledItems) }}. The haul fees below are too high for them. Appraise again
                        in a few minutes for the packaged figure.
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

                @if(! empty($haulFees))
                    <h4>Contract price by location</h4>
                    <p class="text-muted">
                        A haul fee comes off at these locations, on the {{ number_format((float) ($total_volume ?? 0), 2) }} m3 this list takes up.
                        Anywhere else we accept, set the full {{ number_format($total_buyback_value, 2) }} ISK.
                    </p>
                    <div class="table-responsive">
                        <table class="table table-bb-styled table-compact">
                            <thead>
                                <tr>
                                    <th>Location</th>
                                    <th class="text-right">Haul fee</th>
                                    <th class="text-right">Contract price</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($haulFees as $fee)
                                <tr>
                                    <td>{{ $fee['location'] }}</td>
                                    <td class="text-right">{{ number_format($fee['fee'], 2) }} ISK <span class="text-muted">({{ \BuybackManager\Models\BuybackSetting::iskPerM3($fee['per_m3']) }})</span></td>
                                    <td class="text-right"><strong>{{ number_format($fee['price'], 2) }} ISK</strong></td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                <h4>Items ({{ count($items) }})</h4>
                <div class="table-responsive">
                    <table class="table table-bb-styled table-compact">
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th class="text-right">Quantity</th>
                                <th class="text-right">Volume</th>
                                <th class="text-right">Market Price</th>
                                <th class="text-right">%</th>
                                <th class="text-right">Buyback Price</th>
                                <th class="text-right">Total Buyback</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($items as $item)
                            <tr>
                                <td>
                                    {{ $item['type_name'] }}
                                    @if(! empty($item['rule_name']))
                                        <span class="badge badge-secondary" title="The rule this line is bought under">{{ $item['rule_name'] }}</span>
                                    @endif
                                    @if(($item['refine_yield'] ?? null) !== null)
                                        <span class="badge badge-success" title="Valued at the minerals it refines into">refined at {{ rtrim(rtrim(number_format((float) $item['refine_yield'], 2), '0'), '.') }}%</span>
                                    @endif
                                    @if(! empty($item['thin_market']))
                                        <span class="badge badge-warning" title="Priced at the average of the top 5% of buy orders">thin market</span>
                                    @endif
                                </td>
                                <td class="text-right">{{ number_format($item['quantity']) }}</td>
                                <td class="text-right">{{ number_format((float) ($item['unit_volume'] ?? 0) * (int) $item['quantity'], 2) }} m3</td>
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
                                <td colspan="2">Total</td>
                                <td class="text-right">{{ number_format((float) ($total_volume ?? 0), 2) }} m3</td>
                                <td colspan="3"></td>
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
                    <dd class="col-sm-6">{{ $market }}</dd>

                    <dt class="col-sm-6">Items</dt>
                    <dd class="col-sm-6">{{ count($items) }}</dd>

                    <dt class="col-sm-6">Volume</dt>
                    <dd class="col-sm-6">{{ number_format((float) ($total_volume ?? 0), 2) }} m3</dd>

                    <dt class="col-sm-6">Market Value</dt>
                    <dd class="col-sm-6">{{ number_format($total_market_value, 2) }} ISK</dd>

                    <dt class="col-sm-6">Buyback Value</dt>
                    <dd class="col-sm-6">{{ number_format($total_buyback_value, 2) }} ISK</dd>

                    <dt class="col-sm-6">Average %</dt>
                    <dd class="col-sm-6">{{ number_format($average_percentage, 2) }}%</dd>

                    <dt class="col-sm-6">Rates</dt>
                    <dd class="col-sm-6">{{ ($rate_tier ?? null) === \BuybackManager\Models\BuybackAppraisal::RATE_MEMBER ? 'Member' : 'Public' }}</dd>
                </dl>

                <hr>

                @if(! empty($appraisal))
                    <div class="callout callout-success">
                        <h5><i class="fas fa-key"></i> Your appraisal key</h5>
                        <p>
                            Put this key in the <strong>Description</strong> of your in-game
                            contract so we can match it to this quote of
                            <strong>{{ number_format($total_buyback_value, 2) }} ISK</strong>.
                        </p>
                        <div style="display:flex; gap:0.4rem; align-items:stretch;">
                            <input type="text" class="form-control" readonly id="bb-appraisal-key"
                                   value="{{ $appraisal->public_id }}"
                                   style="font-family:monospace; font-weight:700; font-size:1.05rem;"
                                   onclick="this.select();">
                            <button type="button" class="btn btn-bb-secondary" onclick="bbCopyKey()">
                                <i class="fas fa-copy"></i> Copy
                            </button>
                        </div>
                        <p style="font-size:0.85rem; color:#6c757d; margin:0.6rem 0 0;">
                            The key is single use. Set the contract price to the quoted value{{ empty($haulFees) ? '' : ' for the location you contract from' }}. A
                            different amount still reaches us but is flagged for a director to check.
                        </p>
                    </div>

                    <div class="btn-group-vertical btn-block">
                        {{-- With the programme or its public page off, the link shows only the closed notice. --}}
                        @if(! empty($setting) && $setting->publicPageOpen())
                            <a href="{{ route('buyback-manager.public.appraisal', ['ticker' => $setting->public_slug, 'key' => $appraisal->public_id]) }}"
                               class="btn btn-bb-primary btn-block" target="_blank" rel="noopener">
                                <i class="fas fa-external-link-alt"></i> Open shareable appraisal
                            </a>
                        @endif
                        <a href="{{ route('buyback.appraisal.index') }}" class="btn btn-bb-secondary btn-block" style="margin-top:0.4rem;">
                            <i class="fas fa-calculator"></i> New Appraisal
                        </a>
                    </div>
                @else
                    <div class="callout callout-warning">
                        <h5><i class="fas fa-exclamation-triangle"></i> No key generated</h5>
                        <p style="margin-bottom:0;">
                            This quote could not be saved, so it has no contract key. Run the
                            appraisal again, and ask an admin to check the SeAT log if it keeps happening.
                        </p>
                    </div>
                    <a href="{{ route('buyback.appraisal.index') }}" class="btn btn-bb-secondary btn-block">
                        <i class="fas fa-calculator"></i> New Appraisal
                    </a>
                @endif
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

@push('javascript')
<script>
    function bbCopyKey() {
        var field = document.getElementById('bb-appraisal-key');
        if (!field) return;
        field.select();
        try { document.execCommand('copy'); } catch (e) {}
        if (navigator.clipboard) { navigator.clipboard.writeText(field.value).catch(function () {}); }
    }
</script>
@endpush
