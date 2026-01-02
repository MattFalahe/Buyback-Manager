@extends('web::layouts.grids.12')

@section('title', 'Buyback Manager - Settings')
@section('page_header', 'Buyback Settings')

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

        @if($errors->any())
            <div class="alert alert-danger alert-dismissible">
                <button type="button" class="close" data-dismiss="alert">&times;</button>
                <h4><i class="fa fa-exclamation-triangle"></i> Error!</h4>
                <ul>
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <!-- Existing Settings -->
        <div class="box box-primary">
            <div class="box-header with-border">
                <h3 class="box-title">Corporation Buyback Settings</h3>
            </div>
            <div class="box-body">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Corporation</th>
                            <th>Enabled</th>
                            <th>Base %</th>
                            <th>Price Source</th>
                            <th>Character</th>
                            <th>Pricing Rules</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($settings as $setting)
                            <tr>
                                <td>
                                    <img src="https://images.evetech.net/corporations/{{ $setting->corporation_id }}/logo?size=32" 
                                         style="width: 24px; height: 24px; vertical-align: middle; margin-right: 5px;">
                                    {{ $setting->corporation->name ?? 'Unknown' }}
                                </td>
                                <td>
                                    @if($setting->enabled)
                                        <span class="label label-success">Enabled</span>
                                    @else
                                        <span class="label label-default">Disabled</span>
                                    @endif
                                </td>
                                <td>{{ $setting->base_percentage }}%</td>
                                <td>{{ ucfirst($setting->price_source) }}</td>
                                <td>
                                    @if($setting->character_id)
                                        <img src="https://images.evetech.net/characters/{{ $setting->character_id }}/portrait?size=32" 
                                             style="width: 24px; height: 24px; vertical-align: middle; margin-right: 5px;">
                                    @else
                                        <span class="text-muted">Corporation</span>
                                    @endif
                                </td>
                                <td>
                                    <a href="{{ route('buyback-manager.settings.rules', $setting->id) }}" class="btn btn-xs btn-info">
                                        <i class="fa fa-cog"></i> {{ $setting->pricingRules->count() }} Rules
                                    </a>
                                </td>
                                <td>
                                    <button type="button" class="btn btn-xs btn-warning edit-setting-btn" 
                                            data-toggle="modal" 
                                            data-target="#editSettingModal"
                                            data-id="{{ $setting->id }}"
                                            data-corporation-id="{{ $setting->corporation_id }}"
                                            data-character-id="{{ $setting->character_id }}"
                                            data-enabled="{{ $setting->enabled }}"
                                            data-base-percentage="{{ $setting->base_percentage }}"
                                            data-price-source="{{ $setting->price_source }}"
                                            data-region-id="{{ $setting->region_id }}">
                                        <i class="fa fa-edit"></i>
                                    </button>
                                    <form action="{{ route('buyback-manager.settings.destroy', $setting->id) }}" 
                                          method="POST" 
                                          style="display: inline;"
                                          onsubmit="return confirm('Are you sure you want to delete this setting?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-xs btn-danger">
                                            <i class="fa fa-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center text-muted">No settings configured yet</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Add New Setting -->
        <div class="box box-success">
            <div class="box-header with-border">
                <h3 class="box-title">Add New Corporation Setting</h3>
            </div>
            <form action="{{ route('buyback-manager.settings.store') }}" method="POST">
                @csrf
                <div class="box-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="corporation_id">Corporation <span class="text-danger">*</span></label>
                                <select name="corporation_id" id="corporation_id" class="form-control" required>
                                    <option value="">-- Select Corporation --</option>
                                    @foreach($corporations as $corporation)
                                        <option value="{{ $corporation->corporation_id }}">
                                            {{ $corporation->name }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="character_id">Specific Character (Optional)</label>
                                <input type="number" name="character_id" id="character_id" class="form-control" 
                                       placeholder="Leave empty for corporation contracts">
                                <small class="text-muted">If specified, contracts must be issued to this character</small>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label for="base_percentage">Base Percentage <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <input type="number" name="base_percentage" id="base_percentage" 
                                           class="form-control" step="0.01" min="0" max="100" 
                                           value="90" required>
                                    <span class="input-group-addon">%</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label for="price_source">Price Source <span class="text-danger">*</span></label>
                                <select name="price_source" id="price_source" class="form-control" required>
                                    <option value="jita">Jita</option>
                                    <option value="region">Regional</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label for="region_id">Region ID (if Regional)</label>
                                <input type="number" name="region_id" id="region_id" class="form-control" 
                                       placeholder="e.g., 10000002">
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-12">
                            <div class="checkbox">
                                <label>
                                    <input type="checkbox" name="enabled" value="1" checked> 
                                    Enable buyback for this corporation
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="box-footer">
                    <button type="submit" class="btn btn-success">
                        <i class="fa fa-save"></i> Save Setting
                    </button>
                </div>
            </form>
        </div>

        <!-- Information Box -->
        <div class="box box-info">
            <div class="box-header with-border">
                <h3 class="box-title"><i class="fa fa-info-circle"></i> How It Works</h3>
            </div>
            <div class="box-body">
                <ol>
                    <li><strong>Configure Corporation Settings:</strong> Set the base buyback percentage and price source</li>
                    <li><strong>Add Pricing Rules:</strong> Create category, group, or item-specific pricing rules</li>
                    <li><strong>Sync Contracts:</strong> The system automatically syncs item exchange contracts every 15 minutes</li>
                    <li><strong>Appraisal:</strong> Users can appraise their items before creating contracts</li>
                    <li><strong>Tracking:</strong> All contracts are tracked with full item details and values</li>
                </ol>
            </div>
        </div>
    </div>

    <!-- Edit Setting Modal -->
    <div class="modal fade" id="editSettingModal" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <form action="{{ route('buyback-manager.settings.store') }}" method="POST">
                    @csrf
                    <div class="modal-header">
                        <button type="button" class="close" data-dismiss="modal">&times;</button>
                        <h4 class="modal-title">Edit Buyback Setting</h4>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="corporation_id" id="edit_corporation_id">
                        
                        <div class="form-group">
                            <label for="edit_character_id">Specific Character (Optional)</label>
                            <input type="number" name="character_id" id="edit_character_id" class="form-control">
                        </div>

                        <div class="form-group">
                            <label for="edit_base_percentage">Base Percentage</label>
                            <div class="input-group">
                                <input type="number" name="base_percentage" id="edit_base_percentage" 
                                       class="form-control" step="0.01" min="0" max="100" required>
                                <span class="input-group-addon">%</span>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="edit_price_source">Price Source</label>
                            <select name="price_source" id="edit_price_source" class="form-control" required>
                                <option value="jita">Jita</option>
                                <option value="region">Regional</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="edit_region_id">Region ID (if Regional)</label>
                            <input type="number" name="region_id" id="edit_region_id" class="form-control">
                        </div>

                        <div class="checkbox">
                            <label>
                                <input type="checkbox" name="enabled" id="edit_enabled" value="1"> 
                                Enable buyback
                            </label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@push('javascript')
    <script src="{{ asset('web/js/buyback-manager/buyback-manager.js') }}"></script>
    <script>
        // Edit button handler
        $('.edit-setting-btn').on('click', function() {
            $('#edit_corporation_id').val($(this).data('corporation-id'));
            $('#edit_character_id').val($(this).data('character-id'));
            $('#edit_base_percentage').val($(this).data('base-percentage'));
            $('#edit_price_source').val($(this).data('price-source'));
            $('#edit_region_id').val($(this).data('region-id'));
            $('#edit_enabled').prop('checked', $(this).data('enabled') == 1);
        });

        // Show/hide region_id based on price_source
        $('#price_source, #edit_price_source').on('change', function() {
            const regionInput = $(this).attr('id') === 'price_source' ? '#region_id' : '#edit_region_id';
            if ($(this).val() === 'region') {
                $(regionInput).closest('.form-group').show();
            } else {
                $(regionInput).closest('.form-group').hide();
            }
        }).trigger('change');
    </script>
@endpush
