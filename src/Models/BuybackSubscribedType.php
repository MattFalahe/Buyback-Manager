<?php

namespace BuybackManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Local ledger of type IDs Buyback Manager has subscribed to Manager
 * Core's pricing service per market.
 *
 * Powers the "lazy on encounter" subscription pattern: every time
 * PriceProviderService asks MC for prices on a list of type IDs, it
 * diffs against this ledger via missingFrom() and only calls
 * pricing.subscribeTypes for the genuinely new ones.
 *
 * The ledger is the source of truth for "what BB has told MC about" —
 * works even when MC is uninstalled (table just sits idle) and survives
 * MC reinstall (re-uses the same ledger to re-subscribe).
 */
class BuybackSubscribedType extends Model
{
    protected $table = 'buyback_subscribed_types';

    protected $fillable = [
        'type_id',
        'market',
        'subscribed_at',
    ];

    protected $casts = [
        'type_id' => 'integer',
        'subscribed_at' => 'datetime',
    ];

    /**
     * Return the subset of $typeIds that have NOT yet been subscribed to
     * MC for the given market. Result is the input typeIds (as ints),
     * minus anything already in the ledger.
     *
     * Empty input returns empty output.
     *
     * @param int[]  $typeIds
     * @return int[]
     */
    public static function missingFrom(array $typeIds, string $market): array
    {
        if (empty($typeIds)) {
            return [];
        }

        $known = static::where('market', $market)
            ->whereIn('type_id', $typeIds)
            ->pluck('type_id')
            ->map(fn($id) => (int) $id)
            ->all();

        $knownSet = array_flip($known);

        return array_values(array_filter(
            array_map('intval', $typeIds),
            fn($id) => !isset($knownSet[$id])
        ));
    }

    /**
     * Idempotently mark a list of type IDs as subscribed in the given
     * market. Uses insertOrIgnore so concurrent callers can't double-write
     * the unique (type_id, market) key.
     *
     * @param int[] $typeIds
     */
    public static function markSubscribed(array $typeIds, string $market): void
    {
        if (empty($typeIds)) {
            return;
        }

        $now = now();
        $rows = array_map(fn($id) => [
            'type_id' => (int) $id,
            'market' => $market,
            'subscribed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ], $typeIds);

        DB::table('buyback_subscribed_types')->insertOrIgnore($rows);
    }
}
