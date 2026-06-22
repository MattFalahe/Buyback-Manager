<?php

namespace BuybackManager\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Local price cache.
 *
 * Written through by PriceProviderService::cachePrices after every
 * successful provider fetch (any of Fuzzwork/Janice/MC). Read by
 * PriceProviderService::getPricesFromLocalCache when the upstream
 * provider throws — last-resort source so a contract sync doesn't
 * return zeros just because the network blipped.
 *
 * Schema mirrors Mining Manager's mining_price_cache shape so operators
 * see a familiar table.
 */
class BuybackPriceCache extends Model
{
    protected $table = 'buyback_price_cache';

    protected $fillable = [
        'type_id',
        'region_id',
        'sell_price',
        'buy_price',
        'average_price',
        'cached_at',
    ];

    protected $casts = [
        'type_id' => 'integer',
        'region_id' => 'integer',
        'sell_price' => 'decimal:2',
        'buy_price' => 'decimal:2',
        'average_price' => 'decimal:2',
        'cached_at' => 'datetime',
    ];
}
