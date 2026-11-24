<?php

namespace Seat\Eveapi\Models\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Stands in for SeAT's model when the tests run without SeAT.
 */
class ContractItem extends Model
{
    public $incrementing = false;

    protected static $unguarded = true;

    protected $table = 'contract_items';

    protected $primaryKey = 'record_id';

    protected $casts = [
        'is_singleton' => 'boolean',
        'is_included' => 'boolean',
    ];
}
