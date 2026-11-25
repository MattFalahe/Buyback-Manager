<?php

namespace Seat\Eveapi\Models\Alliances;

use Illuminate\Database\Eloquent\Model;

/**
 * Stands in for SeAT's model when the tests run without SeAT.
 */
class Alliance extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected static $unguarded = true;

    protected $table = 'alliances';

    protected $primaryKey = 'alliance_id';
}
