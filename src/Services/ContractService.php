<?php

namespace BuybackManager\Services;

use BuybackManager\Models\BuybackAppraisal;
use BuybackManager\Models\BuybackContract;
use BuybackManager\Models\BuybackContractItem;
use BuybackManager\Models\BuybackNotificationLog;
use BuybackManager\Models\BuybackSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Seat\Eveapi\Models\Contracts\CharacterContract;
use Seat\Eveapi\Models\Contracts\ContractDetail;
use Seat\Eveapi\Models\Contracts\CorporationContract;

/**
 * Syncs buyback contracts from SeAT's already-fetched contract tables,
 * pairs each one to the appraisal whose key its Description carries, and
 * announces the result.
 *
 * The appraisal is a REFERENCE, not a price lock. On first sighting the
 * contract is compared against it and any discrepancy is recorded as a
 * review flag rather than silently accepted or silently dropped:
 *
 *   - the ISK the member asked for versus the value we quoted
 *   - whether the quote was already stale when they contracted
 *   - whether the contract's items match the ones we priced
 *   - whether the appraisal key had already been used
 *   - whether the contract was created somewhere we accept from
 *
 * A clean contract announces through contract.matched; one carrying any
 * flag announces through contract.flagged instead. Never both, so a
 * webhook can subscribe to either without being double-pinged.
 *
 * A contract whose Description holds no key at all is not a buyback
 * contract and is skipped entirely, which is what keeps unrelated
 * item-exchange contracts out of the list.
 */
class ContractService
{
    /**
     * Notification log entries older than this are pruned at the
     * start of each sync cycle. No separate cron required.
     */
    private const NOTIFICATION_LOG_RETENTION_DAYS = 30;

    protected AppraisalMatcher $matcher;

    protected AppraisalRecordService $records;

    protected EventPublisher $eventPublisher;

    protected LocationResolver $locationResolver;

    public function __construct(
        AppraisalMatcher $matcher,
        AppraisalRecordService $records,
        EventPublisher $eventPublisher,
        LocationResolver $locationResolver
    ) {
        $this->matcher = $matcher;
        $this->records = $records;
        $this->eventPublisher = $eventPublisher;
        $this->locationResolver = $locationResolver;
    }

    public function syncContracts(): void
    {
        $this->pruneOldNotificationLogs();
        $this->records->prune();

        $settings = BuybackSetting::where('enabled', true)->get();

        foreach ($settings as $setting) {
            $this->syncContractsForSetting($setting);
        }

        $this->nudgeStaleContracts();
    }

    protected function syncContractsForSetting(BuybackSetting $setting): void
    {
        try {
            // Resolve which ESI feed to read + which assignee to match,
            // per the contract target. The feed follows EVE contract
            // visibility — see BuybackSetting::resolveSyncSource:
            //   my_corp -> corporation_contracts (own corp), assignee = own corp
            //   corp    -> corporation_contracts (target corp), assignee = target corp
            //   player  -> character_contracts (operator), assignee = operator
            $source = $setting->resolveSyncSource();

            if ($source === null) {
                // Instructions-only target (free-text external corp).
                // Nothing to sync — confirmed by hand instead.
                return;
            }

            $contractIds = $source['type'] === 'character'
                ? CharacterContract::where('character_id', $source['feed_id'])->pluck('contract_id')
                : CorporationContract::where('corporation_id', $source['feed_id'])->pluck('contract_id');

            if ($contractIds->isEmpty()) {
                return;
            }

            $contracts = ContractDetail::whereIn('contract_id', $contractIds)
                ->where('type', 'item_exchange')
                ->where('assignee_id', $source['assignee_id'])
                ->with('lines')
                ->get();

            foreach ($contracts as $contract) {
                $this->processContract($contract, $setting);
            }
        } catch (\Throwable $e) {
            Log::error('[Buyback Manager] Contract sync error for corp ' . $setting->corporation_id . ': ' . $e->getMessage());
        }
    }

