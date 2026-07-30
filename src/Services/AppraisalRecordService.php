<?php

namespace BuybackManager\Services;

use BuybackManager\Models\BuybackAppraisal;
use BuybackManager\Models\BuybackAppraisalItem;
use BuybackManager\Models\BuybackSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Persists appraisal results as claimable, single-use keys, and keeps the
 * table from growing without bound.
 *
 * A record is created every time someone runs an appraisal — from the
 * public page with no login, or from the member-facing tool. The key it
 * returns is what the seller pastes into their contract's Description, and
 * what ContractService later resolves the contract back to.
 *
 * Retention is two-tier because the per-item rows are the bulk of the
 * data: items are pruned on a short window, headers are kept far longer so
 * statistics survive. That is safe because a claimed appraisal's items are
 * copied onto buyback_contract_items at match time.
 */
class AppraisalRecordService
{
    /**
     * Rows deleted per prune pass. Keeps the delete incremental so a large
     * backlog can never cause a long lock — the sync cycle runs every 15
     * minutes, so this drains quickly.
     */
    private const PRUNE_CHUNK = 5000;

    /**
     * Store an appraisal result and return the saved record.
     *
     * @param  array  $result   The array returned by AppraisalService::createAppraisal
     * @param  int    $corporationId
     * @param  int|null $userId              SeAT user, when the visitor had a session
     * @param  int|null $issuerCharacterId   Character to credit, when known
     */
    public function store(array $result, int $corporationId, ?int $userId = null, ?int $issuerCharacterId = null): ?BuybackAppraisal
    {
        if (! ($result['success'] ?? false)) {
            return null;
        }

        try {
            return DB::transaction(function () use ($result, $corporationId, $userId, $issuerCharacterId) {
                $appraisal = BuybackAppraisal::create([
                    'public_id' => PublicIdGenerator::generate(),
                    'corporation_id' => $corporationId,
                    'user_id' => $userId,
                    'issuer_character_id' => $issuerCharacterId,
                    'raw_input' => $result['raw_input'] ?? null,
                    'total_market_value' => (float) ($result['total_market_value'] ?? 0),
                    'total_buyback_value' => (float) ($result['total_buyback_value'] ?? 0),
                    'average_percentage' => (float) ($result['average_percentage'] ?? 0),
                    'market' => $result['market'] ?? null,
                    'provider' => $result['provider'] ?? null,
                    'excluded_json' => $result['excluded'] ?? [],
                ]);

                $rows = [];
                foreach (($result['items'] ?? []) as $item) {
                    $rows[] = [
                        'appraisal_id' => $appraisal->id,
                        'type_id' => (int) $item['type_id'],
                        'type_name' => $item['type_name'] ?? null,
                        'quantity' => (int) $item['quantity'],
                        'market_price' => (float) $item['market_price'],
                        'buyback_price' => (float) $item['buyback_price'],
                        'percentage' => (float) $item['percentage'],
                        'price_side' => $item['price_side'] ?? null,
                        'total_market' => (float) $item['total_market'],
                        'total_buyback' => (float) $item['total_buyback'],
                        'group_id' => $item['group_id'] ?? null,
                        'category_id' => $item['category_id'] ?? null,
                    ];
                }

                if (! empty($rows)) {
                    // Chunked insert: a big paste can be hundreds of lines.
                    foreach (array_chunk($rows, 500) as $chunk) {
                        BuybackAppraisalItem::insert($chunk);
                    }
                }

                return $appraisal;
            });
        } catch (\Throwable $e) {
            // An appraisal that can't be stored is still worth showing, so
            // never let persistence break the response.
            Log::warning('[Buyback Manager] Could not store appraisal: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Resolve a public key to a claimable (unclaimed) appraisal for the
     * given corporation. Returns null when the key is unknown, belongs to
     * another corporation, or has already been claimed.
     */
    public function findClaimable(string $publicId, int $corporationId): ?BuybackAppraisal
    {
        return BuybackAppraisal::claimable()
            ->forCorp($corporationId)
            ->where('public_id', $publicId)
            ->first();
    }

    /**
     * Resolve a public key regardless of claim state — used to tell
     * "already used" apart from "never existed" when flagging a contract.
     */
    public function findAny(string $publicId, int $corporationId): ?BuybackAppraisal
    {
        return BuybackAppraisal::forCorp($corporationId)
            ->where('public_id', $publicId)
            ->first();
    }

    /**
     * Two-tier retention sweep. Called from the contract-sync cycle, so it
     * needs no cron of its own.
     *
     * Retention is read per corporation, using the longest configured
     * window so one corp's short setting can never delete another's data.
     */
    public function prune(): void
    {
        try {
            $itemDays = (int) (BuybackSetting::max('appraisal_item_retention_days') ?: 14);
            $headerDays = (int) (BuybackSetting::max('appraisal_retention_days') ?: 180);

            // Items first: drop the bulk rows for appraisals past the short
            // window. Claimed appraisals already copied their snapshot onto
            // the contract, so nothing durable is lost.
            $itemCutoff = now()->subDays(max(1, $itemDays));
            $staleIds = BuybackAppraisal::where('created_at', '<', $itemCutoff)
                ->limit(self::PRUNE_CHUNK)
                ->pluck('id');

            if ($staleIds->isNotEmpty()) {
                $deleted = BuybackAppraisalItem::whereIn('appraisal_id', $staleIds)->delete();
                if ($deleted > 0) {
                    Log::info("[Buyback Manager] Pruned {$deleted} appraisal item rows older than {$itemDays} days");
                }
            }

            // Then the headers themselves, on the long window.
            $headerCutoff = now()->subDays(max(1, $headerDays));
            $oldIds = BuybackAppraisal::where('created_at', '<', $headerCutoff)
                ->limit(self::PRUNE_CHUNK)
                ->pluck('id');

            if ($oldIds->isNotEmpty()) {
                BuybackAppraisalItem::whereIn('appraisal_id', $oldIds)->delete();
                $deleted = BuybackAppraisal::whereIn('id', $oldIds)->delete();
                if ($deleted > 0) {
                    Log::info("[Buyback Manager] Pruned {$deleted} appraisals older than {$headerDays} days");
                }
            }
        } catch (\Throwable $e) {
            // Housekeeping must never break the sync.
            Log::warning('[Buyback Manager] Appraisal prune failed: ' . $e->getMessage());
        }
    }
}
