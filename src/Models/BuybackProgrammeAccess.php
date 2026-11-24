<?php

namespace BuybackManager\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One SeAT account or SeAT role named on a programme, as a manager or a
 * viewer. See the create_buyback_manager_programme_access migration.
 */
class BuybackProgrammeAccess extends Model
{
    protected $table = 'buyback_manager_programme_access';

    public const MANAGER = 'manager';
    public const VIEWER = 'viewer';

    public const ACCESS = [self::MANAGER, self::VIEWER];

    public const USER = 'user';
    public const ROLE = 'role';

    public const SUBJECTS = [self::USER, self::ROLE];

    protected $fillable = [
        'setting_id',
        'access',
        'subject_type',
        'subject_id',
    ];

    protected $casts = [
        'setting_id' => 'integer',
        'subject_id' => 'integer',
    ];

    public function setting()
    {
        return $this->belongsTo(BuybackSetting::class, 'setting_id');
    }
}
