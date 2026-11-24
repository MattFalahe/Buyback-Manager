<?php

namespace BuybackManager\Services;

use BuybackManager\Models\BuybackAppraisal;
use BuybackManager\Models\BuybackContract;
use BuybackManager\Models\BuybackContractItem;
use BuybackManager\Models\BuybackNotificationLog;
use BuybackManager\Models\BuybackSetting;
use BuybackManager\Models\BuybackSyncStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Seat\Eveapi\Models\Contracts\ContractDetail;

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
 * flag announces through contract.flagged instead, and one whose key
 * matches no appraisal through contract.unmatched. Exactly one of the
 * three, so a webhook can subscribe to any of them without being
 * double-pinged.
 *
 * Every contract that quotes a key gets a row, including one whose key
 * matches nothing. The row is what makes each announcement fire once: a
 * cache guard does not survive a Redis restart, and SeAT keeps old
 * contracts forever.
 *
 * A contract whose Description holds no key at all is not a buyback
 * contract and is never loaded.
 */
class ContractService
{
    /**
     * Notification log entries older than this are pruned at the
     * start of each sync cycle. No separate cron required.
     */
    private const NOTIFICATION_LOG_RETENTION_DAYS = 30;

    /**
     * Contracts read per query. Keeps every query far below MySQL's limit
     * of 65,535 bound values, whatever the size of the feed.
     */
    private const CHUNK = 500;

    /**
     * SeAT refreshes contracts on its own schedule, so a contract accepted
     * in its last minutes can still read outstanding after its expiry. The
     * lapse is announced only once the expiry is this far behind.
     */
    private const EXPIRY_GRACE_MINUTES = 60;

    /**
     * How long an expired contract is still read after its lapse was
     * announced.
     */
    private const EXPIRED_WATCH_DAYS = 7;

    protected AppraisalMatcher $matcher;

    protected AppraisalRecordService $records;

    protected EventPublisher $eventPublisher;

    protected LocationResolver $locationResolver;

    protected ContractFeedHealth $feedHealth;

    protected VolumeLookup $volumes;

    public function __construct(
        AppraisalMatcher $matcher,
        AppraisalRecordService $records,
        EventPublisher $eventPublisher,
        LocationResolver $locationResolver,
        ?ContractFeedHealth $feedHealth = null,
        ?VolumeLookup $volumes = null
    ) {
        $this->matcher = $matcher;
        $this->records = $records;
        $this->eventPublisher = $eventPublisher;
        $this->locationResolver = $locationResolver;
        $this->feedHealth = $feedHealth ?? new ContractFeedHealth();
        $this->volumes = $volumes ?? new VolumeLookup();
    }

    public function syncContracts(): void
    {
        $this->pruneOldNotificationLogs();
        $this->records->prune();

        // Before this cycle's own posts, so Discord hears them in order.
        $this->eventPublisher->retryWebhooks();

        $settings = BuybackSetting::where('enabled', true)->get();

        foreach ($settings as $setting) {
            $this->syncContractsForSetting($setting);
        }

        $this->nudgeStaleContracts();
    }

    /**
     * One programme's pass: contracts already tracked, then new ones, then
     * lapses. Errors are caught here so one programme cannot stop the
     * others, and recorded on its sync status so they can be seen.
     */
    public function syncContractsForSetting(BuybackSetting $setting): void
    {
        $run = ['seen' => 0, 'new' => 0, 'updated' => 0, 'errors' => []];

        try {
            // Tracked contracts are read by id, whatever the target is now, so
            // changing the target never strands the ones already in flight.
            $this->refreshTracked($setting, $run);

            // The feed follows EVE contract visibility, see
            // BuybackSetting::resolveSyncSource. Null is an external corp
            // typed by name, whose contracts are confirmed by hand.
            $source = $setting->resolveSyncSource();
            if ($source !== null) {
                $this->readNewContracts($setting, $source, $run);
            }

            $this->announceExpired($setting, $run);
        } catch (\Throwable $e) {
            Log::error('[Buyback Manager] Contract sync error for corp ' . $setting->corporation_id . ': ' . $e->getMessage(), [
                'corporation_id' => $setting->corporation_id,
                'exception' => get_class($e),
            ]);
            $this->recordRun($setting, $run, $e->getMessage());
            $this->checkFeed($setting);

            return;
        }

        $this->recordRun($setting, $run, null);
        $this->checkFeed($setting);
    }

