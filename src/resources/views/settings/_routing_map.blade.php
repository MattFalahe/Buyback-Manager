{{--
    Notification Routing Map (BB port of SM's read-only routing view).

    For each notification category, shows which enabled webhooks fire and
    the role each pings. Read-only — there's nothing to configure here,
    it's the resolved answer to "if X happens, who gets pinged?".

    Expects (from SettingsController::index):
      $routingMap            — category => [description, events, webhooks]
      $roleProviderAvailable
      $roleLookup
--}}
<div class="card card-dark">
    <div class="card-header">
        <h3 class="card-title"><i class="fa fa-project-diagram"></i> Notification Routing Map</h3>
    </div>
    <div class="card-body">
        <p class="text-muted">
            Read-only view of where each buyback event goes. A category fires to every
            <strong>enabled</strong> webhook subscribed to it (corp-scoped webhooks only fire for their
            corp; global webhooks fire for all). Categories with no subscriber are silent.
        </p>

        @php
            $silentCount = collect($routingMap)->filter(fn($r) => $r['webhooks']->isEmpty())->count();
        @endphp
        @if($silentCount > 0)
            <div class="alert alert-warning">
                <i class="fa fa-exclamation-triangle"></i>
                <strong>{{ $silentCount }}</strong> categor{{ $silentCount === 1 ? 'y has' : 'ies have' }} no webhook subscribed.
                Those events fire to nobody. Add a webhook in the Discord Webhooks tab to cover them.
            </div>
        @endif

        <table class="table table-bb-styled">
            <thead>
                <tr>
                    <th style="width:22%;">Category</th>
                    <th style="width:28%;">Triggers</th>
                    <th>Fires to</th>
                </tr>
            </thead>
            <tbody>
                @foreach($routingMap as $category => $row)
                    <tr class="{{ $row['webhooks']->isEmpty() ? '' : '' }}">
                        <td>
                            <strong>{{ $row['label'] ?? $category }}</strong>
                            <br><small class="text-muted">{{ $row['description'] }}</small>
                        </td>
                        <td>
                            @foreach($row['events'] as $ev)
                                <div><small style="color:#8b95a5;"><code style="font-size:0.72rem;">{{ $ev }}</code></small></div>
                            @endforeach
                        </td>
                        <td>
                            @if($row['webhooks']->isEmpty())
                                <span class="label label-warning"><i class="fa fa-volume-mute"></i> Nobody (silent)</span>
                            @else
                                @foreach($row['webhooks'] as $wh)
                                    <div style="margin-bottom:0.4rem; display:flex; align-items:center; gap:0.5rem; flex-wrap:wrap;">
                                        <span style="font-weight:600;">{{ $wh->name }}</span>
                                        @if($wh->corporation_id)
                                            <span class="label label-info" style="font-size:0.7rem;">{{ $wh->corporation->name ?? ('Corp #' . $wh->corporation_id) }}</span>
                                        @else
                                            <span class="label label-default" style="font-size:0.7rem;">Global</span>
                                        @endif
                                        @include('buyback-manager::settings._role_pill', [
                                            'desc' => \BuybackManager\Services\DiscordRoleResolver::describeRoleMention($wh->role_mention, $roleLookup ?? []),
                                        ])
                                    </div>
                                @endforeach
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