    protected function processContract(ContractDetail $contract, BuybackSetting $setting): void
    {
        $existing = BuybackContract::where('contract_id', $contract->contract_id)->first();

        // ---- Already-tracked contract: handle status transitions only ----
        if ($existing !== null) {
            if ($existing->status === $contract->status) {
                return; // unchanged
            }

            $previousStatus = $existing->status;
            $existing->update([
                'status' => $contract->status,
                'completed_date' => $contract->date_completed,
            ]);

            $eventType = $this->classifyEventType($previousStatus, $contract->status);
            if ($eventType !== null) {
                $this->eventPublisher->publish(
                    'buyback.contract.' . $eventType,
                    $this->buildEnvelope($existing->fresh(), $setting, $previousStatus)
                );
            }

            return;
        }

        // ---- First sighting: require an appraisal key in the description ----
        $match = $this->matcher->resolve($contract, $setting);

        if ($match['key'] === null) {
            // Not a buyback contract at all. Skip silently — this is the
            // noise case (unrelated item-exchange contracts to the corp).
            return;
        }

        if ($match['appraisal'] === null) {
            // A key was quoted but it doesn't resolve for this corporation:
            // typo, wrong corp, or the appraisal aged out. Worth a review
            // signal, but there's nothing to value the contract against so
            // no tracked row is created.
            $this->publishUnmatchedAttempt($contract, $setting, $match['key']);

            return;
        }

        /** @var BuybackAppraisal $appraisal */
        $appraisal = $match['appraisal'];

        $quoted = (float) $appraisal->total_buyback_value;
        $asked = (float) ($contract->price ?? 0);
        $deviation = $this->deviationPercent($quoted, $asked);
        $flags = $this->computeFlags($contract, $appraisal, $setting, $deviation, (bool) $match['reused']);

        $savedContract = null;
        DB::transaction(function () use ($contract, $setting, $appraisal, $quoted, $asked, $deviation, $flags, $match, &$savedContract) {
            $buybackContract = BuybackContract::create([
                'contract_id' => $contract->contract_id,
                'corporation_id' => $setting->corporation_id,
                'issuer_id' => $contract->issuer_id,
                'appraisal_id' => $appraisal->id,
                'appraisal_public_id' => $appraisal->public_id,
                'status' => $contract->status,
                'total_value' => $quoted,
                'asked_price' => $asked,
                'deviation_percent' => $deviation,
                'flags_json' => $flags,
                'items_count' => $appraisal->items->count(),
                'issued_date' => $contract->date_issued,
                'completed_date' => $contract->date_completed,
            ]);

            // Copy the appraisal's item snapshot onto the contract. This is
            // the durable payout record, and it's why the appraisal's own
            // item rows can be pruned on a short window.
            foreach ($appraisal->items as $item) {
                BuybackContractItem::create([
                    'contract_id' => $buybackContract->id,
                    'type_id' => (int) $item->type_id,
                    'quantity' => (int) $item->quantity,
                    'unit_price' => (float) $item->buyback_price,
                    'total_value' => (float) $item->total_buyback,
                    'category_id' => $item->category_id,
                    'group_id' => $item->group_id,
                ]);
            }

            // Claim the key so it can't be used again. A reused key is
            // already claimed by an earlier contract — leave that claim
            // pointing at the original.
            if (! $match['reused']) {
                $claim = [
                    'matched_contract_id' => $buybackContract->id,
                    'matched_at' => now(),
                ];

                // Membership is DETECTED, not declared. An appraisal made
                // without signing in has no user_id, but the contract tells us
                // exactly who issued it — so resolve that character back to a
                // SeAT account and attribute the appraisal now. Sellers who
                // never log in stay null and show as a guest.
                if ($appraisal->user_id === null) {
                    $userId = $this->resolveUserIdForCharacter((int) $contract->issuer_id);
                    if ($userId !== null) {
                        $claim['user_id'] = $userId;
                    }
                }

                $appraisal->update($claim);
            }

            $savedContract = $buybackContract;
        });

        if ($savedContract === null) {
            return;
        }

        // One announcement per sighting: clean or flagged, never both.
        $eventName = empty($flags) ? 'buyback.contract.matched' : 'buyback.contract.flagged';
        $this->eventPublisher->publish($eventName, $this->buildEnvelope($savedContract, $setting, null, $appraisal));

        if (! empty($flags)) {
            Log::info('[Buyback Manager] Contract ' . $contract->contract_id . ' flagged for review: ' . implode(', ', $flags));
        }
    }

    /**
     * Signed drift of the asked price against the quote, as a percentage.
     * Positive means they asked for MORE than we quoted (the risky
     * direction); negative is in the corporation's favour.
     *
     * Returns null when there is nothing meaningful to compare against.
     */
    protected function deviationPercent(float $quoted, float $asked): ?float
    {
        if ($quoted <= 0.0) {
            return null;
        }

        return round((($asked - $quoted) / $quoted) * 100, 2);
    }

