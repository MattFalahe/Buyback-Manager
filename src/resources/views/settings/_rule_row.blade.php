{{-- One pricing-rule row. Shared by the price-exception and exclusion
     sections on the Pricing Rules page. Expects $rule plus the name maps
     ($categoryNames, $groupNames, $typeNames) and $setting from the parent. --}}
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
            @endif
            @if($rule->featured)
                <span class="badge badge-warning"><i class="fa fa-star"></i> Most wanted</span>
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
