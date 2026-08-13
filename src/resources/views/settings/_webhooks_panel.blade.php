{{--
    Inline webhook management panel. Rendered inside the settings page's
    Discord Webhooks tab. Form field IDs are wh_-prefixed to avoid
    colliding with the corporation-settings form on the same page (the
    NAME attributes stay canonical because each form POSTs separately).

    Expects (from SettingsController::index):
      $webhooks, $corporations, $allCategories, $recentLog,
      $roleProviderAvailable, $roleProviderLabel, $roleLookup
--}}

{{-- Existing webhooks --}}
<div class="card card-dark">
    <div class="card-header">
        <h3 class="card-title"><i class="fab fa-discord"></i> Configured webhooks</h3>
    </div>
    <div class="card-body">
        {{-- Colour legend. The same colours are used on the Discord embeds, so
             a category is recognisable in both places. --}}
        <div style="margin-bottom:12px; font-size:12px; color:#8b95a5;">
            <span style="margin-right:6px;">Categories:</span>
            @foreach(\BuybackManager\Models\BuybackWebhook::categoryMeta() as $catKey => $catInfo)
                <span class="bb-cat-badge"
                      title="{{ $catInfo['help'] }}"
                      style="background:{{ $catInfo['color'] }}22; color:{{ $catInfo['color'] }}; border-color:{{ $catInfo['color'] }}66;">
                    {{ $catInfo['label'] }}
                </span>
            @endforeach
        </div>
        @if($webhooks->isEmpty())
            <p class="text-muted" style="font-style:italic;">
                No webhooks configured yet. Use the form below to add one.
            </p>
        @else
            <table class="table table-bb-styled">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Scope</th>
                        <th>Categories</th>
                        <th>Mention</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($webhooks as $hook)
                        <tr>
                            <td>{{ $hook->name }}</td>
                            <td>
                                @if($hook->corporation_id)
                                    {{ $hook->corporation->name ?? 'Corp #' . $hook->corporation_id }}
                                @else
                                    <em>Global (all corps)</em>
                                @endif
                            </td>
                            <td>
                                @foreach($hook->categories ?? [] as $c)
                                    @php $bbCol = \BuybackManager\Models\BuybackWebhook::categoryColor($c); @endphp
                                    <span class="bb-cat-badge"
                                          style="background:{{ $bbCol }}22; color:{{ $bbCol }}; border-color:{{ $bbCol }}66;">
                                        {{ \BuybackManager\Models\BuybackWebhook::categoryLabel($c) }}
                                    </span>
                                @endforeach
                            </td>
                            <td>
                                @include('buyback-manager::settings._role_pill', [
                                    'desc' => \BuybackManager\Services\DiscordRoleResolver::describeRoleMention($hook->role_mention, $roleLookup ?? []),
                                ])
                            </td>
                            <td>
                                @if($hook->enabled)
                                    <span class="label label-success">Enabled</span>
                                @else
                                    <span class="label label-default">Disabled</span>
                                @endif
                            </td>
                            <td>
                                <form action="{{ route('buyback-manager.settings.webhooks.test', $hook->id) }}"
                                      method="POST" style="display:inline;">
                                    @csrf
                                    <input type="hidden" name="return_to" value="settings_webhooks">
                                    <button type="submit" class="btn btn-xs btn-info" title="Send a test event">
                                        <i class="fa fa-flask"></i>
                                    </button>
                                </form>
                                <form action="{{ route('buyback-manager.settings.webhooks.destroy', $hook->id) }}"
                                      method="POST" style="display:inline;"
                                      onsubmit="return confirm('Delete this webhook?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-xs btn-danger">
                                        <i class="fa fa-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>

