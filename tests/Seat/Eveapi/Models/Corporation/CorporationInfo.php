<?php

namespace Seat\Eveapi\Models\Corporation;

use Illuminate\Database\Eloquent\Model;

/**
 * Stands in for SeAT's model when the tests run without SeAT.
 */
class CorporationInfo extends Model
{
    public $incrementing = false;

    protected static $unguarded = true;

    protected $table = 'corporation_infos';

    protected $primaryKey = 'corporation_id';
}
