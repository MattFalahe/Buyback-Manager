{{-- Features that need another plugin get boxes of their own, so a missing
     plugin shows on the feature it takes away. Red: a crucial plugin is
     missing. Yellow: only an optional one is missing. --}}
@php
    $hub = $ecosystem['hub'];
    $boxes = [
        'mc-pricing' => [
            'icon' => 'fa-coins',
            'title' => 'Pricing through Manager Core',
            'text' => 'Price at any market added in Manager Core, citadels included, as the provider or the fallback, through a provider each corporation picks there. The items your rules name are priced from the start, and a market an admin sets for Buyback Manager in its Pricing Preferences wins over the corporation\'s own.',
            'without' => 'Until then, quotes are priced by Fuzzwork or Janice.',
        ],
        'hr-credit' => [
            'icon' => 'fa-user-check',
            'title' => 'Buybacks credited in HR Manager',
            'text' => 'Every completed buyback counts towards the seller\'s contribution in HR Manager, on its Economy tab and the player\'s profile.',
            'without' => 'Until then, completed contracts are kept in Buyback Manager\'s own history only.',
        ],
        'role-names' => [
            'icon' => 'fa-user-tag',
            'title' => 'Roles picked by name',
            'text' => 'The Discord Webhooks tab lists your Discord roles to pick from for a webhook\'s mention, and the webhook list and routing map show each role by its name.',
            'without' => 'Until then, type a role as its ID.',
        ],
    ];
@endphp
<h4 class="eco-heading"><i class="fas fa-project-diagram"></i> With other plugins</h4>
<p>These need another plugin. <a href="#plugins" data-goto="plugins">Plugins &amp; Ecosystem</a> says what each one adds and whether this server has it.</p>
<div class="feature-grid">
    <div class="feature-item eco-box eco-hub eco-{{ $hub['state'] }}{{ $hub['state'] !== 'on' ? ' eco-pulse' : '' }}">
        @if($hub['banner'])
            <span class="eco-banner">{{ $hub['banner'] }}</span>
        @endif
        <i class="fas fa-network-wired"></i>
        <h5>Manager Core: The Ecosystem Era</h5>
        <p>Manager Core links the plugins: what happens in one reaches the others, one can ask another for what it knows, and they share prices instead of each fetching its own.</p>
        @if($hub['coming_soon'])
            <p class="eco-note">Buyback Manager does not connect to it yet. It works on its own today, and a later release brings it into the ecosystem.</p>
        @elseif(!$hub['installed'])
            <p class="eco-note">{{ $hub['note'] ?? 'Manager Core is not installed on this server.' }}</p>
        @endif
    </div>
    @foreach($boxes as $key => $box)
        @php($state = $ecosystem['features'][$key])
        <div class="feature-item eco-box eco-{{ $state['state'] }}{{ $state['state'] !== 'on' ? ' eco-pulse' : '' }}">
            @if($state['banner'])
                <span class="eco-banner">{{ $state['banner'] }}</span>
            @endif
            <i class="fas {{ $box['icon'] }}"></i>
            <h5>{{ $box['title'] }}</h5>
            <p>{{ $box['text'] }}</p>
            @if($state['state'] === 'on')
                <span class="badge badge-success eco-tag">With {{ $state['using'] }}</span>
            @else
                <p class="eco-note">Needs {{ $state['needs'] }}. {{ $box['without'] }}</p>
            @endif
        </div>
    @endforeach
</div>
