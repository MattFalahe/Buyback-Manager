@extends('web::layouts.grids.12')

@section('title', 'Buyback Manager - Appraisal')
@section('page_header', 'Buyback Appraisal')

@push('head')
    <link rel="stylesheet" href="{{ asset('web/css/buyback-manager/buyback-manager.css') }}">
    <link rel="stylesheet" href="{{ asset('web/css/buyback-manager/appraisal.css') }}">
@endpush

@section('content')
    <div class="appraisal-wrapper">
        <!-- Input Section -->
        <div class="appraisal-input-section">
            <div class="row">
                <div class="col-md-12">
                    <h4><i class="fa fa-calculator"></i> Appraise Your Items</h4>
                    <p class="text-muted">Paste your items below to get an instant buyback quote</p>
                </div>
            </div>

            <div class="row">
                <div class="col-md-12 corporation-select">
                    <label for="corporation-select">Select Corporation:</label>
                    <select id="corporation-select" class="form-control">
                        <option value="">-- Select Corporation --</option>
                        @foreach($corporations as $corporation)
                            <option value="{{ $corporation->corporation_id }}">
                                {{ $corporation->corporation->name ?? 'Unknown' }}
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="row">
                <div class="col-md-12">
                    <label for="items-input">Items:</label>
                    <textarea id="items-input" 
                              class="form-control appraisal-textarea" 
                              placeholder="Paste items here...&#10;&#10;Format:&#10;Tritanium  1000000&#10;Pyerite    500000&#10;Mexallon   250000"></textarea>
                    
                    <div class="format-hint">
                        <strong>Supported formats:</strong>
                        <ul>
                            <li>EVE inventory copy (Ctrl+C in inventory)</li>
                            <li>Item name followed by quantity: <code>Tritanium  1000000</code></li>
                            <li>Tab or multiple spaces between name and quantity</li>
                        </ul>
                    </div>
                </div>
            </div>

            <div class="row" style="margin-top: 15px;">
                <div class="col-md-12">
                    <button id="appraise-btn" class="btn btn-primary btn-lg">
                        <i class="fa fa-calculator"></i> Calculate Buyback Value
                    </button>
                    <button id="clear-btn" class="btn btn-default btn-lg">
                        <i class="fa fa-times"></i> Clear
                    </button>
                </div>
            </div>
        </div>

        <!-- Error Message -->
        <div id="appraisal-error" class="appraisal-error">
            <i class="fa fa-exclamation-triangle"></i>
            <span id="appraisal-error-message"></span>
        </div>

        <!-- Loading Indicator -->
        <div id="appraisal-loading" class="appraisal-loading">
            <i class="fa fa-spinner fa-spin spinner"></i>
            <p>Calculating appraisal...</p>
        </div>

        <!-- Results Section -->
        <div id="appraisal-results" class="appraisal-results">
            <div class="appraisal-summary">
                <div class="row">
                    <div class="col-md-3 col-sm-6">
                        <div style="text-align: center;">
                            <div class="appraisal-total" id="total-value">0 ISK</div>
                            <div class="appraisal-percentage">Buyback Value</div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <div style="text-align: center;">
                            <div class="appraisal-total" id="market-value" style="color: #3c8dbc;">0 ISK</div>
                            <div class="appraisal-percentage">Market Value</div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <div style="text-align: center;">
                            <div class="appraisal-total" id="percentage-of-market" style="color: #f39c12;">0%</div>
                            <div class="appraisal-percentage">Of Market Value</div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <div style="text-align: center;">
                            <div class="appraisal-total" id="items-count" style="color: #666;">0</div>
                            <div class="appraisal-percentage">Items</div>
                        </div>
                    </div>
                </div>
            </div>

            <div style="margin-top: 20px;">
                <h4>
                    <i class="fa fa-list"></i> Items Breakdown
                    <button id="copy-appraisal" class="btn btn-sm btn-default pull-right">
                        <i class="fa fa-copy"></i> Copy to Clipboard
                    </button>
                </h4>
                <table class="table table-striped appraisal-items-table">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th class="text-right">Quantity</th>
                            <th class="text-right">Market Price</th>
                            <th class="text-right">%</th>
                            <th class="text-right">Buyback Price</th>
                            <th class="text-right">Total Value</th>
                        </tr>
                    </thead>
                    <tbody id="appraisal-items-tbody">
                        <!-- Items will be inserted here via JavaScript -->
                    </tbody>
                </table>
            </div>

            <div class="callout callout-success" style="margin-top: 20px;">
                <h4><i class="fa fa-info-circle"></i> Next Steps</h4>
                <p>To receive this buyback value, create an item exchange contract with these items to the corporation listed above.</p>
            </div>
        </div>
    </div>
@endsection

@push('javascript')
    <script src="{{ asset('web/js/buyback-manager/buyback-manager.js') }}"></script>
    <script src="{{ asset('web/js/buyback-manager/appraisal.js') }}"></script>
@endpush
