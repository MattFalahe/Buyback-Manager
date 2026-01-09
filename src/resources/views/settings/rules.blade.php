@extends('web::layouts.grids.12')

@section('title', 'Buyback Manager - Pricing Rules')
@section('page_header', 'Pricing Rules - ' . ($setting->corporation->name ?? 'Unknown'))

@push('head')
    <link rel="stylesheet" href="{{ asset('web/css/buyback-manager/buyback-manager.css') }}">
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
        <div class="box box-default">
            <div class="box-body">
                <a href="{{ route('buyback-manager.settings.index') }}" class="btn btn-default">
                    <i class="fa fa-arrow-left"></i> Back to Settings
                </a>
            </div>
        </div>

        <!-- Current Rules -->
        <div class="box box-primary">
            <div class="box-header with-border">
                <h3 class="box-title">Current Pricing Rules</h3>
                <div class="box-tools">
                    <span class="label label-info">Base: {{ $setting->base_percentage }}%</span>
                </div>
            </div>
            <div class="box-body">
                @forelse($setting->pricingRules()->orderBy('priority', 'desc')->get() as $rule)
                    <div class="pricing-rule {{ $rule->excluded ? 'excluded' : '' }}">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <div>
                                <span class="pricing-rule-type">{{ strtoupper($rule->type) }}</span>
                                <strong>
                                    @if($rule->type === 'category')
                                        {{ \Seat\Eveapi\Models\Sde\InvCategory::find($rule->type_id)->categoryName ?? 'Unknown' }}
                                    @elseif($rule->type === 'group')
                                        {{ \Seat\Eveapi\Models\Sde\InvGroup::find($rule->type_id)->groupName ?? 'Unknown' }}
                                    @else
                                        {{ \Seat\Eveapi\Models\Sde\InvType::find($rule->type_id)->typeName ?? 'Unknown' }}
                                    @endif
                                </strong>
                                @if($rule->excluded)
                                    <span class="label label-danger">EXCLUDED</span>
                                @else
                                    <span class="pricing-rule-percentage">{{ $rule->percentage }}%</span>
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
        <div class="box box-success">
            <div class="box-header with-border">
                <h3 class="box-title">Add New Pricing Rule</h3>
            </div>
            <form action="{{ route('buyback-manager.settings.rules.store', $setting->id) }}" method="POST">
                @csrf
                <div class="box-body">
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
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="priority">Priority (Optional)</label>
                                <input type="number" name="priority" id="priority" class="form-control" 
                                       placeholder="Auto-assigned based on type">
                                <small class="text-muted">Higher priority rules override lower priority</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="checkbox" style="margin-top: 25px;">
                                <label>
                                    <input type="checkbox" name="excluded" id="excluded" value="1"> 
                                    Exclude this item/category/group from buyback
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="box-footer">
                    <button type="submit" class="btn btn-success">
                        <i class="fa fa-plus"></i> Add Rule
                    </button>
                </div>
            </form>
        </div>

        <!-- Help Box -->
        <div class="box box-info">
            <div class="box-header with-border">
                <h3 class="box-title"><i class="fa fa-info-circle"></i> Rule Priority</h3>
            </div>
            <div class="box-body">
                <p>Pricing rules are applied in the following order (highest to lowest priority):</p>
                <ol>
                    <li><strong>Item-specific rules</strong> (Priority: 30)</li>
                    <li><strong>Group rules</strong> (Priority: 20)</li>
                    <li><strong>Category rules</strong> (Priority: 10)</li>
                    <li><strong>Base percentage</strong> (Default)</li>
                </ol>
                <p class="text-muted">If an item is marked as excluded, it will not be accepted in buyback contracts regardless of other rules.</p>
            </div>
        </div>
    </div>
@endsection

@push('javascript')
    <script src="{{ asset('web/js/buyback-manager/buyback-manager.js') }}"></script>
    <script>
        // Type selector handler
        $('#type').on('change', function() {
            const type = $(this).val();
            const typeIdSelect = $('#type_id');
            
            typeIdSelect.empty().prop('disabled', true);
            
            if (!type) {
                typeIdSelect.append('<option value="">-- Select type first --</option>');
                return;
            }

            typeIdSelect.append('<option value="">Loading...</option>');

            // Fetch options based on type
            let url = '';
            if (type === 'category') {
                url = '/api/sde/categories';
            } else if (type === 'group') {
                url = '/api/sde/groups';
            } else if (type === 'item') {
                url = '/api/sde/types';
            }

            // Note: You'll need to create these API endpoints or use existing ones
            // For now, we'll use the database directly via AJAX to SeAT's existing endpoints
            
            typeIdSelect.empty();
            typeIdSelect.append('<option value="">-- Select --</option>');
            
            @if(isset($categories))
                if (type === 'category') {
                    @foreach($categories as $category)
                        typeIdSelect.append('<option value="{{ $category->categoryID }}">{{ $category->categoryName }}</option>');
                    @endforeach
                }
            @endif
            
            @if(isset($groups))
                if (type === 'group') {
                    @foreach($groups as $group)
                        typeIdSelect.append('<option value="{{ $group->groupID }}">{{ $group->groupName }}</option>');
                    @endforeach
                }
            @endif
            
            typeIdSelect.prop('disabled', false);
        });

        // Excluded checkbox handler
        $('#excluded').on('change', function() {
            if ($(this).is(':checked')) {
                $('#percentage').prop('disabled', true).val('');
            } else {
                $('#percentage').prop('disabled', false);
            }
        });
    </script>
@endpush
