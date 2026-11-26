@extends('web::layouts.grids.12')

@section('title', 'Buyback Appraisal')
@section('page_header', 'Buyback Appraisal')

@push('head')
    <link rel="stylesheet" href="{{ asset('vendor/buyback-manager/css/buyback-manager.css') }}?v=7">
    <link rel="stylesheet" href="{{ asset('vendor/buyback-manager/css/public.css') }}?v=14">
    <link rel="stylesheet" href="{{ asset('vendor/buyback-manager/css/hamsters.css') }}?v=4">
    @if($setting)
    <style>
        .bb-members-page { --bb-pub-accent: {{ $accent }}; }
        @if($backgroundUrl)
        .bb-members-page .bb-public-hero { background-image: url('{{ $backgroundUrl }}'); }
        @endif
        .bb-members-page .bb-public-hero-overlay { opacity: {{ $overlay }}; }
    </style>
    @endif
@endpush

@section('full')
<div class="buyback-manager-wrapper">
@if(! $setting)
    <div class="card card-dark">
        <div class="card-body text-muted">No buyback programme is open right now.</div>
    </div>
@else
    @php
        $membersOnly = $setting->sellerScope() !== \BuybackManager\Models\BuybackSetting::SELLERS_ANY;
        $pct = fn ($value) => rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    @endphp
    <div class="bb-public-body bb-members-page">

        @if($settings->count() > 1)
            <nav class="bb-members-picker" aria-label="Buyback programmes">
                @foreach($settings as $programme)
                    <a href="{{ route('buyback.appraisal.index', ['programme' => $programme->corporation_id]) }}"
                       class="{{ (int) $programme->id === (int) $setting->id ? 'active' : '' }}">{{ $programme->corporation->name ?? ('Corporation #' . $programme->corporation_id) }}</a>
                @endforeach
            </nav>
        @endif

        <div class="bb-public-hero">
            <div class="bb-public-hero-overlay"></div>
            @if($logoStyle === 'light')
                <div class="bb-public-logo-frame"><img class="bb-public-logo" src="{{ $logoUrl }}" alt="{{ $corpName }} logo"></div>
            @else
                <img class="bb-public-logo {{ $logoStyle === 'none' ? 'bb-logo-none' : '' }}" src="{{ $logoUrl }}" alt="{{ $corpName }} logo">
            @endif
            <h1 class="bb-public-title">{{ $setting->public_headline ?: ($corpName . ' Buyback') }}</h1>
            <div class="bb-public-ticker">{{ $badge }}</div>
            <div class="bb-public-blurb">{{ $blurb }}</div>
            <a class="bb-public-cta" href="#bb-members-appraisal">Get an appraisal</a>
        </div>

        <div class="bb-public-wrap {{ $setting->public_layout === 'split' ? 'bb-public-wrap-split' : '' }}">

            @if($memberRates)
                <div class="bb-status bb-status-ok">
                    <strong>Member rates.</strong>
                    Your account holds {{ $memberName }}, a member of {{ $groupName }}, so every rule pays you {{ $bonus }} points more, up to 100%.
                </div>
            @elseif($memberName === null && $setting->hasMemberRates())
                <div class="bb-status bb-status-info">
                    <strong>Public rates.</strong>
                    None of your characters is in {{ $groupName }}, so you are quoted the public rates.
                </div>
            @endif
            @if($membersOnly && $memberName === null)
                <div class="bb-status bb-status-warn">
                    This buyback is for members of {{ $groupName }}. You can still contract it, and a director decides whether to accept.
                </div>
            @endif

            <div class="bb-public-sections">

                <div class="bb-public-section">
                    <h2>{{ $memberRates ? 'Your member rates' : 'Our rates' }}</h2>
                    @if($rates['show_detail'] && $rates['source'])
                        <p class="bb-rates-market">Prices come from {{ $rates['source'] }}.</p>
                    @endif
                    <div class="bb-rate-cards">
                        <div class="bb-rate-card">
                            <div class="label">Base rate</div>
                            <div class="value">{{ $pct($rates['base_percentage']) }}%</div>
                            <div class="sub">{{ $memberRates ? 'public rate ' . $pct($rates['public_base_percentage']) . '%' : 'of market value' }}</div>
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
                                    <span class="pct">
                                        @if($memberRates && $item['public_percentage'] != $item['percentage'])
                                            <span class="bb-public-pct">public {{ $pct($item['public_percentage']) }}%</span>
                                        @endif
                                        {{ $pct($item['percentage']) }}%
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    @endif
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
                    <div class="bb-note">These steps follow this corporation's buyback setup. The appraisal below remembers which rates it quoted.</div>
                </div>

            </div>

            <div class="bb-public-section bb-estimate-section" id="bb-members-appraisal">
                <h2>Appraisal</h2>
                @if(session('no_prices'))
                    @include('buyback-manager::partials.hamsters', ['id' => 'bb-hamsters-out', 'out' => true, 'message' => session('no_prices')])
                @endif

                <form method="POST" action="{{ route('buyback.appraisal.create') }}" id="appraisal-form">
                    @csrf
                    <input type="hidden" name="corporation_id" id="corporation_id" value="{{ $setting->corporation_id }}">
                    <p class="bb-estimate-note">Paste your items to get a quote{{ $memberRates ? ' at member rates' : '' }} and a key to put in your contract.</p>
                    <div class="bb-paste-row">
                        <div>
                            <textarea
                                name="items"
                                id="items"
                                class="bb-estimate-input"
                                rows="10"
                                placeholder="Paste your items here...&#10;&#10;Supported formats:&#10;• EVE inventory or contract (Ctrl+C)&#10;• Item Name    Quantity&#10;• EFT fit"
                                required
                            >{{ old('items') }}</textarea>
                            @error('items')
                                <div class="bb-estimate-warn">{{ $message }}</div>
                            @enderror

                            <div class="bb-estimate-sub">
                                EVE inventory, assets or contract copy (Ctrl+C); one item per line, as <code>1,000 x Tritanium</code>, <code>1000 Tritanium</code>, <code>Tritanium 1000</code> or <code>Tritanium x1000</code>;
                                or an EFT fit (loaded charges are not counted).
                            </div>

                            <div style="margin-top:14px; display:flex; gap:10px; flex-wrap:wrap;">
                                <button type="submit" class="bb-public-cta" style="border:none; cursor:pointer;">
                                    <i class="fas fa-calculator"></i> Calculate
                                </button>
                                {{-- Setting the value fires no input event, and the hamster beside the box listens for one. --}}
                                <button type="button" class="bb-public-cta bb-members-clear" onclick="var box = document.getElementById('items'); box.value = ''; box.dispatchEvent(new Event('input'));">
                                    <i class="fas fa-times"></i> Clear
                                </button>
                            </div>
                        </div>
                        @include('buyback-manager::partials.idle-hamster', ['for' => 'items', 'button' => 'Calculate'])
                    </div>
                </form>
            </div>

        </div>
    </div>
