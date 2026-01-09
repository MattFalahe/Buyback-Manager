@extends('web::layouts.grids.12')

@section('title', 'Buyback Appraisal')
@section('page_header', 'Buyback Appraisal')

@section('full')
<div class="card">
    <div class="card-header">
        <h3 class="card-title">
            <i class="fas fa-calculator"></i> Buyback Appraisal
        </h3>
        <div class="card-tools">
            <span class="badge badge-success">Powered by Manager Core</span>
        </div>
    </div>
    <div class="card-body">
        @if(session('error'))
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-triangle"></i> {{ session('error') }}
            </div>
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
                <textarea
                    name="items"
                    id="items"
                    class="form-control @error('items') is-invalid @enderror"
                    rows="15"
                    placeholder="Paste your items here...&#10;&#10;Supported formats:&#10;• EVE inventory (Ctrl+C)&#10;• Item Name    Quantity&#10;• Cargo scan format"
                    required
                >{{ old('items') }}</textarea>
                @error('items')
                    <span class="invalid-feedback">{{ $message }}</span>
                @enderror

                <small class="form-text text-muted">
                    <strong>Supported formats:</strong>
                    <ul class="mb-0">
                        <li>EVE inventory copy (Ctrl+C in inventory)</li>
                        <li>Cargo scan format: <code>1,000  Tritanium</code></li>
                        <li>Asset list format: <code>Tritanium    1000000</code></li>
                    </ul>
                </small>
            </div>

            <div class="form-group">
                <button type="submit" class="btn btn-primary btn-lg">
                    <i class="fas fa-calculator"></i> Calculate Buyback Price
                </button>
                <button type="button" class="btn btn-default btn-lg" onclick="document.getElementById('items').value = ''">
                    <i class="fas fa-times"></i> Clear
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Loading Modal -->
<div class="modal fade" id="loadingModal" data-backdrop="static" data-keyboard="false" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-body text-center py-5">
                <div class="spinner-border text-primary mb-3" style="width: 3rem; height: 3rem;" role="status">
                    <span class="sr-only">Loading...</span>
                </div>
                <h4 id="loading-message">Hamsters are calculating your buyback value...</h4>
                <p class="text-muted" id="loading-tip">Fetching prices from market and applying corporate rules</p>
            </div>
        </div>
    </div>
</div>

@push('javascript')
<script>
$(document).ready(function() {
    const funMessages = [
        "Hamsters are calculating hard...",
        "Negotiating with Jita traders...",
        "Counting all the ISK...",
        "Consulting the market oracle...",
        "Teaching monkeys to do math...",
        "Bribing station traders...",
        "Checking market fluctuations...",
        "Applying corporate tax...",
        "Running complex algorithms...",
        "Fetching prices from ESI...",
    ];

    let messageIndex = 0;
    let messageInterval;

    $('#appraisal-form').on('submit', function() {
        $('#loadingModal').modal('show');

        // Rotate messages every 3 seconds
        messageInterval = setInterval(function() {
            $('#loading-message').fadeOut(300, function() {
                messageIndex = (messageIndex + 1) % funMessages.length;
                $(this).text(funMessages[messageIndex]).fadeIn(300);
            });
        }, 3000);
    });
});
</script>
@endpush
@endsection
