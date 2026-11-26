{{-- Shown only while Open in game is on here and none of the user's logins can use it. --}}
@if(! empty($oig['corporations']) && empty($oig['characters']))
    <div class="alert alert-warning bb-oig-banner">
        <i class="fa fa-exclamation-triangle"></i>
        <strong>Open in game</strong> needs <code>esi-ui.open_window.v1</code> on your SeAT login.
        @if($oig['profile_asks'] ?? false)
            Log out of SeAT and log back in with your main. If you play an alt, link it again afterwards.
        @else
            SeAT does not ask for it yet, so ask a SeAT admin to add it to the default scope profile under Settings &gt; SSO.
        @endif
    </div>
@endif
