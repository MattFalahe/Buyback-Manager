@extends('web::layouts.grids.12')

@section('title', 'Buyback Offer ' . $offer->public_id)
@section('page_header', 'Buyback Offer ' . $offer->public_id)

@push('head')
    <link rel="stylesheet" href="{{ asset('vendor/buyback-manager/css/buyback-manager.css') }}?v=3">
@endpush

@section('full')
<div class="buyback-manager-wrapper">

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    <div class="row">

        {{-- Main offer card --}}
        <div class="col-md-8">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fa fa-tag"></i> Offer <code>{{ $offer->public_id }}</code>
                    </h3>
                    <div class="card-tools">
                        @php
                            $statusClass = match($offer->status) {
                                'pending' => 'warning',
                                'matched' => 'info',
                                'expired' => 'default',
                                'rejected' => 'danger',
                                'cancelled' => 'default',
                                default => 'default',
                            };
                        @endphp
                        <span class="label label-{{ $statusClass }}">{{ strtoupper($offer->status) }}</span>
                        <span class="label label-default">{{ strtoupper($offer->mode) }}</span>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <div class="info-box bg-success">
                                <span class="info-box-icon"><i class="fa fa-hand-holding-usd"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Buyback value (locked)</span>
                                    <span class="info-box-number">{{ number_format((float) $offer->total_buyback_value, 2) }} ISK</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="info-box bg-info">
                                <span class="info-box-icon"><i class="fa fa-chart-line"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Market value</span>
                                    <span class="info-box-number">{{ number_format((float) $offer->total_market_value, 2) }} ISK</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="info-box bg-warning">
                                <span class="info-box-icon"><i class="fa fa-percent"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Average %</span>
                                    <span class="info-box-number">{{ number_format((float) $offer->average_percentage, 2) }}%</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <h4>Items ({{ $offer->items->count() }})</h4>
                    <div class="table-responsive">
                        <table class="table table-bb-styled table-compact">
                            <thead>
                                <tr>
                                    <th>Item</th>
                                    <th class="text-right">Quantity</th>
                                    <th class="text-right">Market price</th>
                                    <th class="text-right">%</th>
                                    <th class="text-right">Buyback price</th>
                                    <th class="text-right">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($offer->items as $item)
                                    <tr>
                                        <td>{{ $item->type_name }}</td>
                                        <td class="text-right">{{ number_format($item->quantity) }}</td>
                                        <td class="text-right">{{ number_format((float) $item->market_price, 2) }}</td>
                                        <td class="text-right">{{ number_format((float) $item->percentage, 1) }}%</td>
                                        <td class="text-right">{{ number_format((float) $item->buyback_price, 2) }}</td>
                                        <td class="text-right">{{ number_format((float) $item->total_buyback, 2) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            {{-- Rejection reason form (matched offers only, designated character / admin) --}}
            @if($offer->status === 'matched')
                <div class="card card-dark">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fa fa-times-circle"></i> Reject this offer</h3>
                    </div>
                    <div class="card-body">
                        <p class="text-muted" style="font-size:0.9rem;">
                            Reject the linked contract <strong>in-game first</strong>, then record the reason here for the audit trail.
                        </p>
                        <form method="POST" action="{{ route('buyback-manager.offers.reject', $offer->public_id) }}">
                            @csrf
                            <div class="form-group">
                                <textarea name="reason" class="form-control" rows="3" maxlength="1000"
                                          placeholder="e.g. items don't match the offer, contract sent to wrong assignee, item condition issue..."></textarea>
                            </div>
                            <button type="submit" class="btn btn-danger">
                                <i class="fa fa-times"></i> Record rejection
                            </button>
                        </form>
                    </div>
                </div>
            @endif

            {{-- Rejection reason display (already rejected) --}}
            @if($offer->status === 'rejected' && $offer->rejected_reason)
                <div class="card card-dark">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fa fa-times-circle"></i> Rejection reason</h3>
                    </div>
                    <div class="card-body">
                        <blockquote style="font-size:0.95rem; color:#c2c7d0; border-left:3px solid #ef4444; padding-left:1rem;">
                            {{ $offer->rejected_reason }}
                        </blockquote>
                        @if($offer->rejectedBy)
                            <small class="text-muted">— {{ $offer->rejectedBy->name }}</small>
                        @endif
                    </div>
                </div>
            @endif
        </div>

        {{-- Sidebar --}}
        <div class="col-md-4">

            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title"><i class="fa fa-info-circle"></i> Offer details</h3>
                </div>
                <div class="card-body">
                    <dl class="row" style="margin-bottom:0;">
                        <dt class="col-sm-5">Corporation</dt>
                        <dd class="col-sm-7">{{ $offer->corporation->name ?? 'Unknown' }}</dd>

                        <dt class="col-sm-5">Issuer</dt>
                        <dd class="col-sm-7">{{ $offer->issuer->name ?? '#' . $offer->issuer_character_id }}</dd>

                        <dt class="col-sm-5">Send to</dt>
                        <dd class="col-sm-7">
                            {{ $offer->sendToLabel() }}
                            @php
                                $targetTypeLabel = match($offer->target_type ?? 'my_corp') {
                                    'player' => 'character',
                                    'corp' => 'corporation',
                                    default => 'your corporation',
                                };
                            @endphp
                            <br><small class="text-muted">({{ $targetTypeLabel }})</small>
                        </dd>

                        <dt class="col-sm-5">Market</dt>
                        <dd class="col-sm-7">{{ strtoupper($offer->market) }}</dd>

                        <dt class="col-sm-5">Provider</dt>
                        <dd class="col-sm-7">{{ ucfirst($offer->provider) }}</dd>

                        <dt class="col-sm-5">Created</dt>
                        <dd class="col-sm-7">{{ $offer->created_at->toDateTimeString() }}</dd>

                        <dt class="col-sm-5">Lock expires</dt>
                        <dd class="col-sm-7">
                            {{ optional($offer->expires_at)->toDateTimeString() ?: '—' }}
                            @if($offer->status === 'pending' && $offer->expires_at)
                                <br><small class="text-muted">({{ $offer->expires_at->diffForHumans() }})</small>
                            @endif
                        </dd>

                        @if($offer->linked_contract_id)
                            <dt class="col-sm-5">Contract</dt>
                            <dd class="col-sm-7">
                                <a href="{{ route('buyback-manager.contracts.show', $offer->linked_contract_id) }}">
                                    View contract
                                </a>
                            </dd>
                        @endif
                    </dl>
                </div>
            </div>

            {{-- Next-steps card (only relevant while pending) --}}
            @if($offer->status === 'pending')
                <div class="card card-dark">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fa fa-list-ol"></i> Next steps</h3>
                    </div>
                    <div class="card-body">
                        <ol style="padding-left:1.2rem; line-height:1.7;">
                            <li>Open EVE Online and switch to the issuer character.</li>
                            <li>Right-click your items, choose <strong>Create Contract</strong>.</li>
                            <li>Pick <strong>Item Exchange</strong>.</li>
                            <li>
                                Set <strong>Send to</strong>: <code>{{ $offer->sendToLabel() }}</code>
                            </li>
                            @if($setting && $setting->hasLocationRestriction())
                            <li>
                                <strong>Location matters:</strong> create the contract at one of our buyback locations &mdash;
                                {{ implode('; ', $setting->allowedLocationLabels()) }}.
                                <small class="text-muted d-block">Contracts created anywhere else are automatically rejected.</small>
                            </li>
                            @endif
                            <li>
                                <strong>Put this offer ID in the contract Description / Info field:</strong>
                                <div style="display:flex; gap:0.4rem; margin-top:0.3rem; align-items:stretch;">
                                    <input type="text" class="form-control" readonly id="bb-offer-id-copy"
                                           value="{{ $offer->public_id }}"
                                           style="font-family:monospace; font-weight:700;"
                                           onclick="this.select();">
                                    <button type="button" class="btn btn-bb-secondary" onclick="bbCopyOfferId()">
                                        <i class="fa fa-copy"></i> Copy
                                    </button>
                                </div>
                                <small class="text-muted">This is how Buyback Manager links your contract to this exact offer + locked price. Without it the contract won't be auto-detected.</small>
                            </li>
                            <li>
                                Reward you want: <code>{{ number_format((float) $offer->total_buyback_value, 2) }} ISK</code>
                            </li>
                            <li>
                                Submit.
                                @if($offer->isInstructionsOnly())
                                    A buyback director will confirm your contract manually.
                                @else
                                    Buyback Manager will detect the contract within 15 minutes.
                                @endif
                            </li>
                        </ol>
                        <p class="text-muted" style="font-size:0.85rem; margin-bottom:0;">
                            Final paid value is locked at <strong>{{ number_format((float) $offer->total_buyback_value, 2) }} ISK</strong> even if market prices move before the contract is created.
                        </p>
                        @if($offer->isInstructionsOnly())
                            <div class="alert alert-warning" style="margin-top:0.8rem; margin-bottom:0;">
                                <i class="fa fa-info-circle"></i>
                                This offer targets a corporation Buyback Manager can't see in its synced data,
                                so it won't auto-confirm. A director will match it to your contract manually.
                            </div>
                        @endif
                    </div>
                </div>

                {{-- Issuer cancel form --}}
                <div class="card card-dark">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fa fa-ban"></i> Cancel offer</h3>
                    </div>
                    <div class="card-body">
                        <p class="text-muted" style="font-size:0.9rem;">
                            If you changed your mind before creating the EVE contract, cancel here. The offer stays in the audit log.
                        </p>
                        <form method="POST" action="{{ route('buyback-manager.offers.cancel', $offer->public_id) }}"
                              onsubmit="return confirm('Cancel this offer? It cannot be reactivated.');">
                            @csrf
                            <button type="submit" class="btn btn-bb-secondary btn-block">
                                <i class="fa fa-ban"></i> Cancel offer
                            </button>
                        </form>
                    </div>
                </div>
            @endif

            {{-- Share URL card --}}
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title"><i class="fa fa-share-alt"></i> Share</h3>
                </div>
                <div class="card-body">
                    <p class="text-muted" style="font-size:0.85rem;">Paste this URL in Discord / corp chat:</p>
                    <input type="text" class="form-control" readonly
                           value="{{ route('buyback-manager.offers.show', $offer->public_id) }}"
                           onclick="this.select(); document.execCommand('copy');">
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('javascript')
<script>
    function bbCopyOfferId() {
        var el = document.getElementById('bb-offer-id-copy');
        if (!el) return;
        el.select();
        el.setSelectionRange(0, 99999);
        try { document.execCommand('copy'); } catch (e) {}
        if (navigator.clipboard) { navigator.clipboard.writeText(el.value).catch(function(){}); }
    }
</script>
@endpush
