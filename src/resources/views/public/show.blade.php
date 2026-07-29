<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $corpName }} Buyback</title>
    <link rel="stylesheet" href="{{ asset('vendor/buyback-manager/css/public.css') }}?v=9">
    <style>
        .bb-public-body { --bb-pub-accent: {{ $accent }}; }
        @if($backgroundUrl)
        .bb-public-hero { background-image: url('{{ $backgroundUrl }}'); }
        @endif
        .bb-public-hero-overlay { opacity: {{ $overlay }}; }
    </style>
</head>
<body class="bb-public-body">

    <div class="bb-public-hero">
        <div class="bb-public-hero-overlay"></div>
        @if($logoStyle === 'light')
            <div class="bb-public-logo-frame"><img class="bb-public-logo" src="{{ $logoUrl }}" alt="{{ $corpName }} logo"></div>
        @else
            <img class="bb-public-logo {{ $logoStyle === 'none' ? 'bb-logo-none' : '' }}" src="{{ $logoUrl }}" alt="{{ $corpName }} logo">
        @endif
        <h1 class="bb-public-title">{{ $setting->public_headline ?: ($corpName . ' Buyback') }}</h1>
        <div class="bb-public-ticker">{{ $ticker }}</div>
        <p class="bb-public-blurb">{{ $setting->public_blurb ?: 'We buy your items at competitive rates, with fast and locked-in payouts.' }}</p>
        <a class="bb-public-cta" href="{{ $loginUrl }}">Log in to appraise &amp; sell</a>
    </div>

    <div class="bb-public-wrap {{ $layout === 'split' ? 'bb-public-wrap-split' : '' }}">

        <div class="bb-public-sections">

        @if($rates)
        <div class="bb-public-section">
            <h2>Our rates</h2>
            @if($rates['show_detail'] && $rates['market'])
                <p class="bb-rates-market">Prices are sourced from {{ $rates['market'] }}.</p>
            @endif
            <div class="bb-rate-cards">
                <div class="bb-rate-card">
                    <div class="label">Base rate</div>
                    <div class="value">{{ $rates['base_percentage'] }}%</div>
                    <div class="sub">of market value</div>
                </div>
                <div class="bb-rate-card">
                    <div class="label">Price lock</div>
                    <div class="value">{{ $rates['lock_hours'] }}h</div>
                    <div class="sub">quote held</div>
                </div>
            </div>
            @if(count($rates['items']))
            <div class="bb-rate-list">
                @foreach($rates['items'] as $item)
                <div class="bb-rate-row">
                    <span>
                        {{ $item['name'] }}
                        @if($item['featured'])<span class="bb-most-wanted">&#9733; most wanted</span>@endif
                        @if($item['price_side'])<span class="bb-side-badge">{{ $item['price_side'] }}</span>@endif
                    </span>
                    <span class="pct">{{ $item['percentage'] }}%</span>
                </div>
                @endforeach
            </div>
            @endif
        </div>
        @endif

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
            <div class="bb-note">These steps follow this corporation's buyback setup. Log in to get your offer id and start a sale.</div>
        </div>

        </div>

        @if($appraisalEnabled)
        <div class="bb-public-section bb-estimate-section">
            <h2>Quick estimate</h2>
            <p class="bb-estimate-note">Paste your items to see what we'd pay. Preview only &mdash; log in to lock it as an offer.</p>
            <textarea id="bb-estimate-input" class="bb-estimate-input" rows="5" placeholder="Paste items, one per line (e.g. Tritanium  1000)"></textarea>
            <button type="button" id="bb-estimate-btn" class="bb-public-cta" style="margin-top:12px; border:none; cursor:pointer;">Estimate</button>
            <div id="bb-estimate-result" class="bb-estimate-result" style="display:none;"></div>
        </div>
        @endif

        @if($setting->public_footer_text)
            <div class="bb-corp-footer">{{ $setting->public_footer_text }}</div>
        @endif

    </div>

    <footer class="bb-brand-footer">
        <div class="brand"><a href="https://github.com/MattFalahe/Buyback-Manager" target="_blank" rel="noopener">Powered by Buyback Manager</a></div>
        <div class="slogan">Where Resources Find Their Worth.</div>
        <div class="sub">Every delivery measured. Every contribution rewarded.</div>
        <div class="bb-opsec">No member names, volumes, or contract history are shown on this page.</div>
    </footer>

    @if($appraisalEnabled)
    <script>
        (function () {
            var btn = document.getElementById('bb-estimate-btn');
            if (!btn) return;
            var input = document.getElementById('bb-estimate-input');
            var out = document.getElementById('bb-estimate-result');
            var url = "{{ route('buyback-manager.public.estimate', ['ticker' => $ticker]) }}";
            var token = "{{ csrf_token() }}";
            function isk(n) { return new Intl.NumberFormat('en-US', { maximumFractionDigits: 2 }).format(n) + ' ISK'; }
            function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }
            btn.addEventListener('click', function () {
                var items = (input.value || '').trim();
                out.style.display = 'block';
                if (items.length < 3) { out.textContent = 'Paste some items first.'; return; }
                btn.disabled = true;
                out.textContent = 'Estimating...';
                fetch(url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token, 'Accept': 'application/json' },
                    body: JSON.stringify({ items: items })
                }).then(function (r) {
                    // Read as text first: a 404/419/500 returns an HTML error
                    // page, and calling r.json() on that throws, which would
                    // hide the real status behind a generic failure message.
                    return r.text().then(function (body) {
                        var parsed = null;
                        try { parsed = JSON.parse(body); } catch (e) {}
                        return { ok: r.ok, status: r.status, j: parsed };
                    });
                })
                .then(function (res) {
                    btn.disabled = false;
                    if (!res.j) {
                        out.textContent = res.status === 419
                            ? 'Your session expired. Refresh the page and try again.'
                            : 'Estimate failed (HTTP ' + res.status + '). Ask an admin to check the SeAT log.';
                        return;
                    }
                    if (!res.ok || !res.j.success) { out.textContent = res.j.message || 'Could not estimate.'; return; }
                    var j = res.j;
                    var notAccepted = '';
                    if (j.excluded && j.excluded.length) {
                        var names = j.excluded.map(function (e) {
                            return esc(e.name) + ' &times; ' + new Intl.NumberFormat('en-US').format(e.quantity);
                        }).join(', ');
                        notAccepted = '<div class="bb-estimate-warn"><strong>' + j.excluded.length
                            + ' item(s) not accepted</strong> and not included in this estimate: ' + names
                            + '. Ask us for a custom quote on those.</div>';
                    }
                    out.innerHTML = '<div class="bb-estimate-total">' + isk(j.total_buyback_value) + '</div>'
                        + '<div class="bb-estimate-sub">' + j.item_count + ' item type(s) &middot; ' + j.average_percentage + '% of market'
                        + (j.truncated ? ' &middot; list truncated' : '') + '</div>'
                        + '<div class="bb-estimate-sub">Log in to lock this as an offer.</div>'
                        + notAccepted;
                }).catch(function () { btn.disabled = false; out.textContent = 'Could not estimate.'; });
            });
        })();
    </script>
    @endif
</body>
</html>
