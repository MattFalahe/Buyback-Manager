<?php

namespace BuybackManager\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An item's packaged volume. See the create_buyback_manager_packaged_volumes
 * migration for where each source comes from.
 */
class BuybackPackagedVolume extends Model
{
    public const SOURCE_SDE = 'sde';
    public const SOURCE_JANICE = 'janice';
    public const SOURCE_ESI = 'esi';

    protected $table = 'buyback_manager_packaged_volumes';

    protected $primaryKey = 'type_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'type_id',
        'group_id',
        'packaged_volume',
        'source',
        'sde_build',
        'fetched_at',
    ];

    protected $casts = [
        'type_id' => 'integer',
        'group_id' => 'integer',
        'packaged_volume' => 'float',
        'sde_build' => 'integer',
        'fetched_at' => 'datetime',
    ];
}
