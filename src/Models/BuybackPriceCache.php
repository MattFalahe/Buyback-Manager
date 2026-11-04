<?php

namespace BuybackManager\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The last price seen for an item at a market.
 *
 * One row per market and item, whichever provider answered last. Each side
 * keeps its last real price and when it was seen, and is never overwritten
 * by an answer with no orders on that side, so it can stand in when the
 * provider is down. `checked_at` is when a provider last answered for the
 * item at all: a side seen before that answer had no orders in it.
 */
class BuybackPriceCache extends Model
{
    protected $table = 'buyback_price_cache';

    protected $fillable = [
        'market',
        'type_id',
        'provider',
        'buy_price',
        'buy_seen_at',
        'sell_price',
        'sell_seen_at',
        'checked_at',
    ];

    protected $casts = [
        'type_id' => 'integer',
        'buy_price' => 'float',
        'buy_seen_at' => 'datetime',
        'sell_price' => 'float',
        'sell_seen_at' => 'datetime',
        'checked_at' => 'datetime',
    ];
}
