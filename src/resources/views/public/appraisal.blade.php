<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Appraisal {{ $appraisal->public_id }} - {{ $corpName }}</title>
    <link rel="stylesheet" href="{{ asset('vendor/buyback-manager/css/public.css') }}?v=14">
    <style>
        .bb-public-body { --bb-pub-accent: {{ $accent }}; }
    </style>
</head>
<body class="bb-public-body">

    <div class="bb-public-wrap" style="padding-top:32px;">

        <a class="bb-back-link" href="{{ $publicUrl }}">&larr; {{ $corpName }} buyback</a>

        <div class="bb-public-section">
            <h2>Your appraisal</h2>

            @if($keyStatus)
                <div class="bb-status bb-status-{{ $keyStatus['tone'] }}">
                    <strong>{{ $keyStatus['title'] }}.</strong> {{ $keyStatus['text'] }}
                </div>
            @elseif($isStale)
                <div class="bb-status bb-status-warn">
                    <strong>This quote is more than {{ $staleHours }} hours old.</strong>
                    A contract made with it still reaches us, but it is flagged for a director to check against
                    today's prices, which slows your payout. Run a new appraisal for a current quote.
                </div>
            @endif

            <div class="bb-rate-cards">
                <div class="bb-rate-card">
                    <div class="label">We pay</div>
                    <div class="value">{{ number_format((float) $appraisal->total_buyback_value, 2) }}</div>
                    <div class="sub">ISK for this list</div>
                </div>
                <div class="bb-rate-card">
                    <div class="label">Market value</div>
                    <div class="value" style="color:#c9d1d9;">{{ number_format((float) $appraisal->total_market_value, 2) }}</div>
                    <div class="sub">{{ number_format((float) $appraisal->average_percentage, 2) }}% of market</div>
                </div>
            </div>

            @php
                $quoteMeta = [];
                if ($appraisal->created_at) {
                    $quoteMeta[] = 'Quoted ' . $appraisal->created_at->format('j M Y, H:i') . ' EVE';
                }
                if ($validUntil && ! $keyStatus) {
                    $quoteMeta[] = 'valid until ' . $validUntil->format('j M Y, H:i') . ' EVE';
                }
                if ($pricedBy) {
                    $quoteMeta[] = 'prices from ' . $pricedBy;
                }
                if ($appraisal->isMemberRate()) {
                    $quoteMeta[] = 'member rates';
                }
                $quoteMeta[] = number_format((float) $appraisal->total_volume, 2) . ' m3';
            @endphp
            @if(count($quoteMeta))
                <div class="bb-quote-meta">{{ implode(' · ', $quoteMeta) }}</div>
            @endif

            <div class="bb-key-box">
                <div class="bb-key-label">Paste this into the contract Description</div>
                <div class="bb-key-row">
                    <input type="text" id="bb-key" class="bb-key-input" value="{{ $appraisal->public_id }}" readonly onclick="this.select();">
                    <button type="button" class="bb-public-cta" style="border:none; cursor:pointer;" onclick="bbCopy('bb-key', this)">Copy</button>
                </div>
                <div class="bb-key-hint">Without this key we cannot match your contract to this quote.</div>
            </div>

            @unless($keyStatus)
            <div class="bb-copy-grid">
                <div class="bb-copy-field">
                    <div class="bb-key-label">Contract price (ISK)</div>
                    <div class="bb-key-row">
                        <input type="text" id="bb-price" class="bb-key-input bb-copy-input" value="{{ $copyPrice }}" readonly onclick="this.select();">
                        <button type="button" class="bb-public-cta" style="border:none; cursor:pointer;" onclick="bbCopy('bb-price', this)">Copy</button>
                    </div>
                    <div class="bb-key-hint">Exactly as quoted, to the cent.{{ count($haulFees) ? ' Anywhere without a haul fee below.' : '' }}</div>
                </div>
                <div class="bb-copy-field">
                    <div class="bb-key-label">Contract to</div>
                    <div class="bb-key-row">
                        <input type="text" id="bb-recipient" class="bb-key-input bb-copy-input" value="{{ $recipient }}" readonly onclick="this.select();">
                        <button type="button" class="bb-public-cta" style="border:none; cursor:pointer;" onclick="bbCopy('bb-recipient', this)">Copy</button>
                    </div>
                </div>
            </div>

            @if(count($haulFees))
            <div class="bb-key-label" style="margin-top:16px;">Contract price where a haul fee comes off</div>
            <div class="bb-copy-grid">
                @foreach($haulFees as $i => $fee)
                <div class="bb-copy-field">
                    <div class="bb-key-label">{{ $fee['location'] }}</div>
                    <div class="bb-key-row">
                        <input type="text" id="bb-price-{{ $i }}" class="bb-key-input bb-copy-input" value="{{ \BuybackManager\Models\BuybackSetting::contractPrice($fee['price']) }}" readonly onclick="this.select();">
                        <button type="button" class="bb-public-cta" style="border:none; cursor:pointer;" onclick="bbCopy('bb-price-{{ $i }}', this)">Copy</button>
                    </div>
                    <div class="bb-key-hint">{{ number_format($fee['fee'], 2) }} ISK haul fee, {{ \BuybackManager\Models\BuybackSetting::iskPerM3($fee['per_m3']) }}.</div>
                </div>
                @endforeach
            </div>
            @endif
            @endunless
        </div>

        <div class="bb-public-section">
            <h2>How to sell to us</h2>
            <ol class="bb-steps">
                @foreach($instructions as $i => $step)
                <li class="bb-step">
                    <span class="bb-step-num">{{ $i + 1 }}</span>
                    <span class="bb-step-text">{{ $step }}</span>
                </li>
                @endforeach
            </ol>
            <div class="bb-note">
                Set the contract price to <strong>{{ number_format((float) $appraisal->total_buyback_value, 2) }} ISK</strong>{{ count($haulFees) ? ', less the haul fee where you contract from one of the locations above' : '' }}.
                A different amount still reaches us, but it is flagged for a director to check, which slows your payout.
                Quotes older than {{ $staleHours }} hours are re-checked against the market.
            </div>
            @if($membersOnlyNote)
                <div class="bb-note bb-members-note">{{ $membersOnlyNote }}</div>
            @endif
            @if($memberRatesNote && ! $appraisal->isMemberRate())
                <div class="bb-note bb-members-note"><a href="{{ $membersUrl }}">{{ $memberRatesNote }}</a></div>
            @endif
        </div>

        @if(count($locations))
        <div class="bb-public-section">
            <h2>We accept from</h2>
            <div class="bb-rate-list">
                @foreach($locations as $location)
                    <div class="bb-rate-row"><span>{{ $location }}</span></div>
                @endforeach
            </div>
            <div class="bb-note">Contracts created anywhere else are flagged and may be declined.</div>
        </div>
        @endif

        @if(count($excluded))
        <div class="bb-public-section">
            <h2>Not accepted</h2>
            <div class="bb-estimate-warn">
                These are <strong>not included</strong> in the value above, so leave them out of the contract.
                Ask us for a custom quote on anything we do not buy. An item with no market price had none
                anywhere we look when this was appraised.
                <ul style="margin:8px 0 0; padding-left:18px;">
                    @foreach($excluded as $ex)
                        <li>
                            {{ $ex['type_name'] ?? ('Type #' . ($ex['type_id'] ?? '?')) }} &times; {{ number_format((int) ($ex['quantity'] ?? 0)) }}
                            @if(($ex['reason'] ?? null) === 'unpriced')
                                (no market price)
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
        @endif

        @if($pricingNote)
        <div class="bb-public-section">
            <div class="bb-note">{{ $pricingNote }}</div>
        </div>
        @endif

        @if($appraisal->items->count())
        <div class="bb-public-section">
            <h2>Breakdown</h2>
            <div class="bb-table-scroll">
                <table class="bb-items-table">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th class="num">Quantity</th>
                            <th class="num">Volume</th>
                            <th class="num">Unit</th>
                            <th class="num">%</th>
                            <th class="num">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($appraisal->items as $item)
                        <tr>
                            <td>
                                {{ $item->type_name ?? ('Type #' . $item->type_id) }}
                                @if(isset($ruleNames[$item->id]))
                                    <span class="bb-side-badge">{{ $ruleNames[$item->id] }}</span>
                                @endif
                                @if($item->refine_yield !== null)
                                    <span class="bb-side-badge">refined at {{ rtrim(rtrim(number_format((float) $item->refine_yield, 2), '0'), '.') }}%</span>
                                @endif
                                @if($item->thin_market)
                                    <span class="bb-side-badge" title="The top buy order stood more than {{ rtrim(rtrim(number_format($thinGuard, 2), '0'), '.') }}% above the average of the top 5%">thin market, priced at the top-5% average</span>
                                @endif
                            </td>
                            <td class="num">{{ number_format((int) $item->quantity) }}</td>
                            <td class="num">{{ number_format((float) $item->unit_volume * (int) $item->quantity, 2) }} m3</td>
                            <td class="num">{{ number_format((float) $item->buyback_price, 2) }}</td>
                            <td class="num">{{ number_format((float) $item->percentage, 1) }}%</td>
                            <td class="num">{{ number_format((float) $item->total_buyback, 2) }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        @else
        <div class="bb-public-section">
            <div class="bb-note">The line-by-line breakdown for this appraisal has aged out, but the quote above still stands.</div>
        </div>
        @endif

    </div>

    <footer class="bb-brand-footer">
        <div class="brand"><a href="https://github.com/MattFalahe/Buyback-Manager" target="_blank" rel="noopener">Powered by Buyback Manager</a></div>
        <div class="slogan">Where Resources Find Their Worth.</div>
        <div class="sub">Every delivery measured. Every contribution rewarded.</div>
    </footer>

    <script>
        function bbCopy(id, btn) {
            var field = document.getElementById(id);
            field.select();
            var label = btn.getAttribute('data-label') || btn.textContent;
            btn.setAttribute('data-label', label);
            var copied = function () {
                btn.textContent = 'Copied';
                setTimeout(function () { btn.textContent = label; }, 1500);
            };
            // The clipboard API needs https; execCommand covers a plain
            // http install.
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(field.value).then(copied, function () {
                    try { if (document.execCommand('copy')) { copied(); } } catch (e) {}
                });
                return;
            }
            try { if (document.execCommand('copy')) { copied(); } } catch (e) {}
        }
    </script>
</body>
</html>
