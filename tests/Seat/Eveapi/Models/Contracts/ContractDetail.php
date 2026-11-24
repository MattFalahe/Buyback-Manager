<?php

namespace Seat\Eveapi\Models\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Stands in for SeAT's model when the tests run without SeAT: the same
 * table, key and relation the plugin uses. Where SeAT is installed, its own
 * class loads first and this file is never read.
 */
class ContractDetail extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected static $unguarded = true;

    protected $table = 'contract_details';

    protected $primaryKey = 'contract_id';

    protected $casts = [
        'for_corporation' => 'boolean',
    ];

    public function lines()
    {
        return $this->hasMany(ContractItem::class, 'contract_id');
    }
}
