{{-- The hamster beside the paste box. Pass for => the textarea's id, and button => the name on the button that sends it. --}}
@php
    $bbButton = $button ?? 'Calculate';
@endphp
<div class="bb-hamsters bb-idle" data-hamster-idle="{{ $for }}" data-button="{{ $bbButton }}">
    <div class="bb-hamsters__stage">
        <div class="bb-hamsters__scene" data-bb-idle="counter" hidden>
            @include('buyback-manager::partials.idle-hamsters.counter')
        </div>
        <div class="bb-hamsters__scene" data-bb-idle="scales" hidden>
            @include('buyback-manager::partials.idle-hamsters.scales')
        </div>
        <div class="bb-hamsters__scene" data-bb-idle="bay" hidden>
            @include('buyback-manager::partials.idle-hamsters.bay')
        </div>
        <div class="bb-hamsters__scene" data-bb-idle="vend" hidden>
            @include('buyback-manager::partials.idle-hamsters.vend')
        </div>
    </div>
    <div class="bb-hamsters__line" data-hamster-line>Paste your loot. We'll make an offer.</div>
    <div class="bb-hamsters__sub" data-hamster-sub>Paste in the box: inventory, contract or EFT fit.</div>
</div>
