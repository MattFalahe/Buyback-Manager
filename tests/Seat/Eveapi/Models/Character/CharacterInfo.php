<?php

namespace Seat\Eveapi\Models\Character;

use Illuminate\Database\Eloquent\Model;

/**
 * Stands in for SeAT's model when the tests run without SeAT.
 */
class CharacterInfo extends Model
{
    public $incrementing = false;

    protected static $unguarded = true;

    protected $table = 'character_infos';

    protected $primaryKey = 'character_id';
}
