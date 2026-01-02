<?php

namespace BuybackManager\Models;

use Illuminate\Database\Eloquent\Model;
use Seat\Eveapi\Models\Corporation\CorporationInfo;

class BuybackSetting extends Model
{
    protected $table = 'buyback_settings';

    protected $fillable = [
        'corporation_id',
        'character_id',
        'enabled',
        'base_percentage',
        'price_source',
        'region_id',
    ];

    protected $casts = [
        'corporation_id' => 'integer',
        'character_id' => 'integer',
        'enabled' => 'boolean',
        'base_percentage' => 'decimal:2',
        'region_id' => 'integer',
    ];

    public function corporation()
    {
        return $this->belongsTo(CorporationInfo::class, 'corporation_id', 'corporation_id');
    }

    public function pricingRules()
    {
        return $this->hasMany(BuybackPricingRule::class, 'setting_id');
    }

    public function getPercentageForItem(int $typeId, int $categoryId = null, int $groupId = null): ?float
    {
        // Priority: item > group > category > base
        $rules = $this->pricingRules()
            ->where(function ($query) use ($typeId, $categoryId, $groupId) {
                $query->where(function ($q) use ($typeId) {
                    $q->where('type', 'item')->where('type_id', $typeId);
                })
                ->orWhere(function ($q) use ($groupId) {
                    if ($groupId) {
                        $q->where('type', 'group')->where('type_id', $groupId);
                    }
                })
                ->orWhere(function ($q) use ($categoryId) {
                    if ($categoryId) {
                        $q->where('type', 'category')->where('type_id', $categoryId);
                    }
                });
            })
            ->orderBy('priority', 'desc')
            ->first();

        if ($rules && $rules->excluded) {
            return null; // Item is excluded
        }

        return $rules?->percentage ?? $this->base_percentage;
    }
}
