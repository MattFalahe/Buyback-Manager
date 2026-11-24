<?php

namespace BuybackManager\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One entry in a corp's buyback location allow-list. See the
 * create_buyback_manager_location_rules migration for semantics.
 */
class BuybackLocationRule extends Model
{
    protected $table = 'buyback_manager_location_rules';

    public const TYPE_REGION = 'region';
    public const TYPE_CONSTELLATION = 'constellation';
    public const TYPE_SYSTEM = 'system';
    public const TYPE_STATION = 'station';
    public const TYPE_STRUCTURE = 'structure';

    public const ALL_TYPES = [
        self::TYPE_REGION,
        self::TYPE_CONSTELLATION,
        self::TYPE_SYSTEM,
        self::TYPE_STATION,
        self::TYPE_STRUCTURE,
    ];

    protected $fillable = [
        'setting_id',
        'location_type',
        'location_id',
        'location_name',
        'haul_fee_per_m3',
    ];

    protected $casts = [
        'setting_id' => 'integer',
        'location_id' => 'integer',
        'haul_fee_per_m3' => 'decimal:2',
    ];

    public function setting()
    {
        return $this->belongsTo(BuybackSetting::class, 'setting_id');
    }
}
