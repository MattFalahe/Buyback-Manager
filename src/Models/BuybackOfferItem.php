<?php

namespace BuybackManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Seat\Eveapi\Models\Sde\InvType;

/**
 * Frozen per-item snapshot for a BuybackOffer.
 *
 * Once written, never updated — these rows are the legal record of
 * what was quoted, regardless of later market movement. Cascade
 * deletes with the parent offer.
 */
class BuybackOfferItem extends Model
{
    protected $table = 'buyback_offer_items';

    protected $fillable = [
        'offer_id',
        'type_id',
        'type_name',
        'group_id',
        'category_id',
        'quantity',
        'market_price',
        'buyback_price',
        'percentage',
        'total_market',
        'total_buyback',
    ];

    protected $casts = [
        'offer_id' => 'integer',
        'type_id' => 'integer',
        'group_id' => 'integer',
        'category_id' => 'integer',
        'quantity' => 'integer',
        'market_price' => 'decimal:2',
        'buyback_price' => 'decimal:2',
        'percentage' => 'decimal:2',
        'total_market' => 'decimal:2',
        'total_buyback' => 'decimal:2',
    ];

    public function offer(): BelongsTo
    {
        return $this->belongsTo(BuybackOffer::class, 'offer_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(InvType::class, 'type_id', 'typeID');
    }
}