    /**
     * Decide which review flags a first-sighting contract carries.
     *
     * @return array<int, string>
     */
    protected function computeFlags(
        ContractDetail $contract,
        BuybackAppraisal $appraisal,
        BuybackSetting $setting,
        ?float $deviation,
        bool $reused
    ): array {
        $flags = [];

        if ($deviation !== null && abs($deviation) > $setting->deviationTolerance()) {
            $flags[] = BuybackContract::FLAG_PRICE_MISMATCH;
        }

        // Was the quote already old when they contracted? Compare against
        // the contract's issue date, not "now", so a late sync doesn't
        // wrongly age a quote that was fresh at the time.
        $issuedAt = $contract->date_issued ?? now();
        if ($appraisal->created_at !== null
            && $appraisal->created_at->diffInHours($issuedAt, false) > $setting->staleAfterHours()) {
            $flags[] = BuybackContract::FLAG_STALE_QUOTE;
        }

        if ($this->itemsDiffer($contract, $appraisal)) {
            $flags[] = BuybackContract::FLAG_ITEM_MISMATCH;
        }

        if ($reused) {
            $flags[] = BuybackContract::FLAG_KEY_REUSED;
        }

        if (! $this->locationResolver->isAllowed((int) ($contract->start_location_id ?? 0), $setting)) {
            $flags[] = BuybackContract::FLAG_WRONG_LOCATION;
        }

        return $flags;
    }

    /**
     * Compare what the contract actually contains against what the
     * appraisal priced. Only the items the issuer is handing over are
     * considered (is_included), and quantities are summed per type because
     * EVE splits stacks across contract lines.
     */
    protected function itemsDiffer(ContractDetail $contract, BuybackAppraisal $appraisal): bool
    {
        $contractItems = [];
        foreach ($contract->lines as $line) {
            if (! (bool) ($line->is_included ?? true)) {
                continue; // items being requested, not offered
            }
            $typeId = (int) $line->type_id;
            $contractItems[$typeId] = ($contractItems[$typeId] ?? 0) + (int) $line->quantity;
        }

        // No line data synced yet — can't prove a mismatch, so don't claim one.
        if (empty($contractItems)) {
            return false;
        }

        $appraisalItems = [];
        foreach ($appraisal->items as $item) {
            $typeId = (int) $item->type_id;
            $appraisalItems[$typeId] = ($appraisalItems[$typeId] ?? 0) + (int) $item->quantity;
        }

        // Items the appraisal excluded were never priced, so the seller
        // including them is its own (already reported) situation — ignore
        // them here rather than double-flagging.
        foreach (($appraisal->excluded_json ?? []) as $excluded) {
            unset($contractItems[(int) ($excluded['type_id'] ?? 0)]);
        }

        ksort($contractItems);
        ksort($appraisalItems);

        return $contractItems !== $appraisalItems;
    }

    /**
     * Alert directors that a contract quoted an appraisal key that didn't
     * resolve. Fires once per contract (cache-guarded so the 15-minute
     * re-sync doesn't re-alert — these contracts never get a tracked row,
     * so they'd otherwise re-enter the first-sighting path every cycle).
     */
    protected function publishUnmatchedAttempt(ContractDetail $contract, BuybackSetting $setting, string $attemptedKey): void
    {
        $guardKey = 'bb:unmatched_alerted:' . $contract->contract_id;
        if (! Cache::add($guardKey, true, now()->addDays(7))) {
            return;
        }

        $this->eventPublisher->publish('buyback.contract.unmatched', [
            'source_plugin' => 'buyback-manager',
            'schema_version' => 1,
            'event_id' => 'bb-evt-' . Str::uuid()->toString(),
            'corporation_id' => (int) $setting->corporation_id,
            'contract_id' => (int) $contract->contract_id,
            'issuer_id' => (int) $contract->issuer_id,
            'issuer_character_id' => (int) $contract->issuer_id,
            'attempted_appraisal_key' => $attemptedKey,
            'asked_price' => (float) ($contract->price ?? 0),
            'status' => (string) $contract->status,
        ]);
    }

    /**
     * Classify a status transition into a lifecycle event name.
     * Only called for non-first-sighting contracts.
     *
     * @return string|null  'completed' | 'cancelled' | null
     */
    protected function classifyEventType(?string $previousStatus, string $newStatus): ?string
    {
        $completedStates = BuybackContract::COMPLETED_STATES;
        $deadStates = ['cancelled', 'rejected', 'failed', 'deleted', 'reversed'];

        if (in_array($newStatus, $completedStates, true) && ! in_array($previousStatus ?? '', $completedStates, true)) {
            return 'completed';
        }

        if (in_array($newStatus, $deadStates, true) && ! in_array($previousStatus ?? '', $deadStates, true)) {
            return 'cancelled';
        }

        return null;
    }

