<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $corpName }} Buyback</title>
    <link rel="stylesheet" href="{{ asset('vendor/buyback-manager/css/public.css') }}?v=12">
    <link rel="stylesheet" href="{{ asset('vendor/buyback-manager/css/hamsters.css') }}?v=4">
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
        @if($appraisalEnabled)
            <a class="bb-public-cta" href="#bb-estimate">Get an appraisal</a>
        @endif
    </div>

    <div class="bb-public-wrap {{ $layout === 'split' ? 'bb-public-wrap-split' : '' }}">

        <div class="bb-public-sections">

        @if($rates)
        <div class="bb-public-section">
            <h2>Our rates</h2>
            @if($rates['show_detail'] && $rates['source'])
                <p class="bb-rates-market">Prices come from {{ $rates['source'] }}.</p>
            @endif
            <div class="bb-rate-cards">
                <div class="bb-rate-card">
                    <div class="label">Base rate</div>
                    <div class="value">{{ $rates['base_percentage'] }}%</div>
                    <div class="sub">of market value</div>
                </div>
                <div class="bb-rate-card">
                    <div class="label">Quote freshness</div>
                    <div class="value">{{ $rates['stale_hours'] }}h</div>
                    <div class="sub">re-checked after this</div>
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
                        @if($item['refine_yield'] !== null)<span class="bb-side-badge" title="Paid on the minerals it refines into">refined at {{ $item['refine_yield'] }}%</span>@endif
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
            <div class="bb-note">These steps follow this corporation's buyback setup. No account is needed to sell.</div>
        </div>

        </div>

        @if($appraisalEnabled)
        <div class="bb-public-section bb-estimate-section" id="bb-estimate">
            <h2>Quick estimate</h2>
            <p class="bb-estimate-note">Paste your items to see what we'd pay. No login needed, and you'll get a key to put in your contract.</p>
            <div class="bb-paste-row">
                <div>
                    <textarea id="bb-estimate-input" class="bb-estimate-input" rows="5" placeholder="Paste items, one per line (e.g. Tritanium  1000)"></textarea>
                    <button type="button" id="bb-estimate-btn" class="bb-public-cta" style="margin-top:12px; border:none; cursor:pointer;">Estimate</button>
                </div>
                @include('buyback-manager::partials.idle-hamster', ['for' => 'bb-estimate-input', 'button' => 'Estimate'])
            </div>
            @include('buyback-manager::partials.hamsters', ['id' => 'bb-estimate-hamsters', 'hidden' => true])
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
    <script src="{{ asset('vendor/buyback-manager/js/hamsters.js') }}?v=2"></script>
    <script>
        (function () {
            var btn = document.getElementById('bb-estimate-btn');
            if (!btn) return;
            var input = document.getElementById('bb-estimate-input');
            var out = document.getElementById('bb-estimate-result');
            // Relative URL on purpose: an absolute one carries whatever scheme
            // Laravel derived from APP_URL / the proxy headers, and if that is
            // http while the page is served over https the browser treats it as
            // cross-origin and a "connect-src 'self'" CSP blocks the fetch.
            // A root-relative path is always same-origin.
            var url = "{{ route('buyback-manager.public.estimate', ['ticker' => $link], false) }}";
            var prepareUrl = "{{ route('buyback-manager.public.estimate.prepare', ['ticker' => $link], false) }}";
            var token = "{{ csrf_token() }}";
            var hamsters = BuybackHamsters.create(document.getElementById('bb-estimate-hamsters'));
            function isk(n) { return new Intl.NumberFormat('en-US', { maximumFractionDigits: 2 }).format(n) + ' ISK'; }
            function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }
            btn.addEventListener('click', function () {
                var items = (input.value || '').trim();
                out.style.display = 'block';
                if (items.length < 3) { out.textContent = 'Paste some items first.'; return; }
                btn.disabled = true;
                out.style.display = 'none';
                hamsters.start();
                var headers = { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token, 'Accept': 'application/json' };
                BuybackHamsters.waitForPrices({
                    prepareUrl: prepareUrl,
                    waitUrl: function (key) { return prepareUrl + '/' + key; },
                    body: JSON.stringify({ items: items }),
                    headers: headers,
                    onWaiting: function (count, text) { hamsters.sub(text); }
                }).then(function () {
                    return fetch(url, {
                        method: 'POST',
                        headers: headers,
                        body: JSON.stringify({ items: items })
                    });
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
                    if (res.j && res.j.no_prices) {
                        hamsters.outOfCoffee(res.j.message);
                        return;
                    }
                    hamsters.stop();
                    out.style.display = 'block';
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
                            return esc(e.name) + ' &times; ' + new Intl.NumberFormat('en-US').format(e.quantity)
                                + (e.unpriced ? ' (no market price)' : '');
                        }).join(', ');
                        notAccepted = '<div class="bb-estimate-warn"><strong>' + j.excluded.length
                            + ' item(s) not accepted</strong> and not included in this estimate: ' + names
                            + '. Ask us for a custom quote on anything we do not buy.</div>';
                    }
                    if (j.unrecognised && j.unrecognised.length) {
                        notAccepted += '<div class="bb-estimate-warn"><strong>' + j.unrecognised.length
                            + ' line(s) not recognised</strong> and not included: '
                            + j.unrecognised.map(esc).join(', ') + '. Check the spelling, or copy the items from the game.</div>';
                    }
                    if (j.quoted_at) {
                        notAccepted += '<div class="bb-estimate-sub">Quoted ' + esc(j.quoted_at)
                            + (j.priced_by ? ', with prices from ' + esc(j.priced_by) : '') + '.</div>';
                    }
                    if (j.pricing_note) {
                        notAccepted += '<div class="bb-estimate-sub">' + esc(j.pricing_note) + '</div>';
                    }
                    if (j.thin_market) {
                        notAccepted += '<div class="bb-estimate-sub">' + j.thin_market + ' item(s) on a thin market were priced at the average of the top 5% of buy orders, not at the top order.</div>';
                    }
                    var fees = '';
                    if (j.haul_fees && j.haul_fees.length) {
                        fees = '<div class="bb-estimate-sub">A haul fee comes off where you contract from:</div><ul class="bb-estimate-sub" style="margin:4px 0 0; padding-left:18px;">'
                            + j.haul_fees.map(function (f) {
                                return '<li>' + esc(f.location) + ': <strong>' + isk(f.price) + '</strong> (' + isk(f.fee) + ' haul fee)</li>';
                            }).join('')
                            + '</ul>';
                        if (j.volume_assembled && j.volume_assembled.length) {
                            fees += '<div class="bb-estimate-warn">Counted assembled, since their packaged volume is not known yet: '
                                + j.volume_assembled.map(esc).join(', ') + '. The haul fee is too high for them; estimate again in a few minutes.</div>';
                        }
                    }
                    out.innerHTML = '<div class="bb-estimate-total">' + isk(j.total_buyback_value) + '</div>'
                        + '<div class="bb-estimate-sub">' + j.item_count + ' item type(s) &middot; ' + j.average_percentage + '% of market'
                        + ' &middot; ' + new Intl.NumberFormat('en-US', { maximumFractionDigits: 2 }).format(j.total_volume || 0) + ' m3'
                        + (j.truncated ? ' &middot; list truncated' : '') + '</div>'
                        + fees
                        + (j.appraisal_url
                            ? '<div style="margin-top:12px;"><a class="bb-public-cta" href="' + j.appraisal_url + '">See full appraisal &amp; get your key</a></div>'
                            : '<div class="bb-estimate-sub">Run the appraisal again to get a contract key.</div>')
                        + notAccepted;
                }).catch(function () {
                    btn.disabled = false;
                    hamsters.stop();
                    out.style.display = 'block';
                    out.textContent = 'Could not estimate.';
                });
            });
        })();
    </script>
    @endif
</body>
</html>
