{{--
    Resolved-role pill (BB port of SM's _role_pill).

    Expects:
      $desc — result of DiscordRoleResolver::describeRoleMention(), or null
              for an empty / unset mention.
    Inherited from the parent view:
      $roleProviderAvailable — bool, whether any Discord role source exists.
--}}
@php($rp_hasProvider = $roleProviderAvailable ?? false)
@if(empty($desc))
    <span style="color:#8b95a5; font-style:italic; font-size:0.85rem;">No mention</span>
@elseif(!empty($desc['known']))
    <span class="bb-role-pill" title="Discord role ID {{ $desc['id'] }}">
        @if(!empty($desc['color']) && preg_match('/^#[0-9a-f]{6}$/i', $desc['color']))
            <span class="bb-role-color-dot" style="background:{{ $desc['color'] }};"></span>
        @endif
        <span>{{ '@' . ($desc['name'] ?: ('Role ' . $desc['id'])) }}</span>
    </span>
@elseif(($desc['kind'] ?? '') === 'user')
    <span class="bb-role-pill is-user" title="{{ $desc['raw'] }}">
        <i class="fas fa-user"></i>
        <span>User mention{{ !empty($desc['id']) ? ' (' . $desc['id'] . ')' : '' }}</span>
    </span>
@elseif(($desc['kind'] ?? '') === 'role')
    @if($rp_hasProvider)
        <span class="bb-role-pill is-unknown" title="{{ $desc['raw'] }}">
            <i class="fas fa-question-circle"></i>
            <span>Role {{ $desc['id'] }} (not in any installed role list)</span>
        </span>
    @else
        <code style="font-size:0.75rem;">{{ $desc['raw'] }}</code>
    @endif
@else
    <span class="bb-role-pill is-unknown" title="{{ $desc['raw'] }}">
        <i class="fas fa-exclamation-triangle"></i>
        <span>Unrecognized (will not ping)</span>
    </span>
@endif