@endif
</div>

@if($setting)
<!-- Loading Modal -->
<div class="modal fade" id="loadingModal" data-backdrop="static" data-keyboard="false" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-body py-4">
                @include('buyback-manager::partials.hamsters', ['id' => 'bb-hamsters'])
            </div>
        </div>
    </div>
</div>

@push('javascript')
<script src="{{ asset('vendor/buyback-manager/js/hamsters.js') }}?v=2"></script>
<script>
$(document).ready(function () {
    const form = document.getElementById('appraisal-form');
    const hamsters = BuybackHamsters.create(document.getElementById('bb-hamsters'));
    // Relative, so the page's scheme is always the one used.
    const prepareUrl = "{{ route('buyback.appraisal.prepare', [], false) }}";
    let sending = false;

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        if (sending) {
            return;
        }
        sending = true;

        hamsters.start();
        $('#loadingModal').appendTo('body').modal('show');

        const corporationId = document.getElementById('corporation_id').value;
        BuybackHamsters.waitForPrices({
            prepareUrl: prepareUrl,
            waitUrl: function (token) {
                return prepareUrl + '/' + token + '?corporation_id=' + encodeURIComponent(corporationId);
            },
            body: new FormData(form),
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            onWaiting: function (count, text) {
                hamsters.sub(text);
            }
        }).then(function () {
            // The submit event does not fire again for this.
            form.submit();
        });
    });

    // Back from the result, the browser may show this page as it was left:
    // with the modal up and the form locked.
    window.addEventListener('pageshow', function (event) {
        if (event.persisted) {
            sending = false;
            hamsters.stop();
            $('#loadingModal').modal('hide');
        }
    });
});
</script>
@endpush
@endif
@endsection
