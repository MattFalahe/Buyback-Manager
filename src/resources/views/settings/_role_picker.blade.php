{{--
    Discord role-picker modal + JS. Include once per page that has a
    role_mention input with a .js-pick-role trigger button.

    Expects:
      $roleProviderAvailable — only renders the modal when a provider exists.
--}}
@if($roleProviderAvailable)
<div class="modal fade" id="rolePickerModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content" style="background:#2a2f3a; border:1px solid #454d55;">
            <div class="modal-header">
                <h5 class="modal-title" style="color:#fff;">Pick Discord Role</h5>
                <button type="button" class="close" data-dismiss="modal"><span style="color:#fff;">&times;</span></button>
            </div>
            <div class="modal-body" id="rolePickerBody">
                <div class="text-center" style="padding:1rem;"><i class="fas fa-spinner fa-spin"></i> Loading...</div>
            </div>
        </div>
    </div>
</div>

@push('javascript')
<script>
(function ($) {
    'use strict';

    const ROUTES = {
        listRoles: @json(route('buyback-manager.settings.webhooks.roles')),
    };

    let activeRoleTarget = null;

    $(document).on('click', '.js-pick-role', function () {
        const $form = $(this).closest('form, .form-group').first();
        activeRoleTarget = $form.find('input[name="role_mention"]').first();
        openRolePicker();
    });

    function openRolePicker() {
        const $modal = $('#rolePickerModal');
        if (!$modal.length) {
            return;
        }
        const $body = $('#rolePickerBody');
        $body.html('<div class="text-center" style="padding:1rem;"><i class="fas fa-spinner fa-spin"></i> Loading...</div>');
        $modal.modal('show');

        $.getJSON(ROUTES.listRoles, function (res) {
            if (!res.roles || res.roles.length === 0) {
                $body.html(`
                    <div class="alert alert-warning">
                        <strong>No roles returned from ${res.label || 'provider'}.</strong><br>
                        Enter the mention manually as <code>&lt;@&amp;ROLE_ID&gt;</code> or raw role ID.
                    </div>`);
                return;
            }

            const perSource = {};
            res.roles.forEach(function (r) {
                perSource[r.source] = (perSource[r.source] || 0) + 1;
            });
            const sourceLabels = {
                'discord-roles-table':  'SeAT Broadcast',
                'seat-connector':       'SeAT Connector',
                'warlof-discord':       'Warlof (legacy)',
            };
            const sourceColors = {
                'discord-roles-table':  '#28a745',
                'seat-connector':       '#3498db',
                'warlof-discord':       '#95a5a6',
            };
            const badgeStyle = 'color:#000; font-weight:700; font-size:0.7rem; padding:2px 6px;';

            let html = '<div style="max-height:460px; overflow-y:auto;">';
            html += '<div style="font-size:0.78rem; color:#8b95a5; margin-bottom:0.5rem;">';
            html += `${res.roles.length} unique role(s) from ${Object.keys(perSource).length} source(s): `;
            html += Object.keys(perSource).map(function (s) {
                return `<span class="badge" style="background:${sourceColors[s]||'#666'}; ${badgeStyle} margin-left:3px;">${sourceLabels[s]||s}: ${perSource[s]}</span>`;
            }).join(' ');
            html += '</div>';

            html += '<div style="display:flex; gap:0.4rem; margin-bottom:0.8rem;">';
            html += '<input type="text" id="roleFilter" class="form-control" placeholder="Search roles..." style="background:#1e222b; border:1px solid #454d55; color:#fff; flex-grow:1;">';
            if (Object.keys(perSource).length > 1) {
                html += '<select id="sourceFilter" class="form-control" style="background:#1e222b; border:1px solid #454d55; color:#fff; max-width:180px;">';
                html += '<option value="">All sources</option>';
                Object.keys(perSource).forEach(function (s) {
                    html += `<option value="${s}">${sourceLabels[s]||s}</option>`;
                });
                html += '</select>';
            }
            html += '</div>';

            html += '<div id="roleList" style="display:flex; flex-wrap:wrap; gap:4px;">';
            res.roles.forEach(function (r) {
                const hex = r.color && /^#[0-9a-f]{6}$/i.test(r.color) ? r.color : '';
                const dot = hex
                    ? `<span style="display:inline-block; width:10px; height:10px; border-radius:50%; background:${hex}; margin-right:6px; vertical-align:middle;"></span>`
                    : '';
                const format = (r.mention_format || ('<@&' + r.id + '>')).replace(/"/g, '&quot;');
                const primarySrc = r.source;
                const alsoIn = (r.sources || []).filter(s => s !== primarySrc);
                const primaryBadge = `<span class="badge" style="background:${sourceColors[primarySrc]||'#666'}; color:#000; font-weight:700; font-size:0.65rem; padding:2px 6px; margin-left:4px; vertical-align:middle;">${sourceLabels[primarySrc]||primarySrc}</span>`;
                const extraBadge = alsoIn.length > 0
                    ? `<span class="badge badge-secondary" style="color:#fff; font-weight:600; font-size:0.65rem; padding:2px 6px; margin-left:2px;" title="Also in: ${alsoIn.map(s => sourceLabels[s]||s).join(', ')}">+${alsoIn.length}</span>`
                    : '';
                html += `<button type="button" class="btn btn-sm btn-outline-primary js-role-pick-btn"
                    data-role-id="${r.id}"
                    data-role-name="${r.name}"
                    data-mention-format="${format}"
                    data-source="${primarySrc}"
                    style="text-align:left;">
                    ${dot}${r.name}
                    <small style="opacity:0.55; margin-left:4px;">#${r.id.slice(-6)}</small>
                    ${primaryBadge}${extraBadge}
                </button>`;
            });
            html += '</div></div>';
            $body.html(html);

            const applyFilter = function () {
                const textV = ($('#roleFilter').val() || '').toLowerCase();
                const srcV  = $('#sourceFilter').val() || '';
                $('#roleList .js-role-pick-btn').each(function () {
                    const n = ($(this).data('role-name') + ' ' + $(this).data('role-id')).toLowerCase();
                    const s = $(this).data('source');
                    const matchesText = n.includes(textV);
                    const matchesSrc = !srcV || s === srcV;
                    $(this).toggle(matchesText && matchesSrc);
                });
            };
            $('#roleFilter').on('input', applyFilter);
            $('#sourceFilter').on('change', applyFilter);
        }).fail(function () {
            $body.html('<div class="alert alert-danger">Failed to load roles from Discord provider(s).</div>');
        });
    }

    $(document).on('click', '.js-role-pick-btn', function () {
        const mentionFormat = $(this).data('mention-format') || ('<@&' + $(this).data('role-id') + '>');
        if (activeRoleTarget) {
            activeRoleTarget.val(mentionFormat).trigger('blur');
        }
        $('#rolePickerModal').modal('hide');
    });

})(jQuery);
</script>
@endpush
@endif
