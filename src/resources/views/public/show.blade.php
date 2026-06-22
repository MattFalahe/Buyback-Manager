<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $corpName }} Buyback</title>
    <link rel="stylesheet" href="{{ asset('vendor/buyback-manager/css/public.css') }}?v=5">
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
        @if($setting->public_logo_bg)
            <div class="bb-public-logo-frame"><img class="bb-public-logo" src="{{ $logoUrl }}" alt="{{ $corpName }} logo"></div>
        @else
            <img class="bb-public-logo" src="{{ $logoUrl }}" alt="{{ $corpName }} logo">
        @endif
        <h1 class="bb-public-title">{{ $setting->public_headline ?: ($corpName . ' Buyback') }}</h1>
        <div class="bb-public-ticker">{{ $ticker }}</div>
        <p class="bb-public-blurb">{{ $setting->public_blurb ?: 'We buy your items at competitive rates, with fast and locked-in payouts.' }}</p>
        <a class="bb-public-cta" href="{{ $loginUrl }}">Log in to appraise &amp; sell</a>
    </div>

    <div class="bb-public-wrap">

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
</body>
</html>
