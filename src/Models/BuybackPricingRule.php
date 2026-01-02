<?php

namespace BuybackManager\Models;

use Illuminate\Database\Eloquent\Model;

class BuybackPricingRule extends Model
{
    protected $table = 'buyback_pricing_rules';

    protected $fillable = [
        'setting_id',
        'type',
        'type_id',
        'percentage',
        'excluded',
        'priority',
    ];

    protected $casts = [
        'setting_id' => 'integer',
        'type_id' => 'integer',
        'percentage' => 'decimal:2',
        'excluded' => 'boolean',
        'priority' => 'integer',
    ];

    public function setting()
    {
        return $this->belongsTo(BuybackSetting::class, 'setting_id');
    }
}
