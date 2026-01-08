<?php

namespace BuybackManager\Models;

use Illuminate\Database\Eloquent\Model;
use Seat\Eveapi\Models\Character\CharacterInfo;
use Seat\Eveapi\Models\Corporation\CorporationInfo;

class BuybackContract extends Model
{
    protected $table = 'buyback_contracts';

    protected $fillable = [
        'contract_id',
        'corporation_id',
        'issuer_id',
        'status',
        'total_value',
        'items_count',
        'issued_date',
        'completed_date',
    ];

    protected $casts = [
        'contract_id' => 'integer',
        'corporation_id' => 'integer',
        'issuer_id' => 'integer',
        'total_value' => 'decimal:2',
        'items_count' => 'integer',
        'issued_date' => 'datetime',
        'completed_date' => 'datetime',
    ];

    public function corporation()
    {
        return $this->belongsTo(CorporationInfo::class, 'corporation_id', 'corporation_id');
    }

    public function issuer()
    {
        return $this->belongsTo(CharacterInfo::class, 'issuer_id', 'character_id');
    }

    public function items()
    {
        return $this->hasMany(BuybackContractItem::class, 'contract_id');
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', ['outstanding', 'in_progress']);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }
}
