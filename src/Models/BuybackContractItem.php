<?php

namespace BuybackManager\Models;

use Illuminate\Database\Eloquent\Model;
use Seat\Eveapi\Models\Sde\InvType;

class BuybackContractItem extends Model
{
    protected $table = 'buyback_contract_items';

    protected $fillable = [
        'contract_id',
        'type_id',
        'quantity',
        'unit_price',
        'total_value',
        'category_id',
        'group_id',
    ];

    protected $casts = [
        'contract_id' => 'integer',
        'type_id' => 'integer',
        'quantity' => 'integer',
        'unit_price' => 'decimal:2',
        'total_value' => 'decimal:2',
        'category_id' => 'integer',
        'group_id' => 'integer',
    ];

    public function contract()
    {
        return $this->belongsTo(BuybackContract::class, 'contract_id');
    }

    public function type()
    {
        return $this->belongsTo(InvType::class, 'type_id', 'typeID');
    }
}
