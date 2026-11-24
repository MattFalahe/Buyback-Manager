@extends('web::layouts.grids.12')

@section('title', 'Buyback Appraisal')
@section('page_header', 'Buyback Appraisal')

@push('head')
    <link rel="stylesheet" href="{{ asset('vendor/buyback-manager/css/buyback-manager.css') }}?v=6">
    <link rel="stylesheet" href="{{ asset('vendor/buyback-manager/css/hamsters.css') }}?v=4">
@endpush

@section('full')
<div class="buyback-manager-wrapper">
<div class="card card-dark">
    <div class="card-header">
        <h3 class="card-title">
            <i class="fas fa-calculator"></i> Buyback Appraisal
        </h3>
    </div>
    <div class="card-body">
        @if(session('no_prices'))
            @include('buyback-manager::partials.hamsters', ['id' => 'bb-hamsters-out', 'out' => true, 'message' => session('no_prices')])
        @endif

        <form method="POST" action="{{ route('buyback.appraisal.create') }}" id="appraisal-form">
            @csrf

            <div class="form-group">
                <label for="corporation_id">Select Corporation</label>
                <select name="corporation_id" id="corporation_id" class="form-control @error('corporation_id') is-invalid @enderror" required>
                    <option value="">-- Select Corporation --</option>
                    @foreach($corporations as $corpId => $corpName)
                        <option value="{{ $corpId }}" {{ old('corporation_id') == $corpId ? 'selected' : '' }}>
                            {{ $corpName }}
                        </option>
                    @endforeach
                </select>
                @error('corporation_id')
                    <span class="invalid-feedback">{{ $message }}</span>
                @enderror
            </div>

            <div class="form-group">
                <label for="items">Paste Items</label>
                <div class="bb-paste-row">
                    <div>
                        <textarea
                            name="items"
                            id="items"
                            class="form-control @error('items') is-invalid @enderror"
                            rows="15"
                            placeholder="Paste your items here...&#10;&#10;Supported formats:&#10;• EVE inventory or contract (Ctrl+C)&#10;• Item Name    Quantity&#10;• EFT fit"
                            required
                        >{{ old('items') }}</textarea>
                        @error('items')
                            <span class="invalid-feedback">{{ $message }}</span>
                        @enderror

                        <small class="form-text text-muted">
                            <strong>Supported formats:</strong>
                            <ul class="mb-0">
                                <li>EVE inventory, assets or contract copy (Ctrl+C)</li>
                                <li>One item per line: <code>1,000 x Tritanium</code>, <code>1000 Tritanium</code>, <code>Tritanium 1000</code> or <code>Tritanium x1000</code></li>
                                <li>An EFT fit: the ship, its modules, drones and cargo (loaded charges are not counted)</li>
                            </ul>
                        </small>
                    </div>
                    @include('buyback-manager::partials.idle-hamster', ['for' => 'items', 'button' => 'Calculate'])
                </div>
            </div>

            <div class="form-group">
                <button type="submit" class="btn btn-bb-primary btn-lg">
                    <i class="fas fa-calculator"></i> Calculate Buyback Price
                </button>
                {{-- Setting the value fires no input event, and the hamster beside the box listens for one. --}}
                <button type="button" class="btn btn-bb-secondary btn-lg" onclick="var box = document.getElementById('items'); box.value = ''; box.dispatchEvent(new Event('input'));">
                    <i class="fas fa-times"></i> Clear
                </button>
            </div>
        </form>
    </div>
</div>
</div>

<!-- Loading Modal -->
<div class="modal fade" id="loadingModal" data-backdrop="static" data-keyboard="false" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-body py-4">
                @include('buyback-manager::partials.hamsters', ['id' => 'bb-hamsters'])
            </div>
        </div>
    </div>
</div>

@push('javascript')
<script src="{{ asset('vendor/buyback-manager/js/hamsters.js') }}?v=2"></script>
<script>
$(document).ready(function () {
    const form = document.getElementById('appraisal-form');
    const hamsters = BuybackHamsters.create(document.getElementById('bb-hamsters'));
    // Relative, so the page's scheme is always the one used.
    const prepareUrl = "{{ route('buyback.appraisal.prepare', [], false) }}";
    let sending = false;

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        if (sending) {
            return;
        }
        sending = true;

        hamsters.start();
        $('#loadingModal').appendTo('body').modal('show');

        const corporationId = document.getElementById('corporation_id').value;
        BuybackHamsters.waitForPrices({
            prepareUrl: prepareUrl,
            waitUrl: function (token) {
                return prepareUrl + '/' + token + '?corporation_id=' + encodeURIComponent(corporationId);
            },
            body: new FormData(form),
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            onWaiting: function (count, text) {
                hamsters.sub(text);
            }
        }).then(function () {
            // The submit event does not fire again for this.
            form.submit();
        });
    });

    // Back from the result, the browser may show this page as it was left:
    // with the modal up and the form locked.
    window.addEventListener('pageshow', function (event) {
        if (event.persisted) {
            sending = false;
            hamsters.stop();
            $('#loadingModal').modal('hide');
        }
    });
});
</script>
@endpush
@endsection
