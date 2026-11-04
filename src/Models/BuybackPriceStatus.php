<?php

namespace BuybackManager\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * How a corporation's price provider did on its last quote: failing or
 * not, since when, and whether the fallback provider was answering for it.
 * Kept so the pricing alert fires when that changes, not on every quote.
 */
class BuybackPriceStatus extends Model
{
    protected $table = 'buyback_price_status';

    protected $fillable = [
        'setting_id',
        'provider',
        'market',
        'failing',
        'error',
        'since',
        'last_success',
        'fallback',
        'fallback_ok',
        'fallback_error',
    ];

    protected $casts = [
        'setting_id' => 'integer',
        'failing' => 'boolean',
        'since' => 'datetime',
        'last_success' => 'datetime',
        'fallback_ok' => 'boolean',
    ];
}
