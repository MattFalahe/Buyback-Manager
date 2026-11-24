{{-- The hamsters who fetch the prices. Pass id, and out => true with a message when no provider answered. --}}
@php
    $bbOut = ! empty($out);
    $bbClass = 'bb-hamsters' . ($bbOut ? ' bb-hamsters--out' : '');
    $bbLine = $bbOut ? 'The hamsters are out of coffee.' : 'Hamsters are calling CCP…';
    $bbHidden = ! empty($hidden) ? 'hidden' : '';
    $bbOutMark = $bbOut ? 'data-hamsters-out' : '';
    // The wait picks its scene each time it starts, so it carries all four.
    // A page that is already out of coffee is drawn once and needs only one.
    $bbScenes = ['server', 'call', 'space', 'trading'];
    if ($bbOut) {
        $bbScenes = [$bbScenes[array_rand($bbScenes)]];
    }
@endphp
<div class="{{ $bbClass }}" id="{{ $id }}" role="status" aria-live="polite" {{ $bbOutMark }} {{ $bbHidden }}>
    <div class="bb-hamsters__stage">
        @foreach($bbScenes as $bbScene)
            <div class="bb-hamsters__scene" data-bb-scene="{{ $bbScene }}" {{ $bbOut ? '' : 'hidden' }}>
                @if($bbScene === 'server') @include('buyback-manager::partials.hamster-scenes.server') @endif
                @if($bbScene === 'call') @include('buyback-manager::partials.hamster-scenes.call') @endif
                @if($bbScene === 'space') @include('buyback-manager::partials.hamster-scenes.space') @endif
                @if($bbScene === 'trading') @include('buyback-manager::partials.hamster-scenes.trading') @endif
            </div>
        @endforeach
    </div>
    <div class="bb-hamsters__line" data-hamster-line>{{ $bbLine }}</div>
    <div class="bb-hamsters__sub" data-hamster-sub>{{ $message ?? '' }}</div>
</div>