    /**
     * Say once when a programme can no longer see its contracts, and once
     * when it can again. Runs after the run is recorded, so a streak of
     * failed syncs includes the one that just ended.
     */
    protected function checkFeed(BuybackSetting $setting): void
    {
        try {
            $status = BuybackSyncStatus::firstOrNew(['setting_id' => $setting->id]);
            $wasFailing = (bool) $status->feed_failing;
            $failedSince = $status->feed_since;

            // An outside corporation typed by name has no feed to lose.
            // Saying its feed recovered would be just as wrong as saying it
            // failed, so the state is cleared without a word.
            $hasFeed = $setting->resolveSyncSource() !== null;
            $problem = $hasFeed ? $this->feedHealth->problem($setting, $status) : null;
            $failing = $problem !== null;

            $status->feed_failing = $failing;
            $status->feed_reason = $failing ? Str::limit($problem['reason'], 500) : null;
            $status->feed_since = $failing ? ($wasFailing && $failedSince ? $failedSince : now()) : null;
            $status->save();

            if ($failing === $wasFailing || ! $hasFeed) {
                return;
            }

            $this->eventPublisher->announce($failing ? 'buyback.feed.failing' : 'buyback.feed.recovered', [
                'corporation_id' => (int) $setting->corporation_id,
                'corporation_name' => optional($setting->corporation)->name,
                'send_to' => $setting->targetDisplayLabel(),
                'target_type' => $setting->target_type ?: BuybackSetting::TARGET_MY_CORP,
                'reason' => $problem['reason'] ?? null,
                'advice' => $problem['advice'] ?? null,
                'since' => ($failing ? $status->feed_since : $failedSince)?->toIso8601String(),
                'at' => now()->toIso8601String(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[Buyback Manager] Could not check the contract feed for corp ' . $setting->corporation_id . ': ' . $e->getMessage());
        }
    }

    /**
     * Follow the status of every tracked contract that can still change.
     *
     * An expired contract stays outstanding in SeAT for good. It is still
     * read for a while after its lapse was announced, in case SeAT was slow
     * to see it accepted, and then left alone.
     */
    protected function refreshTracked(BuybackSetting $setting, array &$run): void
    {
        BuybackContract::where('corporation_id', $setting->corporation_id)
            ->whereNotIn('status', BuybackContract::FINAL_STATES)
            ->where(function ($query) {
                $query->whereNull('expired_at')
                    ->orWhere('expired_at', '>', now()->subDays(self::EXPIRED_WATCH_DAYS));
            })
            ->chunkById(self::CHUNK, function ($rows) use ($setting, &$run) {
                $details = ContractDetail::whereIn('contract_id', $rows->pluck('contract_id')->all())
                    ->get()
                    ->keyBy('contract_id');

                foreach ($rows as $row) {
                    $detail = $details->get($row->contract_id);
                    if ($detail === null) {
                        continue; // SeAT no longer holds it, nothing to compare
                    }

                    $run['seen']++;
                    $this->guarded((int) $row->contract_id, $setting, $run, function () use ($row, $detail, $setting, &$run) {
                        if ($this->applyStatus($row, $detail, $setting)) {
                            $run['updated']++;
                        }
                    });
                }
            });
    }

    /**
     * First sightings: item exchanges to the assignee that quote something
     * shaped like a key and have no row yet. Only contracts issued while an
     * appraisal key can still resolve are looked at, since an older one
     * could only ever be unknown.
     *
     * The feed and the tracked list are subqueries, never bound id lists:
     * SeAT never prunes its contract tables, and a long-lived corporation's
     * feed passes MySQL's 65,535 placeholder limit.
     */
    protected function readNewContracts(BuybackSetting $setting, array $source, array &$run): void
    {
        [$feedTable, $feedColumn] = $source['type'] === 'character'
            ? ['character_contracts', 'character_id']
            : ['corporation_contracts', 'corporation_id'];

        $since = now()->subDays($this->records->headerRetentionDays());

        ContractDetail::query()
            ->whereIn('contract_id', function ($query) use ($feedTable, $feedColumn, $source) {
                $query->select('contract_id')->from($feedTable)->where($feedColumn, $source['feed_id']);
            })
            ->where('type', 'item_exchange')
            ->where('assignee_id', $source['assignee_id'])
            ->where('date_issued', '>=', $since)
            ->where('title', 'like', '%bb-%')
            ->whereNotIn('contract_id', function ($query) {
                $query->select('contract_id')->from('buyback_manager_contracts');
            })
            ->with('lines')
            // By id, not by offset: each contract handled gets a row, which
            // takes it out of the set the next page is read from.
            ->chunkById(self::CHUNK, function ($contracts) use ($setting, &$run) {
                foreach ($contracts as $contract) {
                    $run['seen']++;
                    $this->guarded((int) $contract->contract_id, $setting, $run, function () use ($contract, $setting, &$run) {
                        if ($this->trackNewContract($contract, $setting)) {
                            $run['new']++;
                        }
                    });
                }
            }, 'contract_id');
    }

    /**
     * One contract's work, so a malformed row is logged and skipped instead
     * of blocking every contract behind it on every run.
     */
    protected function guarded(int $contractId, BuybackSetting $setting, array &$run, callable $work): void
    {
        try {
            $work();
        } catch (\Throwable $e) {
            $run['errors'][] = 'Contract ' . $contractId . ': ' . $e->getMessage();
            Log::error('[Buyback Manager] Failed to process contract ' . $contractId
                . ' for corp ' . $setting->corporation_id . ': ' . $e->getMessage(), [
                    'contract_id' => $contractId,
                    'corporation_id' => $setting->corporation_id,
                    'exception' => get_class($e),
                ]);
        }
    }

    /**
     * Copy a tracked contract's status across and announce what it means.
     * Returns true when the status changed.
     */
    protected function applyStatus(BuybackContract $row, ContractDetail $detail, BuybackSetting $setting): bool
    {
        $newStatus = (string) $detail->status;
        $changes = [];

        if ($row->status !== $newStatus) {
            $changes['status'] = $newStatus;
        }
        if ($detail->date_completed !== null && $row->completed_date === null) {
            $changes['completed_date'] = $detail->date_completed;
        }
        if ($detail->date_expired !== null && $row->expires_at === null) {
            $changes['expires_at'] = $detail->date_expired;
        }

        if ($changes === []) {
            return false;
        }

        $previousStatus = $row->status;
        $row->update($changes);

        if (! isset($changes['status'])) {
            return false;
        }

        $eventType = $this->classifyEventType($previousStatus, $newStatus);

        // The lapse was already announced. EVE deleting the expired
        // contract later is housekeeping, not a second ending.
        if ($eventType === 'cancelled' && $row->expired_at !== null) {
            $eventType = null;
        }

        if ($eventType !== null) {
            $this->eventPublisher->publish(
                'buyback.contract.' . $eventType,
                $this->buildEnvelope($row, $setting, $previousStatus, $row->appraisal)
            );
        }

        return true;
    }

    /**
     * First sighting of a contract that quotes something shaped like a key.
     * Returns true when a row was created.
     */
    protected function trackNewContract(ContractDetail $contract, BuybackSetting $setting): bool
    {
        $match = $this->matcher->resolve($contract, $setting);

        if ($match['key'] === null) {
            // "bb-" in the Description, but nothing a key could be.
            return false;
        }

        if ($match['appraisal'] === null) {
            $row = $this->trackUnknownKey($contract, $setting, $match['key']);
            $this->eventPublisher->publish('buyback.contract.unmatched', $this->buildEnvelope($row, $setting, null));
        } else {
            /** @var BuybackAppraisal $appraisal */
            $appraisal = $match['appraisal'];
            $row = $this->trackMatched($contract, $setting, $appraisal, (bool) $match['reused']);

            $eventName = $row->isFlagged() ? 'buyback.contract.flagged' : 'buyback.contract.matched';
            $this->eventPublisher->publish($eventName, $this->buildEnvelope($row, $setting, null, $appraisal));

            if ($row->isFlagged()) {
                Log::info('[Buyback Manager] Contract ' . $contract->contract_id . ' flagged for review: ' . implode(', ', $row->flags()));
            }
        }

        // A director who accepts inside one sync interval leaves a contract
        // that is already over when first seen. Announce how it ended in the
        // same pass, or the payout is never posted or credited.
        $eventType = $this->classifyEventType(null, (string) $row->status);
        if ($eventType !== null) {
            $this->eventPublisher->publish(
                'buyback.contract.' . $eventType,
                $this->buildEnvelope($row, $setting, null, $row->appraisal)
            );
        }

        return true;
    }

    protected function trackMatched(ContractDetail $contract, BuybackSetting $setting, BuybackAppraisal $appraisal, bool $reused): BuybackContract
    {
        // The quote listed a price for each location with a haul fee; the
        // contract is held to the one for where it was made.
        $perM3 = $this->locationResolver->haulFeePerM3((int) ($contract->start_location_id ?? 0), $setting);
        $haulFee = $perM3 > 0
            ? BuybackSetting::haulFee($perM3, (float) $appraisal->total_volume, (float) $appraisal->total_buyback_value)
            : null;

        $quoted = (float) $appraisal->total_buyback_value - ($haulFee ?? 0.0);
        $asked = (float) ($contract->price ?? 0);
        $deviation = $this->deviationPercent($quoted, $asked);
        $flags = $this->computeFlags($contract, $appraisal, $setting, $deviation, $reused);
        $volume = $this->contractVolume($contract, (float) $appraisal->total_volume);

        return DB::transaction(function () use ($contract, $setting, $appraisal, $quoted, $haulFee, $asked, $deviation, $flags, $reused, $volume) {
            $row = BuybackContract::create([
                'contract_id' => $contract->contract_id,
                'corporation_id' => $setting->corporation_id,
                'issuer_id' => $contract->issuer_id,
                'appraisal_id' => $appraisal->id,
                'appraisal_public_id' => $appraisal->public_id,
                'quoted_key' => $appraisal->public_id,
                'status' => $contract->status,
                'total_value' => $quoted,
                'haul_fee' => $haulFee,
                'asked_price' => $asked,
                'deviation_percent' => $deviation,
                'flags_json' => $flags,
                'items_count' => $appraisal->items->count(),
                'total_volume' => $volume,
                'issued_date' => $contract->date_issued,
                'expires_at' => $contract->date_expired,
                'completed_date' => $contract->date_completed,
            ] + $this->targetAtSighting($setting));

            // Copy the appraisal's item snapshot onto the contract. This is
            // the durable payout record, and it's why the appraisal's own
            // item rows can be pruned on a short window.
            foreach ($appraisal->items as $item) {
                BuybackContractItem::create([
                    'contract_id' => $row->id,
                    'type_id' => (int) $item->type_id,
                    'quantity' => (int) $item->quantity,
                    'unit_price' => (float) $item->buyback_price,
                    'total_value' => (float) $item->total_buyback,
                    'category_id' => $item->category_id,
                    'group_id' => $item->group_id,
                ]);
            }

            // Claim the key so it can't be used again. A reused key is
            // already claimed by an earlier contract, so that claim keeps
            // pointing at the original.
            if (! $reused) {
                $claim = [
                    'matched_contract_id' => $row->id,
                    'matched_at' => now(),
                ];

                // Membership is DETECTED, not declared. An appraisal made
                // without signing in has no user_id, but the contract tells us
                // exactly who issued it, so resolve that character back to a
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

            return $row;
        });
    }

    /**
     * A key that resolves to nothing for this corporation: a typo, another
     * corporation's key, or one pruned. There is no quote to value it
     * against, so it is kept out of the buyback totals, but it is tracked
     * like any other so directors can review it and it alerts once.
     */
    protected function trackUnknownKey(ContractDetail $contract, BuybackSetting $setting, string $key): BuybackContract
    {
        $flags = [BuybackContract::FLAG_UNKNOWN_KEY];
        if (! $this->locationResolver->isAllowed((int) ($contract->start_location_id ?? 0), $setting)) {
            $flags[] = BuybackContract::FLAG_WRONG_LOCATION;
        }

        return BuybackContract::create([
            'contract_id' => $contract->contract_id,
            'corporation_id' => $setting->corporation_id,
            'issuer_id' => $contract->issuer_id,
            'appraisal_id' => null,
            'appraisal_public_id' => null,
            'quoted_key' => $key,
            'status' => $contract->status,
            'total_value' => 0,
            'asked_price' => (float) ($contract->price ?? 0),
            'deviation_percent' => null,
            'flags_json' => $flags,
            'items_count' => count($this->includedItems($contract)),
            'total_volume' => $this->contractVolume($contract, null),
            'issued_date' => $contract->date_issued,
            'expires_at' => $contract->date_expired,
            'completed_date' => $contract->date_completed,
        ] + $this->targetAtSighting($setting));
    }

    /**
     * The programme's target as it stands when a contract is first seen,
     * kept on the row so later events describe where this contract went.
     *
     * @return array<string, int|string|null>
     */
    protected function targetAtSighting(BuybackSetting $setting): array
    {
        return $setting->targetIdentity() + ['send_to' => $setting->targetDisplayLabel()];
    }

    /**
     * Announce each outstanding contract whose expiry has passed, once.
     */
    protected function announceExpired(BuybackSetting $setting, array &$run): void
    {
        BuybackContract::where('corporation_id', $setting->corporation_id)
            ->where('status', 'outstanding')
            ->whereNull('expired_at')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now()->subMinutes(self::EXPIRY_GRACE_MINUTES))
            ->chunkById(self::CHUNK, function ($rows) use ($setting, &$run) {
                foreach ($rows as $row) {
                    $this->guarded((int) $row->contract_id, $setting, $run, function () use ($row, $setting) {
                        // Stamped before the publish, so a failure there
                        // cannot announce the same lapse every run.
                        $row->update(['expired_at' => now()]);
                        $this->eventPublisher->publish(
                            'buyback.contract.expired',
                            $this->buildEnvelope($row, $setting, (string) $row->status, $row->appraisal)
                        );
                    });
                }
            });
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
        $issuedAt = $contract->date_issued ? Carbon::parse($contract->date_issued) : now();
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
     * What the issuer hands over, summed per type because EVE splits stacks
     * across contract lines. Lines with is_included false are items asked
     * for in return, not offered.
     *
     * @return array<int, int>  type_id => quantity
     */
    protected function includedItems(ContractDetail $contract): array
    {
        $items = [];
        foreach ($contract->lines as $line) {
            if (! (bool) ($line->is_included ?? true)) {
                continue;
            }
            $typeId = (int) $line->type_id;
            $items[$typeId] = ($items[$typeId] ?? 0) + (int) $line->quantity;
        }

        return $items;
    }

    /**
     * The m3 the contract carries, from its own items, so the figure is what
     * actually has to be hauled. Until SeAT has read the items the quote's
     * volume stands in. A volume that cannot be worked out never stops the
     * contract being tracked.
     */
    protected function contractVolume(ContractDetail $contract, ?float $quoted): ?float
    {
        $items = $this->includedItems($contract);
        if ($items === []) {
            return $quoted;
        }

        try {
            $volumes = $this->volumes->unitVolumes(array_keys($items))['volumes'];
        } catch (\Throwable $e) {
            Log::warning('[Buyback Manager] Could not measure contract ' . $contract->contract_id . ': ' . $e->getMessage());

            return $quoted;
        }

        $total = 0.0;
        foreach ($items as $typeId => $quantity) {
            $total += ($volumes[$typeId] ?? 0.0) * $quantity;
        }

        return round($total, 4);
    }

    /**
     * Compare what the contract actually contains against what the
     * appraisal priced.
     */
    protected function itemsDiffer(ContractDetail $contract, BuybackAppraisal $appraisal): bool
    {
        $contractItems = $this->includedItems($contract);

        // No line data synced yet, so a mismatch can't be proven.
        if (empty($contractItems)) {
            return false;
        }

        $appraisalItems = [];
        foreach ($appraisal->items as $item) {
            $typeId = (int) $item->type_id;
            $appraisalItems[$typeId] = ($appraisalItems[$typeId] ?? 0) + (int) $item->quantity;
        }

        // Items the appraisal excluded were never priced, so the seller
        // including them is its own (already reported) situation. Ignore
        // them here rather than double-flagging.
        foreach (($appraisal->excluded_json ?? []) as $excluded) {
            unset($contractItems[(int) ($excluded['type_id'] ?? 0)]);
        }

        ksort($contractItems);
        ksort($appraisalItems);

        return $contractItems !== $appraisalItems;
    }

    /**
     * Classify a status into the lifecycle event it means, given the
     * status before it (null on first sighting).
     *
     * @return string|null  'completed' | 'cancelled' | null
     */
    protected function classifyEventType(?string $previousStatus, string $newStatus): ?string
    {
        if (in_array($newStatus, BuybackContract::COMPLETED_STATES, true)
            && ! in_array($previousStatus ?? '', BuybackContract::COMPLETED_STATES, true)) {
            return 'completed';
        }

        if (in_array($newStatus, BuybackContract::DEAD_STATES, true)
            && ! in_array($previousStatus ?? '', BuybackContract::DEAD_STATES, true)) {
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
            // False when the key matched no appraisal: there is no quote,
            // so total_value is 0 and asked_price is all there is.
            'matched' => $contract->isMatched(),
            // The value quoted by the appraisal for where the contract was
            // made, after any haul fee there: what the corp expects to pay.
            'total_value' => (float) $contract->total_value,
            'total_buyback_value' => (float) $contract->total_value,
            'haul_fee' => $contract->haul_fee !== null ? (float) $contract->haul_fee : null,
            'asked_price' => $contract->asked_price !== null ? (float) $contract->asked_price : null,
            'deviation_percent' => $contract->deviation_percent !== null ? (float) $contract->deviation_percent : null,
            'flags' => $contract->flags(),
            'items_count' => (int) $contract->items_count,
            'appraisal_public_id' => $contract->appraisal_public_id,
            // Where this contract was sent, not where the programme sends
            // contracts now: the target may have changed since it was seen.
            'send_to' => $contract->send_to ?? $setting->targetDisplayLabel(),
            'issued_date' => optional($contract->issued_date)->toIso8601String(),
            'expires_at' => optional($contract->expires_at)->toIso8601String(),
            'completed_date' => optional($contract->completed_date)->toIso8601String(),
            'url' => route('buyback-manager.contracts.show', $contract->id),
        ] + ($contract->targetIdentity() ?? $setting->targetIdentity());

        if (! $contract->isMatched()) {
            $env['attempted_appraisal_key'] = $contract->quoted_key;
        }

        if ($appraisal !== null) {
            $env['appraisal_id'] = (int) $appraisal->id;
            $env['market'] = $appraisal->market;
            $env['provider'] = $appraisal->provider;
        }

        return $env;
    }

    /**
     * Reminder pass: contracts still awaiting acceptance that have sat
     * longer than their corp's auto-nudge window fire buyback.contract.nudge
     * once (guarded by nudged_at). A contract past its expiry can no longer
     * be accepted, so it is never nudged. Folded into the sync cycle so it
     * needs no extra cron. 0 disables.
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

        BuybackContract::whereNull('nudged_at')
            ->whereNull('expired_at')
            ->where('status', 'outstanding')
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->whereIn('corporation_id', $settings->keys()->all())
            ->chunkById(self::CHUNK, function ($rows) use ($settings) {
                foreach ($rows as $contract) {
                    $setting = $settings->get($contract->corporation_id);
                    $hours = $setting ? (int) $setting->auto_nudge_hours : 0;

                    if ($hours <= 0 || $contract->issued_date === null) {
                        continue;
                    }
                    if ($contract->issued_date->copy()->addHours($hours)->isFuture()) {
                        continue; // not stale yet
                    }

                    // Stamp first so a failure in the publish path can't loop-nudge.
                    try {
                        $contract->update(['nudged_at' => now()]);
                        $this->eventPublisher->publish(
                            'buyback.contract.nudge',
                            $this->buildEnvelope($contract, $setting, (string) $contract->status, $contract->appraisal)
                        );
                    } catch (\Throwable $e) {
                        Log::error('[Buyback Manager] Nudge failed for contract ' . $contract->contract_id . ': ' . $e->getMessage(), [
                            'contract_id' => $contract->contract_id,
                            'exception' => get_class($e),
                        ]);
                    }
                }
            });
    }

    /**
     * Keep how this run went, for Diagnostics and the Contracts page.
     * last_ok_at moves only when the feed was read; an error, of the whole
     * run or of one contract, stays visible until a run without one.
     */
    protected function recordRun(BuybackSetting $setting, array $run, ?string $fatal): void
    {
        try {
            $status = BuybackSyncStatus::firstOrNew(['setting_id' => $setting->id]);
            $status->last_run_at = now();

            $errors = $run['errors'];
            if ($fatal !== null) {
                array_unshift($errors, $fatal);
            } else {
                $status->last_ok_at = now();
                $status->contracts_seen = $run['seen'];
                $status->contracts_new = $run['new'];
                $status->contracts_updated = $run['updated'];
            }

            if ($errors === []) {
                $status->last_error = null;
                $status->failures = 0;
            } else {
                $more = count($errors) > 1 ? ' (and ' . (count($errors) - 1) . ' more)' : '';
                $status->last_error = mb_substr($errors[0], 0, 2000) . $more;
                $status->failures = (int) $status->failures + 1;
            }

            $status->save();
        } catch (\Throwable $e) {
            Log::warning('[Buyback Manager] Could not record the sync run for corp ' . $setting->corporation_id . ': ' . $e->getMessage());
        }
    }

    /**
     * Sweep buyback_manager_notification_log of entries older than retention.
     * Fires once at the start of each sync cycle, so no separate cron.
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
