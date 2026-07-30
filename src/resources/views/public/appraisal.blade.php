<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Appraisal {{ $appraisal->public_id }} - {{ $corpName }}</title>
    <link rel="stylesheet" href="{{ asset('vendor/buyback-manager/css/public.css') }}?v=10">
    <style>
        .bb-public-body { --bb-pub-accent: {{ $accent }}; }
    </style>
</head>
<body class="bb-public-body">

    <div class="bb-public-wrap" style="padding-top:32px;">

        <a class="bb-back-link" href="{{ $publicUrl }}">&larr; {{ $corpName }} buyback</a>

        <div class="bb-public-section">
            <h2>Your appraisal</h2>

            @if($appraisal->isClaimed())
                <div class="bb-estimate-warn">
                    <strong>This key has already been used</strong> by a contract, so it cannot be used again.
                    Run a new appraisal if you have more to sell.
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
                    <div class="sub">{{ number_format((float) $appraisal->average_percentage, 2) }}% of market{{ $appraisal->market ? ' · ' . ucfirst($appraisal->market) : '' }}</div>
                </div>
            </div>

            <div class="bb-key-box">
                <div class="bb-key-label">Paste this into the contract Description</div>
                <div class="bb-key-row">
                    <input type="text" id="bb-key" class="bb-key-input" value="{{ $appraisal->public_id }}" readonly onclick="this.select();">
                    <button type="button" class="bb-public-cta" style="border:none; cursor:pointer;" onclick="bbCopyKey()">Copy</button>
                </div>
                <div class="bb-key-hint">Without this key we cannot match your contract to this quote.</div>
            </div>
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
                Set the contract price to <strong>{{ number_format((float) $appraisal->total_buyback_value, 2) }} ISK</strong>.
                A different amount still reaches us, but it is flagged for a director to check, which slows your payout.
                Quotes older than {{ $staleHours }} hours are re-checked against the market.
            </div>
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
                We do not currently buy these, so they are <strong>not included</strong> in the value above.
                Leave them out of the contract and ask us for a custom quote.
                <ul style="margin:8px 0 0; padding-left:18px;">
                    @foreach($excluded as $ex)
                        <li>{{ $ex['type_name'] ?? ('Type #' . ($ex['type_id'] ?? '?')) }} &times; {{ number_format((int) ($ex['quantity'] ?? 0)) }}</li>
                    @endforeach
                </ul>
            </div>
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
                            <th class="num">Unit</th>
                            <th class="num">%</th>
                            <th class="num">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($appraisal->items as $item)
                        <tr>
                            <td>{{ $item->type_name ?? ('Type #' . $item->type_id) }}</td>
                            <td class="num">{{ number_format((int) $item->quantity) }}</td>
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
        function bbCopyKey() {
            var field = document.getElementById('bb-key');
            field.select();
            try { document.execCommand('copy'); } catch (e) {}
            if (navigator.clipboard) { navigator.clipboard.writeText(field.value).catch(function () {}); }
        }
    </script>
</body>
</html>
