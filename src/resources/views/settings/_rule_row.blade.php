{{-- One pricing-rule row. Shared by the price-exception and exclusion
     sections on the Pricing Rules page. Expects $rule plus the name maps
     ($categoryNames, $groupNames, $typeNames) and $setting from the parent. --}}
@php
    $eveName = match ($rule->type) {
        'category' => $categoryNames[$rule->type_id] ?? 'Unknown',
        'group' => $groupNames[$rule->type_id] ?? 'Unknown',
        default => $typeNames[$rule->type_id] ?? 'Unknown',
    };
    $ownYield = $rule->refine_yield !== null;
    $yieldText = rtrim(rtrim(number_format($ownYield ? (float) $rule->refine_yield : $setting->programmeRefineYield(), 2), '0'), '.');
@endphp
<div class="pricing-rule {{ $rule->excluded ? 'excluded' : '' }}">
    <div style="display: flex; justify-content: space-between; align-items: center;">
        <div>
            <span class="pricing-rule-type">{{ strtoupper($rule->type) }}</span>
            <strong>{{ $rule->display_name ?: $eveName }}</strong>
            @if($rule->excluded)
                <span class="badge badge-danger">NOT BOUGHT</span>
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
                @if($rule->refined)
                    <span class="badge badge-success" title="{{ $ownYield ? 'This rule\'s own yield' : 'The programme\'s refining yield, for ore, ice and moon ore' }}"><i class="fa fa-industry"></i> refined at {{ $yieldText }}%{{ $ownYield ? '' : ' (programme)' }}</span>
                @endif
            @endif
            @if($rule->featured)
                <span class="badge badge-warning"><i class="fa fa-star"></i> Most wanted</span>
            @endif
            @if($rule->display_name)
                <div class="text-muted" style="font-size: 0.85em; margin-top: 2px;">EVE name: {{ $eveName }}</div>
            @endif
        </div>
        <div>
            <form action="{{ route('buyback-manager.settings.rules.destroy', [$setting->id, $rule->id]) }}"
                  method="POST"
                  style="display: inline;"
                  onsubmit="return confirm('Delete this rule?');">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-xs btn-danger">
                    <i class="fa fa-trash"></i> Delete
                </button>
            </form>
        </div>
    </div>
</div>
