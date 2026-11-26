{{-- The appraisal behind a contract, opened by Details beside its key. It is moved to <body> before it opens, so its styles are its own. --}}
@php
    $appraisal = $quote['appraisal'];
    $haulFee = (float) $contract->haul_fee;
    $linesPruned = $appraisal !== null && $appraisal->items->isEmpty()
        && $appraisal->created_at !== null && $appraisal->created_at->lt(now()->subDays($quote['item_days']));
    $quotedWhen = $appraisal !== null && $appraisal->created_at !== null
        ? $appraisal->created_at->format('j M Y, H:i') . ' EVE'
            . ($contract->issued_date ? ', ' . $appraisal->created_at->diffForHumans($contract->issued_date) . ' the contract' : '')
        : '-';
    $sideWords = ['buy' => 'of buy', 'sell' => 'of sell', 'split' => 'of split'];
    $excluded = $appraisal !== null && is_array($appraisal->excluded_json) ? $appraisal->excluded_json : [];
@endphp
<div class="modal fade bb-detail-modal" id="bb-appraisal-details" tabindex="-1" role="dialog" aria-labelledby="bb-appraisal-details-title" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="bb-appraisal-details-title">
                    <i class="fas fa-receipt"></i> Appraisal <code>{{ $contract->appraisal_public_id }}</code>
                </h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
            </div>
            <div class="modal-body">
                @if($appraisal === null)
                    <div class="bb-modal-note">
                        <i class="fas fa-history"></i>
                        <div>
                            This appraisal is no longer stored. Appraisals are kept for {{ number_format($quote['header_days']) }} days.
                            The contract items on this page are the copy taken when the contract matched it, at the prices it quoted.
                        </div>
                    </div>
                @else
                    <div class="bb-figures">
                        <div class="bb-figure">
                            <div class="bb-figure-label">Quote</div>
                            <div class="bb-figure-value">{{ number_format((float) $appraisal->total_buyback_value, 2) }} <small>ISK</small></div>
                            <div class="bb-figure-sub">{{ number_format((float) $appraisal->average_percentage, 2) }}% of {{ number_format((float) $appraisal->total_market_value, 2) }} ISK market value</div>
                        </div>
                        <div class="bb-figure">
                            <div class="bb-figure-label">Haul fee</div>
                            <div class="bb-figure-value">{{ number_format($haulFee, 2) }} <small>ISK</small></div>
                            <div class="bb-figure-sub">{{ $haulFee > 0 ? 'where the contract was made' : 'none where the contract was made' }}</div>
                        </div>
                        <div class="bb-figure bb-figure-accent">
                            <div class="bb-figure-label">Total</div>
                            <div class="bb-figure-value">{{ number_format((float) $contract->total_value, 2) }} <small>ISK</small></div>
                            <div class="bb-figure-sub">the price expected on the contract</div>
                        </div>
                    </div>

                    <dl class="bb-facts">
                        <dt>Quoted</dt>
                        <dd>{{ $quotedWhen }}</dd>
                        <dt>Rates</dt>
                        <dd>
                            @if($appraisal->isMemberRate())
                                <span class="badge badge-primary">Member rates</span>
                                <span class="bb-muted">each line's rate includes the member bonus</span>
                            @else
                                <span class="badge badge-secondary">Public rates</span>
                            @endif
                        </dd>
                        <dt>Prices from</dt>
                        <dd>{{ $quote['priced_by'] ?? '-' }}</dd>
                        <dt>Volume</dt>
                        <dd>{{ number_format((float) $appraisal->total_volume, 2) }} m3</dd>
                        <dt>Made by</dt>
                        <dd>{{ $appraisal->generatedByLabel() }}</dd>
                    </dl>

                    <h6 class="bb-modal-section">Lines</h6>
                    @if($appraisal->items->isNotEmpty())
                        <div class="bb-scroll">
                            <table class="bb-lines">
                                <thead>
                                    <tr>
                                        <th>Item</th>
                                        <th class="num">Quantity</th>
                                        <th class="num">Market</th>
                                        <th class="num">Rate</th>
                                        <th class="num">Unit price</th>
                                        <th class="num">Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($appraisal->items as $line)
                                        <tr>
                                            <td>
                                                {{ $line->type_name ?? ('Type #' . $line->type_id) }}
                                                @if($line->refine_yield !== null)
                                                    <span class="bb-line-badge">refined at {{ rtrim(rtrim(number_format((float) $line->refine_yield, 2), '0'), '.') }}%</span>
                                                @endif
                                                @if($line->thin_market)
                                                    <span class="bb-line-badge thin">thin market</span>
                                                @endif
                                            </td>
                                            <td class="num">{{ number_format((int) $line->quantity) }}</td>
                                            <td class="num">{{ number_format((float) $line->market_price, 2) }}</td>
                                            <td class="num">{{ number_format((float) $line->percentage, 1) }}% {{ $sideWords[$line->price_side] ?? '' }}</td>
                                            <td class="num">{{ number_format((float) $line->buyback_price, 2) }}</td>
                                            <td class="num">{{ number_format((float) $line->total_buyback, 2) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <td colspan="2">Quote</td>
                                        <td class="num">{{ number_format((float) $appraisal->total_market_value, 2) }}</td>
                                        <td class="num">{{ number_format((float) $appraisal->average_percentage, 2) }}%</td>
                                        <td></td>
                                        <td class="num">{{ number_format((float) $appraisal->total_buyback_value, 2) }}</td>
                                    </tr>
                                    @if($haulFee > 0)
                                        <tr class="bb-lines-minor">
                                            <td colspan="5">Haul fee where the contract was made</td>
                                            <td class="num">-{{ number_format($haulFee, 2) }}</td>
                                        </tr>
                                        <tr>
                                            <td colspan="5">Total</td>
                                            <td class="num">{{ number_format((float) $contract->total_value, 2) }}</td>
                                        </tr>
                                    @endif
                                </tfoot>
                            </table>
                        </div>
                    @elseif($linesPruned)
                        <div class="bb-modal-note">
                            <i class="fas fa-history"></i>
                            <div>
                                The lines of this appraisal have been pruned: they are kept for {{ number_format($quote['item_days']) }} days, the totals above for {{ number_format($quote['header_days']) }}.
                                The contract items on this page are the copy taken when the contract matched it, at the prices it quoted.
                            </div>
                        </div>
                    @else
                        <div class="bb-modal-note">
                            <i class="fas fa-info-circle"></i>
                            <div>This appraisal priced no lines.</div>
                        </div>
                    @endif

                    @if(count($excluded))
                        <h6 class="bb-modal-section">Not accepted</h6>
                        <div class="bb-modal-note warn">
                            <i class="fas fa-ban"></i>
                            <div>
                                Left out of the quote:
                                @foreach($excluded as $ex)
                                    <span class="d-block">
                                        {{ $ex['type_name'] ?? ('Type #' . ($ex['type_id'] ?? '?')) }} &times; {{ number_format((int) ($ex['quantity'] ?? 0)) }}{{ ($ex['reason'] ?? null) === 'unpriced' ? ', no market price' : '' }}
                                    </span>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    @if($appraisal->pricing_note)
                        <div class="bb-modal-note">
                            <i class="fas fa-info-circle"></i>
                            <div>{{ $appraisal->pricing_note }}</div>
                        </div>
                    @endif
                @endif
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-bb-secondary" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
