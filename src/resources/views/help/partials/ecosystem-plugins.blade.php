{{-- Every plugin Buyback Manager works with and whether this server has it:
     the crucial ones, a divider, then the optional ones. --}}
@php
    $about = [
        'manager-core' => [
            'icon' => 'fa-network-wired',
            'url' => 'https://github.com/MattFalahe/Manager-Core',
            'text' => 'The hub of the plugin ecosystem. It carries events and data between the plugins, so each one can use what the others know, and keeps one set of prices they all share.',
            'here' => 'It can be picked as the price provider or the fallback, for any market added there, citadels included, through a provider each corporation picks. Every contract Buyback Manager matches, flags, completes or cancels is published to its event feed, which is how other plugins hear of a buyback. Buyback Manager needs Manager Core 1.2.0 or later.',
        ],
        'hr-manager' => [
            'icon' => 'fa-users',
            'url' => 'https://github.com/MattFalahe/HR-Manager',
            'text' => 'Follows your members and applicants: who is active, who has gone quiet, and what each one gives back to the corporation.',
            'here' => 'Each completed buyback counts towards the seller\'s contribution. HR Manager hears of buybacks through Manager Core, so it needs Manager Core as well.',
        ],
        'seat-broadcast' => [
            'icon' => 'fa-bullhorn',
            'url' => 'https://github.com/MattFalahe/SeAT-Discord-Pings',
            'text' => 'Sends pings and broadcasts from SeAT to Discord, and keeps a list of your Discord roles with their colours.',
            'here' => 'The Discord Webhooks tab lists those roles to pick from for a webhook\'s mention. SeAT Connector does the same, so one of the two is enough.',
        ],
        'seat-connector' => [
            'icon' => 'fa-plug',
            'url' => 'https://github.com/zenobio93/seat-connector',
            'text' => 'Links SeAT to your Discord server and knows its roles.',
            'here' => 'The Discord Webhooks tab lists those roles to pick from for a webhook\'s mention. SeAT Broadcast does the same, so one of the two is enough, and the older Warlof Discord connector works too.',
        ],
    ];
    $groups = [
        'crucial' => ['Crucial', 'Needed for full functionality.'],
        'optional' => ['Optional', 'Each adds to one feature. Buyback Manager works without them.'],
    ];
    $inGroup = array_count_values(array_column($ecosystem['plugins'], 'role'));
@endphp
<div class="help-card">
    <h3><i class="fas fa-puzzle-piece"></i> Plugins &amp; Ecosystem</h3>
    <p>Buyback Manager works on its own. These plugins add to it, and each one says whether this server has it, checked when this page opens.</p>

    @foreach($groups as $role => [$title, $line])
        @if(!$loop->first)
            <hr class="eco-divider">
        @endif
        <h4 class="eco-group">{{ $title }}</h4>
        <p class="eco-group-line">{{ ($inGroup[$role] ?? 0) > 0 ? $line : 'None.' }}</p>
        @foreach($ecosystem['plugins'] as $key => $plugin)
            @continue($plugin['role'] !== $role)
            <div class="eco-plugin">
                <div class="eco-plugin-head">
                    <i class="fas {{ $about[$key]['icon'] }}"></i>
                    <strong>{{ $plugin['name'] }}</strong>
                    <span class="eco-badges">
                        @foreach($plugin['badges'] as $badge)
                            <span class="badge badge-{{ $badge['tone'] }}{{ $badge['pulse'] ? ' eco-pulse' : '' }}">{{ $badge['label'] }}</span>
                        @endforeach
                    </span>
                </div>
                <p>{{ $about[$key]['text'] }}</p>
                <p><strong>In Buyback Manager:</strong> {{ $about[$key]['here'] }}</p>
                @if(!empty($plugin['note']))
                    <p class="eco-note">{{ $plugin['note'] }}</p>
                @endif
                <a href="{{ $about[$key]['url'] }}" target="_blank" rel="noopener" class="eco-link"><i class="fab fa-github"></i> {{ $plugin['name'] }} on GitHub</a>
            </div>
        @endforeach
    @endforeach
</div>
