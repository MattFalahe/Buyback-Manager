{{-- Whether the button can work yet. Read once per page by the controller. --}}
@php
    $oigProfile = $openInGame['profile'] ?? null;
    $oigSsoUrl = \Illuminate\Support\Facades\Route::has('seatcore::configuration.sso') ? route('seatcore::configuration.sso') : null;
@endphp
<small class="d-block bb-oig-status">
    @if(! ($openInGame['listed'] ?? false))
        <i class="fa fa-exclamation-triangle text-warning"></i>
        SeAT does not offer <code>esi-ui.open_window.v1</code> yet. Restart SeAT so it reads Buyback Manager's addition to its scope list.
    @elseif($oigProfile === null || ! $oigProfile['asks'])
        <i class="fa fa-exclamation-triangle text-warning"></i>
        SeAT's default scope profile{{ $oigProfile ? ' (' . $oigProfile['name'] . ')' : '' }} does not ask for <code>esi-ui.open_window.v1</code>.
        A SeAT admin adds it under
        @if($oigSsoUrl)
            <a href="{{ $oigSsoUrl }}">Settings &gt; SSO</a>.
        @else
            Settings &gt; SSO.
        @endif
        Buyback Manager never changes your scope profiles.
    @else
        <i class="fa fa-check text-success"></i>
        SeAT asks for it. {{ number_format($openInGame['characters'] ?? 0) }} {{ ($openInGame['characters'] ?? 0) === 1 ? 'character holds' : 'characters hold' }} it so far;
        anyone else logs out of SeAT and back in with their main, then links any alt again.
    @endif
</small>
