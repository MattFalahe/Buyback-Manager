<?php

namespace BuybackManager\Models;

use Illuminate\Database\Eloquent\Model;
use Seat\Eveapi\Models\Sde\InvType;

/**
 * One priced line of an appraisal. These rows are pruned on a shorter
 * window than their parent because the payout record lives on
 * buyback_contract_items once a contract claims the appraisal.
 */
class BuybackAppraisalItem extends Model
{
    protected $table = 'buyback_appraisal_items';

    public $timestamps = false;

    protected $fillable = [
        'appraisal_id',
        'type_id',
        'type_name',
        'quantity',
        'market_price',
        'buyback_price',
        'percentage',
        'price_side',
        'total_market',
        'total_buyback',
        'group_id',
        'category_id',
    ];

    protected $casts = [
        'appraisal_id' => 'integer',
        'type_id' => 'integer',
        'quantity' => 'integer',
        'market_price' => 'decimal:2',
        'buyback_price' => 'decimal:2',
        'percentage' => 'decimal:2',
        'total_market' => 'decimal:2',
        'total_buyback' => 'decimal:2',
        'group_id' => 'integer',
        'category_id' => 'integer',
    ];

    public function appraisal()
    {
        return $this->belongsTo(BuybackAppraisal::class, 'appraisal_id');
    }

    public function type()
    {
        return $this->belongsTo(InvType::class, 'type_id', 'typeID');
    }
}
