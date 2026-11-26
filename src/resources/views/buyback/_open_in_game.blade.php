{{-- Copy the contract ID and, where the programme has the feature on, open the contract in the game client. --}}
@php
    $oigOn = isset($oig['corporations'][(int) $contract->corporation_id]);
    $oigCharacters = $oig['characters'] ?? [];
    $oigFirst = array_key_first($oigCharacters);
    $oigUrl = route('buyback-manager.contracts.open-in-game', $contract->id);
    $oigLabel = ! empty($withLabel);
@endphp
<span class="bb-oig text-nowrap" data-token="{{ csrf_token() }}">
    <button type="button" class="btn btn-xs btn-bb-secondary copy-contract-value" data-value="{{ $contract->contract_id }}" data-toggle="tooltip" title="Copy contract ID">
        <i class="fa fa-copy"></i>
    </button>
    @if($oigOn)
        @if($oigFirst === null)
            <span class="d-inline-block" tabindex="0" data-toggle="tooltip"
                  title="{{ ($oig['profile_asks'] ?? false) ? 'Your login does not include esi-ui.open_window.v1 yet. Log out of SeAT and back in with your main.' : 'SeAT does not ask for esi-ui.open_window.v1 yet. A SeAT admin adds it under Settings > SSO.' }}">
                <button type="button" class="btn btn-xs btn-default bb-oig-missing" disabled style="pointer-events: none;">
                    <i class="fa fa-external-link-alt"></i>
                    <i class="fa fa-exclamation-triangle text-warning"></i>
                    @if($oigLabel)
                        Open in game
                    @endif
                </button>
            </span>
        @elseif(count($oigCharacters) === 1)
            <button type="button" class="btn btn-xs btn-bb-primary bb-open-in-game" data-url="{{ $oigUrl }}" data-character="{{ $oigFirst }}"
                    data-toggle="tooltip" title="Open in game as {{ $oigCharacters[$oigFirst] }}">
                <i class="fa fa-external-link-alt"></i>
                @if($oigLabel)
                    Open in game
                @endif
            </button>
        @else
            <span class="btn-group">
                <button type="button" class="btn btn-xs btn-bb-primary dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" title="Open in game">
                    <i class="fa fa-external-link-alt"></i>
                    @if($oigLabel)
                        Open in game
                    @endif
                </button>
                <div class="dropdown-menu">
                    <h6 class="dropdown-header">Open in game as</h6>
                    @foreach($oigCharacters as $oigCharacterId => $oigName)
                        <a href="#" class="dropdown-item bb-open-in-game" data-url="{{ $oigUrl }}" data-character="{{ $oigCharacterId }}">{{ $oigName }}</a>
                    @endforeach
                </div>
            </span>
        @endif
        <small class="bb-oig-result"></small>
    @endif
</span>
