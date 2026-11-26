<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $corpName }} Buyback</title>
    <link rel="stylesheet" href="{{ asset('vendor/buyback-manager/css/public.css') }}?v=14">
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

    <div class="bb-public-hero bb-public-hero--closed">
        <div class="bb-public-hero-overlay"></div>
        @if($logoStyle === 'light')
            <div class="bb-public-logo-frame"><img class="bb-public-logo" src="{{ $logoUrl }}" alt="{{ $corpName }} logo"></div>
        @else
            <img class="bb-public-logo {{ $logoStyle === 'none' ? 'bb-logo-none' : '' }}" src="{{ $logoUrl }}" alt="{{ $corpName }} logo">
        @endif
        <h1 class="bb-public-title">{{ $setting->public_headline ?: ($corpName . ' Buyback') }}</h1>
        <div class="bb-public-ticker">{{ $badge }}</div>
    </div>

    <div class="bb-public-wrap">

        <div class="bb-public-section bb-closed">
            <h2>{{ $permanent ? 'Permanently closed' : 'Temporarily closed' }}</h2>
            <div class="bb-hamsters">
                <div class="bb-hamsters__stage">
                    @if($permanent)
                        @include('buyback-manager::partials.closed-hamsters.gone')
                    @else
                        @include('buyback-manager::partials.closed-hamsters.back')
                    @endif
                </div>
                <div class="bb-hamsters__line">{{ $permanent ? 'The hamsters have packed up and moved on.' : 'The hamsters are on a break.' }}</div>
            </div>
            <p class="bb-closed__message">{{ $message }}</p>
        </div>

        @if($setting->public_footer_text)
            <div class="bb-corp-footer">{{ $setting->public_footer_text }}</div>
        @endif

    </div>

    <footer class="bb-brand-footer">
        <div class="brand"><a href="https://github.com/MattFalahe/Buyback-Manager" target="_blank" rel="noopener">Powered by Buyback Manager</a></div>
        <div class="slogan">Where Resources Find Their Worth.</div>
        <div class="sub">Every delivery measured. Every contribution rewarded.</div>
    </footer>
</body>
</html>