    /**
     * Resolve an EVE character to the SeAT account that owns it, or null when
     * the character is not registered in SeAT (a genuine guest seller).
     *
     * Any character on an account resolves to the same user, so someone who
     * appraised on one character and contracted from an alt still lands on
     * the right person.
     */
    protected function resolveUserIdForCharacter(int $characterId): ?int
    {
        if ($characterId <= 0) {
            return null;
        }

        $userId = DB::table('refresh_tokens')
            ->where('character_id', $characterId)
            ->whereNull('deleted_at')
            ->value('user_id');

        return $userId !== null ? (int) $userId : null;
    }

    /**
     * Build the cross-plugin event envelope used by every
     * buyback.contract.* publish.
     */
    protected function buildEnvelope(
        BuybackContract $contract,
        BuybackSetting $setting,
        ?string $previousStatus,
        ?BuybackAppraisal $appraisal = null
    ): array {
        $env = [
            'source_plugin' => 'buyback-manager',
            'schema_version' => 1,
            'event_id' => 'bb-evt-' . Str::uuid()->toString(),
            'corporation_id' => (int) $setting->corporation_id,
            'contract_id' => (int) $contract->contract_id,
            'issuer_id' => (int) $contract->issuer_id,
            'issuer_character_id' => (int) $contract->issuer_id,
            'status' => (string) $contract->status,
            'previous_status' => $previousStatus,
            // The value quoted by the appraisal — what the corp expects to pay.
            'total_value' => (float) $contract->total_value,
            'total_buyback_value' => (float) $contract->total_value,
            'asked_price' => $contract->asked_price !== null ? (float) $contract->asked_price : null,
            'deviation_percent' => $contract->deviation_percent !== null ? (float) $contract->deviation_percent : null,
            'flags' => $contract->flags(),
            'items_count' => (int) $contract->items_count,
            'appraisal_public_id' => $contract->appraisal_public_id,
            'send_to' => $setting->targetDisplayLabel(),
            'issued_date' => optional($contract->issued_date)->toIso8601String(),
            'completed_date' => optional($contract->completed_date)->toIso8601String(),
            'url' => route('buyback-manager.contracts.show', $contract->id),
        ];

        if ($appraisal !== null) {
            $env['appraisal_id'] = (int) $appraisal->id;
            $env['market'] = $appraisal->market;
            $env['provider'] = $appraisal->provider;
        }

        return $env;
    }

    /**
     * Reminder pass: matched contracts still awaiting acceptance (status
     * 'outstanding') that have sat longer than their corp's auto-nudge
     * window fire buyback.contract.nudge once (guarded by nudged_at).
     * Folded into the sync cycle so it needs no extra cron. 0 disables.
     */
    protected function nudgeStaleContracts(): void
    {
        $settings = BuybackSetting::where('enabled', true)
            ->where('auto_nudge_hours', '>', 0)
            ->get()
            ->keyBy('corporation_id');

        if ($settings->isEmpty()) {
            return;
        }

        $candidates = BuybackContract::whereNull('nudged_at')
            ->where('status', 'outstanding')
            ->whereIn('corporation_id', $settings->keys()->all())
            ->get();

        foreach ($candidates as $contract) {
            $setting = $settings->get($contract->corporation_id);
            $hours = $setting ? (int) $setting->auto_nudge_hours : 0;

            if ($hours <= 0 || $contract->issued_date === null) {
                continue;
            }
            if ($contract->issued_date->copy()->addHours($hours)->isFuture()) {
                continue; // not stale yet
            }

            // Stamp first so a failure in the publish path can't loop-nudge.
            $contract->update(['nudged_at' => now()]);
            $this->eventPublisher->publish(
                'buyback.contract.nudge',
                $this->buildEnvelope($contract->fresh(), $setting, null)
            );
        }
    }

    /**
     * Sweep buyback_notification_log of entries older than retention.
     * Fires once at the start of each sync cycle — no separate cron.
     */
    protected function pruneOldNotificationLogs(): void
    {
        try {
            $cutoff = now()->subDays(self::NOTIFICATION_LOG_RETENTION_DAYS);
            $deleted = BuybackNotificationLog::where('sent_at', '<', $cutoff)->delete();
            if ($deleted > 0) {
                Log::info("[Buyback Manager] Pruned {$deleted} notification log entries older than " . self::NOTIFICATION_LOG_RETENTION_DAYS . ' days');
            }
        } catch (\Throwable $e) {
            // Best-effort housekeeping; never break sync because of it.
            Log::warning('[Buyback Manager] Notification log prune failed: ' . $e->getMessage());
        }
    }
}
