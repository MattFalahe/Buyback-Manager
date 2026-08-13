@extends('web::layouts.grids.12')

@section('title', 'Buyback Manager - Pricing Rules')
@section('page_header', 'Pricing Rules - ' . ($setting->corporation->name ?? 'Unknown'))

@push('head')
    @include("buyback-manager::settings._settings_styles")
    <link rel="stylesheet" href="{{ asset('vendor/buyback-manager/css/buyback-manager.css') }}?v=1">
@endpush

@section('content')
    <div class="buyback-manager-wrapper">
        @if(session('success'))
            <div class="alert alert-success alert-dismissible">
                <button type="button" class="close" data-dismiss="alert">&times;</button>
                <i class="fa fa-check"></i> {{ session('success') }}
            </div>
        @endif

        <!-- Back Button -->
        <div class="card card-dark">
            <div class="card-body">
                <a href="{{ route('buyback-manager.settings.index') }}" class="btn btn-bb-secondary">
                    <i class="fa fa-arrow-left"></i> Back to Settings
                </a>
            </div>
        </div>

        <!-- 1. Default rate -->
        <div class="card card-dark">
            <div class="card-header">
                <h3 class="card-title"><i class="fa fa-percent"></i> Default rate for all items</h3>
                <div class="card-tools">
                    @if($setting->buy_listed_only)
                        <span class="badge badge-warning">Listed items only</span>
                    @else
                        <span class="badge badge-info">Buying everything at {{ $setting->base_percentage }}%</span>
                    @endif
                </div>
            </div>
            <form action="{{ route('buyback-manager.settings.rules.defaults', $setting->id) }}" method="POST">
                @csrf
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label for="base_percentage">Default rate</label>
                                <div class="input-group" style="max-width:200px;">
                                    <input type="number" name="base_percentage" id="base_percentage"
                                           class="form-control" step="0.01" min="0" max="100" required
                                           value="{{ old('base_percentage', $setting->base_percentage) }}">
                                    <span class="input-group-addon">%</span>
                                </div>
                                <small class="text-muted">Paid for anything without a price exception below.</small>
                            </div>
                        </div>
                        <div class="col-md-8">
                            <div class="checkbox" style="margin-top:25px;">
                                <label>
                                    <input type="checkbox" name="buy_listed_only" id="buy_listed_only" value="1"
                                           {{ old('buy_listed_only', $setting->buy_listed_only) ? 'checked' : '' }}>
                                    <strong>Buy only the items listed as price exceptions</strong>
                                </label>
                            </div>
                            <small class="text-muted">
                                Turns the programme into an allow list. The default rate is ignored, only items with a
                                price exception below are bought, and everything else is reported back to the seller
                                as not accepted instead of being quoted.
                            </small>
                        </div>
                    </div>
                </div>
                <div class="card-footer">
                    <button type="submit" class="btn btn-bb-primary">
                        <i class="fa fa-save"></i> Save default rate
                    </button>
                </div>
            </form>
        </div>

        <!-- 2. Price exceptions -->
        <div class="card card-dark">
            <div class="card-header">
                <h3 class="card-title"><i class="fa fa-sliders-h"></i> Price exceptions</h3>
                <div class="card-tools">
                    <span class="badge badge-info">{{ $exceptions->count() }}</span>
                </div>
            </div>
            <div class="card-body">
                <p class="text-muted">Items, groups or categories bought at a rate other than the default.</p>
                @forelse($exceptions as $rule)
                    @include('buyback-manager::settings._rule_row', ['rule' => $rule])
                @empty
                    @if($setting->buy_listed_only)
                        <div class="alert alert-warning" style="margin-bottom:0;">
                            <i class="fa fa-exclamation-triangle"></i>
                            <strong>Nothing is being bought.</strong> The programme is set to buy only listed items,
                            but no price exceptions are configured, so every appraisal will come back empty.
                        </div>
                    @else
                        <p class="text-muted text-center" style="margin-bottom:0;">
                            No exceptions. Everything is bought at the default rate.
                        </p>
                    @endif
                @endforelse
            </div>
        </div>

        <!-- 3. Buyback exclusions -->
        <div class="card card-dark">
            <div class="card-header">
                <h3 class="card-title"><i class="fa fa-ban"></i> Buyback exclusions</h3>
                <div class="card-tools">
                    <span class="badge badge-danger">{{ $exclusions->count() }}</span>
                </div>
            </div>
            <div class="card-body">
                <p class="text-muted">Never bought, whatever the default rate is. Sellers are told these are not accepted.</p>
                @forelse($exclusions as $rule)
                    @include('buyback-manager::settings._rule_row', ['rule' => $rule])
                @empty
                    <p class="text-muted text-center" style="margin-bottom:0;">
                        No exclusions configured.
                    </p>
                @endforelse
            </div>
        </div>

        <!-- Add New Rule -->
        <div class="card card-dark">
            <div class="card-header">
                <h3 class="card-title"><i class="fa fa-plus-circle"></i> Add a rule</h3>
            </div>
            <form action="{{ route('buyback-manager.settings.rules.store', $setting->id) }}" method="POST">
                @csrf
                <div class="card-body">
                    <p class="text-muted">
                        Set a percentage to add a <strong>price exception</strong>, or tick
                        <strong>Do not buy this</strong> to add a <strong>buyback exclusion</strong>. The rule lands in
                        the matching section above.
                    </p>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label for="type">Rule Type <span class="text-danger">*</span></label>
                                <select name="type" id="type" class="form-control" required>
                                    <option value="">-- Select Type --</option>
                                    <option value="category">Category</option>
                                    <option value="group">Group</option>
                                    <option value="item">Specific Item</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label for="type_id">Select <span class="text-danger">*</span></label>
                                <select name="type_id" id="type_id" class="form-control" required disabled>
                                    <option value="">-- Select type first --</option>
                                </select>
                                <small class="text-muted" id="type_id_hint" style="display: none;">
                                    For specific items, paste the EVE type ID directly.
                                </small>
                                <input type="number" name="type_id_manual" id="type_id_manual"
                                       class="form-control" placeholder="EVE type ID" style="display: none; margin-top: 5px;">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label for="percentage">Percentage</label>
                                <div class="input-group">
                                    <input type="number" name="percentage" id="percentage" 
                                           class="form-control" step="0.01" min="0" max="100" 
                                           placeholder="Leave empty to exclude">
                                    <span class="input-group-addon">%</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-8">
                            <div class="form-group">
                                <label for="price_side">Apply % to which side?</label>
                                <select name="price_side" id="price_side" class="form-control">
                                    <option value="">Default (use corp's setting-wide preference)</option>
                                    <option value="buy">Buy (max buy order)</option>
                                    <option value="sell">Sell (min sell order)</option>
                                    <option value="split">Split (average of buy &amp; sell)</option>
                                </select>
                                <small class="text-muted">
                                    The plugin pulls live buy &amp; sell prices, then applies your %
                                    to <strong>this</strong> side. e.g. <code>90% of sell</code> for ore vs
                                    <code>80% of buy</code> for modules. Mix policies per rule.
                                </small>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="checkbox" style="margin-top: 25px;">
                                <label>
                                    <input type="checkbox" name="excluded" id="excluded" value="1">
                                    Do not buy this
                                </label>
                            </div>
                            <div class="checkbox">
                                <label>
                                    <input type="checkbox" name="featured" id="featured" value="1">
                                    Feature on public page (most wanted)
                                </label>
                            </div>
                        </div>
                    </div>
                    {{-- Priority is auto-assigned by SettingsController::storeRule
                         (item=30, group=20, category=10) to enforce the documented
                         precedence "item > group > category". Custom priorities
                         were dropped from the UI in the v1.0.0 audit because they
                         could violate that precedence silently. The column is
                         retained for forward-compat. --}}
                </div>
                <div class="card-footer">
                    <button type="submit" class="btn btn-bb-primary">
                        <i class="fa fa-plus"></i> Add Rule
                    </button>
                </div>
            </form>
        </div>

        <!-- Help Box -->
        <div class="card card-dark">
            <div class="card-header">
                <h3 class="card-title"><i class="fa fa-info-circle"></i> How rules work</h3>
            </div>
            <div class="card-body">
                <p>Each rule has three inputs:</p>
                <ol>
                    <li>
                        <strong>Target</strong>: a specific item, a group of items, or an entire category.
                    </li>
                    <li>
                        <strong>Percentage</strong>: the rate the corp pays for matched items.
                    </li>
                    <li>
                        <strong>Price side</strong>: which side of the market spread the % applies to.
                        <em>buy</em> (max buy order), <em>sell</em> (min sell order), or
                        <em>split</em> (average of both). Default means "use the corp's setting-wide preference."
                    </li>
                </ol>
                <p>Rules are applied in this fixed priority order (highest first):</p>
                <ol>
                    <li><strong>Item-specific rules</strong></li>
                    <li><strong>Group rules</strong></li>
                    <li><strong>Category rules</strong></li>
                    <li><strong>Default rate</strong> (used when nothing above matches)</li>
                </ol>
                <div class="alert alert-info" style="margin-top:1rem;">
                    <strong>Example mix:</strong> Ore + minerals at <code>95% of sell</code>,
                    modules at <code>80% of buy</code>, datacores at <code>90% of split</code>.
                    Each rule independently picks its side; the percentage is then applied to that
                    side's live market value (the plugin always fetches both sides regardless).
                </div>

                <h4>The two ways to run a programme</h4>
                <table class="plugin-info-table">
                    <tr>
                        <td><strong>Buy everything</strong><br><span class="text-muted">default</span></td>
                        <td>Every item is bought at the default rate. Price exceptions change the rate for some of them; exclusions carve out what you refuse.</td>
                    </tr>
                    <tr>
                        <td><strong>Buy only listed items</strong></td>
                        <td>An allow list. The default rate is ignored and only items with a price exception are bought. Everything else is reported to the seller as not accepted, so nothing is ever quoted at a rate you did not choose.</td>
                    </tr>
                </table>

                <p class="text-muted">Exclusions always win: an excluded item is never accepted, whichever mode you run and whatever other rules match it.</p>
            </div>
        </div>
    </div>
@endsection

@push('javascript')
    <script src="{{ asset('vendor/buyback-manager/js/buyback-manager.js') }}"></script>
    <script>
        // Pre-rendered option lists, keeps the select populated without extra AJAX.
        const categoryOptions = [
            @foreach($categories as $category)
                { id: {{ $category->categoryID }}, name: @json($category->categoryName) },
            @endforeach
        ];
        const groupOptions = [
            @foreach($groups as $group)
                { id: {{ $group->groupID }}, name: @json($group->groupName) },
            @endforeach
        ];

        $('#type').on('change', function() {
            const type = $(this).val();
            const typeIdSelect = $('#type_id');
            const manualInput = $('#type_id_manual');
            const hint = $('#type_id_hint');

            typeIdSelect.empty().prop('disabled', true).show().prop('name', 'type_id');
            manualInput.hide().val('').prop('name', 'type_id_manual').prop('required', false);
            hint.hide();

            if (!type) {
                typeIdSelect.append('<option value="">-- Select type first --</option>');
                return;
            }

            typeIdSelect.append('<option value="">-- Select --</option>');

            if (type === 'category') {
                categoryOptions.forEach(o => {
                    typeIdSelect.append(`<option value="${o.id}">${$('<div>').text(o.name).html()}</option>`);
                });
                typeIdSelect.prop('disabled', false);
            } else if (type === 'group') {
                groupOptions.forEach(o => {
                    typeIdSelect.append(`<option value="${o.id}">${$('<div>').text(o.name).html()}</option>`);
                });
                typeIdSelect.prop('disabled', false);
            } else if (type === 'item') {
                // SDE has 30k+ types — render a numeric input instead of a 30k-row dropdown.
                typeIdSelect.hide().prop('disabled', true).removeAttr('name');
                manualInput.show().prop('name', 'type_id').prop('required', true);
                hint.show();
            }
        });

        // Excluded checkbox handler — disable percentage AND price_side
        // for excluded rules (they're meaningless when the rule rejects
        // the item entirely).
        $('#excluded').on('change', function() {
            const checked = $(this).is(':checked');
            $('#percentage').prop('disabled', checked).val(checked ? '' : $('#percentage').val());
            $('#price_side').prop('disabled', checked);
            if (checked) {
                $('#price_side').val('');
            }
        });

        // The default rate is meaningless in allow-list mode, so grey it out
        // rather than leaving a live-looking field that does nothing.
        // readonly, not disabled: a disabled input is not submitted, and the
        // field is still required by the save.
        function bbSyncDefaultRateState() {
            var listedOnly = $('#buy_listed_only').is(':checked');
            $('#base_percentage')
                .prop('readonly', listedOnly)
                .css('opacity', listedOnly ? 0.5 : 1);
        }
        $('#buy_listed_only').on('change', bbSyncDefaultRateState);
        bbSyncDefaultRateState();
    </script>
@endpush
