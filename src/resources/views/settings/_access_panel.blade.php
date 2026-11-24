{{--
  Programme Access: who manages and who views each programme.
  Expects $settings, $access (by setting id, from ProgrammeAccess::entries),
  $roleOptions and $managesEveryCorporation. Only an admin changes it; a
  manager sees who is named on their own programmes.
--}}
<div class="card card-dark">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-user-shield"></i> Programme Access</h3>
    </div>
    <div class="card-body">
        <p>
            Each programme names who runs it and who watches it. A <strong>manager</strong> changes its settings,
            pricing rules, locations, webhooks and public page, and sees its contracts. A <strong>viewer</strong>
            sees its contracts, Statistics and Analytics. Name a SeAT account, or a whole SeAT role so everyone
            holding it counts.
        </p>
        <ul class="text-muted" style="margin-bottom:1rem;">
            <li>A manager also needs <em>{{ trans('buyback-manager::permissions.settings') }}</em>, and a viewer <em>{{ trans('buyback-manager::permissions.view') }}</em>. A name without it is marked below.</li>
            <li>Having a character in the corporation counts for nothing, so someone in your corporation can run a buyback under their alt corporation without reaching yours.</li>
            <li>A programme with nobody named is run and seen by admins alone.</li>
            @if(! $managesEveryCorporation)
                <li>Only a Buyback Manager admin names people here.</li>
            @endif
        </ul>

        @if($managesEveryCorporation && $settings->isNotEmpty())
            <form method="POST" id="bbAccessForm" class="bb-access-form"
                  data-action-template="{{ route('buyback-manager.settings.access.store', ['setting_id' => '__SETTING__']) }}">
                @csrf
                <div class="row">
                    <div class="col-md-3">
                        <div class="form-group">
                            <label for="acc_setting">Programme</label>
                            <select id="acc_setting" class="form-control">
                                @foreach($settings as $setting)
                                    <option value="{{ $setting->id }}">{{ $setting->corporation->name ?? 'Corporation #' . $setting->corporation_id }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label for="acc_access">As</label>
                            <select name="access" id="acc_access" class="form-control">
                                <option value="manager">Manager</option>
                                <option value="viewer">Viewer</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label for="acc_type">Who</label>
                            <select name="subject_type" id="acc_type" class="form-control">
                                <option value="user">SeAT account</option>
                                <option value="role">SeAT role</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group bb-acc-user">
                            <label for="acc_search">Account</label>
                            <input type="text" id="acc_search" class="form-control" autocomplete="off"
                                   placeholder="Any of its characters' names">
                            <div id="acc_results" style="position:relative;"></div>
                            <small id="acc_selected" class="text-muted" style="display:none;"></small>
                        </div>
                        <div class="form-group bb-acc-role" style="display:none;">
                            <label for="acc_role">Role</label>
                            <select id="acc_role" class="form-control">
                                @forelse($roleOptions as $roleId => $roleTitle)
                                    <option value="{{ $roleId }}">{{ $roleTitle }}</option>
                                @empty
                                    <option value="">No SeAT roles yet</option>
                                @endforelse
                            </select>
                        </div>
                        <input type="hidden" name="subject_id" id="acc_subject_id">
                    </div>
                    <div class="col-md-1">
                        <div class="form-group">
                            <label>&nbsp;</label>
                            <button type="submit" id="acc_add" class="btn btn-bb-primary btn-block" disabled>Add</button>
                        </div>
                    </div>
                </div>
            </form>
        @endif

        <table class="table table-bb-styled">
            <thead>
                <tr>
                    <th style="width:24%;">Programme</th>
                    <th>Managers</th>
                    <th>Viewers</th>
                </tr>
            </thead>
            <tbody>
                @forelse($settings as $setting)
                    @php $named = collect($access[$setting->id] ?? []); @endphp
                    <tr>
                        <td>
                            <img src="https://images.evetech.net/corporations/{{ $setting->corporation_id }}/logo?size=32"
                                 style="width: 24px; height: 24px; vertical-align: middle; margin-right: 5px;">
                            {{ $setting->corporation->name ?? 'Corporation #' . $setting->corporation_id }}
                        </td>
                        @foreach(['manager', 'viewer'] as $kind)
                            <td>
                                @forelse($named->where('access', $kind) as $entry)
                                    @php
                                        $isRole = $entry['subject_type'] === 'role';
                                        $problem = null;
                                        if ($entry['missing']) {
                                            $problem = $isRole ? 'This role is no longer in SeAT.' : 'This account is no longer in SeAT.';
                                        } elseif ($entry['lacks']) {
                                            $needs = trans('buyback-manager::permissions.' . \Illuminate\Support\Str::after($entry['lacks'], 'buyback-manager.'));
                                            $problem = ($isRole ? 'This role does not carry ' : 'This account does not hold ') . $needs . ', so this does nothing yet.';
                                        }
                                    @endphp
                                    <span class="bb-role-pill {{ $isRole ? '' : 'is-user' }} {{ $problem ? 'is-unknown' : '' }}"
                                          style="margin:0 0.3rem 0.3rem 0;" title="{{ $problem ?? ($isRole ? 'SeAT role' : 'SeAT account') }}">
                                        <i class="fas {{ $isRole ? 'fa-users' : 'fa-user' }}"></i>
                                        {{ $entry['name'] }}
                                        @if($problem)
                                            <i class="fas fa-exclamation-triangle"></i>
                                        @endif
                                        @if($managesEveryCorporation)
                                            <form method="POST" action="{{ route('buyback-manager.settings.access.destroy', [$setting->id, $entry['id']]) }}"
                                                  style="display:inline;" onsubmit="return confirm('Remove this name from the programme?');">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-link btn-xs p-0" style="color:inherit;" title="Remove">&times;</button>
                                            </form>
                                        @endif
                                    </span>
                                    @if($problem)
                                        <div class="text-warning" style="font-size:0.8rem; margin-bottom:0.3rem;">{{ $entry['name'] }}: {{ $problem }}</div>
                                    @endif
                                @empty
                                    <span class="text-muted">{{ $kind === 'manager' ? 'Nobody: admins only' : 'Nobody' }}</span>
                                @endforelse
                            </td>
                        @endforeach
                    </tr>
                @empty
                    <tr>
                        <td colspan="3" class="text-center text-muted">
                            {{ $managesEveryCorporation ? 'No programmes yet. Add one under Configured Corps first.' : 'You are not named a manager of any programme yet.' }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@if($managesEveryCorporation && $settings->isNotEmpty())
    @push('javascript')
    <script>
        (function () {
            var searchUrl = @json(route('buyback-manager.settings.access.accounts'));
            var $form = $('#bbAccessForm');
            var $type = $('#acc_type');
            var $search = $('#acc_search');
            var $results = $('#acc_results');
            var $selected = $('#acc_selected');
            var $role = $('#acc_role');
            var $subject = $('#acc_subject_id');
            var $add = $('#acc_add');
            var picked = '';
            var timer = null;

            function sync() {
                var isRole = $type.val() === 'role';
                $form.find('.bb-acc-user').toggle(!isRole);
                $form.find('.bb-acc-role').toggle(isRole);
                $subject.val(isRole ? ($role.val() || '') : picked);
                $add.prop('disabled', !$subject.val());
            }

            function render(accounts) {
                $results.empty();
                if (!accounts.length) {
                    $results.append('<div class="text-muted" style="padding:6px 0;">No account has a character by that name.</div>');
                    return;
                }
                var $list = $('<div class="list-group" style="position:absolute; z-index:20; width:100%; max-height:240px; overflow:auto;"></div>');
                accounts.forEach(function (account) {
                    var $item = $('<a href="#" class="list-group-item list-group-item-action" style="background:#1e222b; border-color:#454d55; color:#fff;"></a>');
                    $item.text(account.name + (account.matched ? ' (' + account.matched + ')' : ''));
                    $item.on('click', function (e) {
                        e.preventDefault();
                        picked = String(account.id);
                        $search.val(account.name);
                        $selected.show().html('Account of <strong>' + $('<span>').text(account.name).html() + '</strong>');
                        $results.empty();
                        sync();
                    });
                    $list.append($item);
                });
                $results.append($list);
            }

            $search.on('input', function () {
                picked = '';
                $selected.hide().text('');
                sync();
                clearTimeout(timer);
                var q = $search.val().trim();
                if (q.length < 2) { $results.empty(); return; }
                timer = setTimeout(function () {
                    $.getJSON(searchUrl, { q: q })
                        .done(render)
                        .fail(function () { $results.html('<div class="text-danger" style="padding:6px 0;">Search failed.</div>'); });
                }, 250);
            });

            $type.on('change', sync);
            $role.on('change', sync);

            $form.on('submit', function () {
                $form.attr('action', $form.data('action-template').replace('__SETTING__', $('#acc_setting').val()));
            });

            sync();
        })();
    </script>
    @endpush
@endif