{{-- Add webhook --}}
<div class="card card-dark">
    <div class="card-header">
        <h3 class="card-title"><i class="fa fa-plus"></i> Add webhook</h3>
    </div>
    <form action="{{ route('buyback-manager.settings.webhooks.store') }}" method="POST">
        @csrf
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <label for="wh_name">Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="wh_name" class="form-control"
                               placeholder="e.g. #buyback-director" required maxlength="100">
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label for="wh_corporation_id">Scope</label>
                        <select name="corporation_id" id="wh_corporation_id" class="form-control">
                            <option value="">Global (all corps)</option>
                            @foreach($corporations as $corp)
                                <option value="{{ $corp->corporation_id }}">{{ $corp->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </div>
            <div class="form-group">
                <label for="wh_url">Discord webhook URL <span class="text-danger">*</span></label>
                <input type="url" name="url" id="wh_url" class="form-control"
                       placeholder="https://discord.com/api/webhooks/..." required>
                <small class="text-muted">Only Discord webhooks accepted (https://discord.com/api/webhooks/...).</small>
            </div>
            <div class="form-group">
                <label for="wh_role_mention">Role / user mention</label>
                <div class="bb-role-input-group">
                    <input type="text" name="role_mention" id="wh_role_mention" class="form-control"
                           placeholder="Paste a role ID, <@&123...>, or <@456...>" maxlength="50">
                    @if($roleProviderAvailable)
                        <button type="button" class="btn btn-bb-secondary js-pick-role" title="Pick from Discord">
                            <i class="fas fa-hashtag"></i> Pick from Discord
                        </button>
                    @endif
                </div>
                <small class="text-muted">
                    Optional. Prepended to the embed as the message content.
                    @if($roleProviderAvailable)
                        Detected role source: <strong>{{ $roleProviderLabel }}</strong>.
                        Click <em>Pick from Discord</em> to choose from the merged role list.
                    @else
                        No Discord role provider detected (install SeAT Broadcast or warlof/seat-connector to enable the picker).
                    @endif
                    <br>
                    <strong>Bare numeric role IDs are auto-wrapped</strong> as <code>&lt;@&amp;ID&gt;</code>
                    on save. For user mentions, paste the full <code>&lt;@ID&gt;</code> form.
                </small>
            </div>
            <div class="form-group">
                <label>What should this webhook announce? <span class="text-danger">*</span></label>
                @php $bbCatMeta = \BuybackManager\Models\BuybackWebhook::categoryMeta(); @endphp
                @foreach(\BuybackManager\Models\BuybackWebhook::categoryGroups() as $box)
                    <div style="border:1px solid var(--bb-border, #2b3038); border-radius:8px; padding:12px 14px; margin-bottom:12px; background:var(--bb-dark-card, rgba(255,255,255,0.02));">
                        <div style="font-weight:600; color:var(--bb-text-light, #c5cdd8); margin-bottom:10px; padding-bottom:6px; border-bottom:1px solid var(--bb-border, #2b3038);">
                            <i class="fas {{ $box['icon'] }}" style="margin-right:6px; opacity:0.8;"></i> {{ $box['title'] }}
                        </div>
                        <div class="row">
                            @foreach($box['keys'] as $cat)
                                <div class="col-md-6">
                                    <div class="checkbox" style="margin-top:0;">
                                        <label>
                                            <input type="checkbox" name="categories[]" value="{{ $cat }}">
                                            <span class="bb-cat-badge"
                                                  style="background:{{ $bbCatMeta[$cat]['color'] }}22; color:{{ $bbCatMeta[$cat]['color'] }}; border-color:{{ $bbCatMeta[$cat]['color'] }}66;">
                                                {{ $bbCatMeta[$cat]['label'] ?? $cat }}
                                            </span>
                                        </label>
                                        <small class="d-block text-muted" style="margin-left:1.25rem;">
                                            {{ $bbCatMeta[$cat]['help'] ?? '' }}
                                        </small>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
                <small class="text-muted">
                    Most operators put <strong>Normal buyback flow</strong> on a director channel and
                    <strong>Needs a director</strong> on a separate review channel.
                </small>
            </div>
            <div class="checkbox">
                <label>
                    <input type="checkbox" name="enabled" value="1" checked>
                    Enabled
                </label>
            </div>
        </div>
        <div class="card-footer">
            <button type="submit" class="btn btn-bb-primary">
                <i class="fa fa-save"></i> Add webhook
            </button>
        </div>
    </form>
</div>

{{-- Recent dispatch log --}}
<div class="card card-dark">
    <div class="card-header">
        <h3 class="card-title"><i class="fa fa-history"></i> Recent dispatches</h3>
    </div>
    <div class="card-body">
        @if($recentLog->isEmpty())
            <p class="text-muted" style="font-style:italic;">
                No webhook dispatches yet. Trigger a test event via the flask icon above.
            </p>
        @else
            <table class="table table-bb-styled table-compact">
                <thead>
                    <tr>
                        <th>When</th>
                        <th>Webhook</th>
                        <th>Event</th>
                        <th>Status</th>
                        <th>Error</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($recentLog as $row)
                        @php
                            $statusClass = match($row->status) {
                                'sent' => 'success',
                                'rate_limited' => 'warning',
                                'failed' => 'danger',
                                default => 'default',
                            };
                        @endphp
                        <tr>
                            <td>{{ $row->sent_at }}</td>
                            <td>#{{ $row->webhook_id }}</td>
                            <td title="{{ $row->event_name }}">
                                @php $bbCol = \BuybackManager\Models\BuybackWebhook::eventColor($row->event_name); @endphp
                                <span class="bb-cat-badge"
                                      style="background:{{ $bbCol }}22; color:{{ $bbCol }}; border-color:{{ $bbCol }}66;">
                                    {{ \BuybackManager\Models\BuybackWebhook::eventLabel($row->event_name) }}
                                </span>
                            </td>
                            <td><span class="label label-{{ $statusClass }}">{{ $row->status }}</span></td>
                            <td><small class="text-muted">{{ \Illuminate\Support\Str::limit($row->error ?? '', 80) }}</small></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>
