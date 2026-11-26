<?php

namespace Seat\Eveapi\Models\Sde;

use Illuminate\Database\Eloquent\Model;

/**
 * Stands in for SeAT's model when the tests run without SeAT.
 */
class InvType extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'invTypes';

    protected $primaryKey = 'typeID';
}
