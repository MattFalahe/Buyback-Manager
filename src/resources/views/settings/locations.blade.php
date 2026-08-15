@extends('web::layouts.grids.12')

@section('title', 'Buyback Manager - Locations')
@section('page_header', 'Buyback Locations - ' . ($setting->corporation->name ?? 'Unknown'))

@push('head')
    @include("buyback-manager::settings._settings_styles")
    <link rel="stylesheet" href="{{ asset('vendor/buyback-manager/css/buyback-manager.css') }}?v=4">
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
        <div class="alert alert-danger">
            <ul style="margin:0; padding-left:18px;">
                @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    <div class="card card-dark">
        <div class="card-body">
            <a href="{{ route('buyback-manager.settings.index') }}" class="btn btn-bb-secondary">
                <i class="fa fa-arrow-left"></i> Back to Settings
            </a>
        </div>
    </div>

    <div class="card card-dark">
        <div class="card-header">
            <h3 class="card-title">Allowed Buyback Locations</h3>
        </div>
        <div class="card-body">
            <p class="text-muted">
                Contracts are accepted only when created at one of these locations. A region or
                constellation covers everything inside it. <strong>Leave this empty to accept contracts anywhere.</strong>
            </p>

            @forelse($rules as $rule)
                <div class="pricing-rule" style="display:flex; justify-content:space-between; align-items:center;">
                    <div>
                        <span class="pricing-rule-type">{{ strtoupper($rule->location_type) }}</span>
                        <strong>{{ $rule->location_name ?: ('#' . $rule->location_id) }}</strong>
                        <span class="text-muted" style="font-size: 11px;">(id {{ $rule->location_id }})</span>
                    </div>
                    <form action="{{ route('buyback-manager.settings.locations.destroy', [$setting->id, $rule->id]) }}"
                          method="POST" style="display:inline;"
                          onsubmit="return confirm('Remove this location?');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-xs btn-danger"><i class="fa fa-trash"></i> Remove</button>
                    </form>
                </div>
            @empty
                <p class="text-muted text-center">No location restrictions. Buyback is accepted at any location.</p>
            @endforelse
        </div>
    </div>

    <div class="card card-dark">
        <div class="card-header">
            <h3 class="card-title">Add a Location</h3>
        </div>
        <form action="{{ route('buyback-manager.settings.locations.store', $setting->id) }}" method="POST" id="addLocationForm">
            @csrf
            <input type="hidden" name="location_id" id="loc_id">
            <input type="hidden" name="location_name" id="loc_name">
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4">
                        <div class="form-group">
                            <label for="loc_type">Type <span class="text-danger">*</span></label>
                            <select id="loc_type" name="location_type" class="form-control">
                                <option value="region">Region</option>
                                <option value="constellation">Constellation</option>
                                <option value="system">System</option>
                                <option value="station">Station (NPC)</option>
                                <option value="structure">Structure (Citadel)</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-8">
                        <div class="form-group">
                            <label for="loc_search">Search by name <span class="text-danger">*</span></label>
                            <input type="text" id="loc_search" class="form-control" autocomplete="off"
                                   placeholder="Type at least 2 characters...">
                            <div id="loc_results" style="position:relative;"></div>
                            <div id="loc_selected" class="text-muted" style="margin-top:6px; display:none;"></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card-footer">
                <button type="submit" class="btn btn-bb-primary" id="loc_add_btn" disabled>
                    <i class="fa fa-plus"></i> Add Location
                </button>
            </div>
        </form>
    </div>

    <div class="card card-dark">
        <div class="card-header">
            <h3 class="card-title"><i class="fa fa-info-circle"></i> How location rules work</h3>
        </div>
        <div class="card-body">
            <ul>
                <li>A contract's location is resolved to its station/structure, system, constellation and region.</li>
                <li>It is accepted if it matches <strong>any</strong> entry above &mdash; so a single region entry covers every station and citadel inside it.</li>
                <li>Contracts created anywhere else are still tracked, but <strong>flagged for review</strong> with a wrong-location reason so a director can decline them in game rather than paying out.</li>
                <li>The allowed locations are shown on the public page and on every appraisal, so sellers know where to contract before they haul.</li>
            </ul>
        </div>
    </div>
</div>
@endsection

@push('javascript')
<script>
    (function () {
        var searchUrl = "{{ route('buyback-manager.settings.locations.search') }}";
        var $type = $('#loc_type');
        var $search = $('#loc_search');
        var $results = $('#loc_results');
        var $selected = $('#loc_selected');
        var $id = $('#loc_id');
        var $name = $('#loc_name');
        var $btn = $('#loc_add_btn');
        var timer = null;

        function clearSelection() {
            $id.val('');
            $name.val('');
            $selected.hide().text('');
            $btn.prop('disabled', true);
        }

        function renderResults(items) {
            $results.empty();
            if (!items.length) {
                $results.append('<div class="text-muted" style="padding:6px 0;">No matches.</div>');
                return;
            }
            var $list = $('<div class="list-group" style="position:absolute; z-index:20; width:100%; max-height:240px; overflow:auto;"></div>');
            items.forEach(function (it) {
                var $a = $('<a href="#" class="list-group-item list-group-item-action" style="background:#1e222b; border-color:#454d55; color:#fff;"></a>');
                $a.text(it.name + ' (id ' + it.id + ')');
                $a.on('click', function (e) {
                    e.preventDefault();
                    $id.val(it.id);
                    $name.val(it.name);
                    $search.val(it.name);
                    $selected.show().html('Selected: <strong>' + $('<span>').text(it.name).html() + '</strong>');
                    $btn.prop('disabled', false);
                    $results.empty();
                });
                $list.append($a);
            });
            $results.append($list);
        }

        function doSearch() {
            var q = $search.val().trim();
            clearSelection();
            if (q.length < 2) { $results.empty(); return; }
            $.getJSON(searchUrl, { type: $type.val(), q: q })
                .done(renderResults)
                .fail(function () { $results.html('<div class="text-danger" style="padding:6px 0;">Search failed.</div>'); });
        }

        $search.on('keyup', function () {
            clearTimeout(timer);
            timer = setTimeout(doSearch, 300);
        });
        $type.on('change', function () { $search.val(''); $results.empty(); clearSelection(); });
    })();
</script>
@endpush
