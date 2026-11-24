<?php

namespace BuybackManager\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * How one programme's last contract sync went. The job catches each
 * programme's errors so one cannot stop the others, so this row is the only
 * place a failing sync shows.
 */
class BuybackSyncStatus extends Model
{
    /**
     * A programme whose last good read is older than this is reported as
     * not syncing. The sync runs every 15 minutes, so this allows for a few
     * missed or slow runs before anyone is warned.
     */
    public const STALE_AFTER_MINUTES = 60;

    protected $table = 'buyback_manager_sync_status';

    protected $fillable = [
        'setting_id',
        'last_run_at',
        'last_ok_at',
        'last_error',
        'failures',
        'feed_failing',
        'feed_reason',
        'feed_since',
        'contracts_seen',
        'contracts_new',
        'contracts_updated',
    ];

    protected $casts = [
        'setting_id' => 'integer',
        'last_run_at' => 'datetime',
        'last_ok_at' => 'datetime',
        'failures' => 'integer',
        'feed_failing' => 'boolean',
        'feed_since' => 'datetime',
        'contracts_seen' => 'integer',
        'contracts_new' => 'integer',
        'contracts_updated' => 'integer',
    ];

    public function setting()
    {
        return $this->belongsTo(BuybackSetting::class, 'setting_id');
    }

    public function isStale(): bool
    {
        return $this->last_ok_at === null
            || $this->last_ok_at->lt(now()->subMinutes(self::STALE_AFTER_MINUTES));
    }

    public function isHealthy(): bool
    {
        return ! $this->isStale() && $this->last_error === null;
    }
}
