<?php

namespace BuybackManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Webhook dispatch dedup + audit log.
 *
 * payload_hash is an SHA1 digest of (event_name + canonical payload).
 * The unique key (webhook_id, payload_hash) means "this exact event
 * already fired to this webhook" — used by WebhookDispatcher to skip
 * duplicate dispatches when ContractService re-runs over the same
 * unchanged contract.
 *
 * Retention swept by ContractService::pruneOldNotificationLogs every
 * sync cycle (rows older than 30 days). No separate cron required.
 */
class BuybackNotificationLog extends Model
{
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_RATE_LIMITED = 'rate_limited';

    protected $table = 'buyback_notification_log';

    protected $fillable = [
        'webhook_id',
        'event_name',
        'payload_hash',
        'sent_at',
        'status',
        'error',
    ];

    protected $casts = [
        'webhook_id' => 'integer',
        'sent_at' => 'datetime',
    ];

    public function webhook(): BelongsTo
    {
        return $this->belongsTo(BuybackWebhook::class, 'webhook_id');
    }

    /**
     * Compute the dedup hash for an event + payload pair. Centralised
     * so the same hash function is used at dispatch time and at lookup
     * time; drift here would silently disable dedup.
     *
     * Strategy:
     *   1. Exclude `event_id` from the payload before hashing. Each
     *      EventPublisher call mints a fresh UUID for that field, so
     *      including it would make every dispatch hash uniquely and
     *      dedup would never catch a genuinely-identical event (e.g.
     *      two workers racing to publish the same status transition).
     *   2. Recursively ksort the remaining keys so a reordered payload
     *      (different key insertion order, same content) still produces
     *      the same hash.
     *   3. JSON-encode with stable flags and SHA1 the result.
     *
     * The unique `(webhook_id, payload_hash)` DB constraint provides
     * the final dedup guarantee under race conditions; this method
     * lets the application-level check work BEFORE we hit the DB.
     */
    public static function computeHash(string $eventName, array $payload): string
    {
        $copy = $payload;
        unset($copy['event_id']);
        self::canonicalSort($copy);
        $canonical = json_encode($copy, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        return sha1($eventName . '|' . $canonical);
    }

    /**
     * Recursive ksort. Mutates $arr in place. Only descends into nested
     * arrays — scalar values and objects are left alone (the EventBus
     * envelope contract is JSON-serialisable scalars only, so objects
     * shouldn't appear here).
     */
    private static function canonicalSort(array &$arr): void
    {
        ksort($arr);
        foreach ($arr as &$value) {
            if (is_array($value)) {
                self::canonicalSort($value);
            }
        }
    }
}
