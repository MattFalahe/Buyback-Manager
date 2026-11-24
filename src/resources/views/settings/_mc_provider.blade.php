{{-- The provider Manager Core prices the chosen market through for this
     corporation. The page script fills the options for the market picked
     beside it, from what Manager Core lists there. --}}
<div class="form-group">
    <label for="{{ $fieldId }}">Provider at that market</label>
    <select name="manager_core_provider" id="{{ $fieldId }}" class="form-control bb-mc-provider">
        <option value="">The market's own</option>
    </select>
    <small class="text-muted">
        Manager Core keeps this provider's prices at the market for Buyback Manager alone, next to the market's own, so corporations on the same market can price through different providers. An item it has not priced through it yet goes to the fallback provider.
    </small>
    @if($mcOverrideMarket)
        <div class="text-warning small" style="margin-top:4px;">
            <i class="fa fa-exclamation-triangle"></i>
            While Manager Core's Pricing Preferences override Buyback Manager, the provider named here is set aside.
        </div>
    @endif
</div>
