@extends('web::layouts.grids.12')

@section('title', 'Buyback Manager - Pricing Rules')
@section('page_header', 'Pricing Rules - ' . ($setting->corporation->name ?? 'Unknown'))

@push('head')
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

        <!-- Current Rules -->
        <div class="card card-dark">
            <div class="card-header">
                <h3 class="card-title">Current Pricing Rules</h3>
                <div class="card-tools">
                    <span class="badge badge-info">Base: {{ $setting->base_percentage }}%</span>
                </div>
            </div>
            <div class="card-body">
                @forelse($setting->pricingRules()->orderBy('priority', 'desc')->get() as $rule)
                    <div class="pricing-rule {{ $rule->excluded ? 'excluded' : '' }}">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <div>
                                <span class="pricing-rule-type">{{ strtoupper($rule->type) }}</span>
                                <strong>
                                    @if($rule->type === 'category')
                                        {{ $categoryNames[$rule->type_id] ?? 'Unknown' }}
                                    @elseif($rule->type === 'group')
                                        {{ $groupNames[$rule->type_id] ?? 'Unknown' }}
                                    @else
                                        {{ $typeNames[$rule->type_id] ?? 'Unknown' }}
                                    @endif
                                </strong>
                                @if($rule->excluded)
                                    <span class="badge badge-danger">EXCLUDED</span>
                                @else
                                    <span class="pricing-rule-percentage">{{ $rule->percentage }}%</span>
                                    @php
                                        $sideLabel = match($rule->price_side) {
                                            'buy' => 'of buy price',
                                            'sell' => 'of sell price',
                                            'split' => 'of split (buy+sell ÷ 2)',
                                            default => 'of default side',
                                        };
                                        $sideBadge = match($rule->price_side) {
                                            'buy' => 'info',
                                            'sell' => 'primary',
                                            'split' => 'warning',
                                            default => 'default',
                                        };
                                    @endphp
                                    <span class="badge badge-{{ $sideBadge }}">{{ $sideLabel }}</span>
                                @endif
                                @if($rule->featured)
                                    <span class="badge badge-warning"><i class="fa fa-star"></i> Most wanted</span>
                                @endif
                                <span class="text-muted" style="font-size: 11px;">(Priority: {{ $rule->priority }})</span>
                            </div>
                            <div>
                                <form action="{{ route('buyback-manager.settings.rules.destroy', [$setting->id, $rule->id]) }}" 
                                      method="POST" 
                                      style="display: inline;"
                                      onsubmit="return confirm('Delete this pricing rule?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-xs btn-danger">
                                        <i class="fa fa-trash"></i> Delete
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                @empty
                    <p class="text-muted text-center">No custom pricing rules configured. Base percentage will be used for all items.</p>
                @endforelse
            </div>
        </div>

        <!-- Add New Rule -->
        <div class="card card-dark">
            <div class="card-header">
                <h3 class="card-title">Add New Pricing Rule</h3>
            </div>
            <form action="{{ route('buyback-manager.settings.rules.store', $setting->id) }}" method="POST">
                @csrf
                <div class="card-body">
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
                                    Exclude from buyback
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
                    <li><strong>Base percentage</strong> (corp default, uses the setting-wide price side)</li>
                </ol>
                <div class="alert alert-info" style="margin-top:1rem;">
                    <strong>Example mix:</strong> Ore + minerals at <code>95% of sell</code>,
                    modules at <code>80% of buy</code>, datacores at <code>90% of split</code>.
                    Each rule independently picks its side; the percentage is then applied to that
                    side's live market value (the plugin always fetches both sides regardless).
                </div>
                <p class="text-muted">If an item is marked as excluded, it will not be accepted in buyback contracts regardless of other rules.</p>
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
    </script>
@endpush
