<?php

namespace BuybackManager\Services;

use BuybackManager\Models\BuybackContract;
use BuybackManager\Models\BuybackContractItem;
use BuybackManager\Models\BuybackNotificationLog;
use BuybackManager\Models\BuybackOffer;
use BuybackManager\Models\BuybackSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Seat\Eveapi\Models\Contracts\CharacterContract;
use Seat\Eveapi\Models\Contracts\ContractDetail;
use Seat\Eveapi\Models\Contracts\CorporationContract;

/**
 * Syncs buyback contracts by reading from SeAT's already-fetched
 * corporation contract tables, matching each to the offer it fulfils by
 * the offer public_id embedded in the contract Description (via
 * OfferMatcher), and emitting cross-plugin events via EventPublisher
 * (fan-out to MC EventBus + BB webhooks).
 *
 * Offer-id matching (the operator asked for "only contracts created with
 * a valid offer id"):
 *   - First sighting + the contract Description contains a BB offer id
 *     that resolves to a claimable pending offer (same corp + issuer):
 *     build the BuybackContract straight from the offer's FROZEN snapshot
 *     (value + items), link it, fire buyback.contract.matched.
 *   - First sighting + a BB offer id present but it DOESN'T resolve
 *     (typo / expired / already-claimed): fire buyback.contract.unmatched
 *     as a director review signal (cache-guarded, once per contract). No
 *     tracked contract is created.
 *   - First sighting + NO BB offer id: pure noise (random/deleted
 *     item-exchange contract to the corp). Skipped silently.
 *   - Already-tracked contract: status transitions fire
 *     buyback.contract.completed / .cancelled; a rejected/failed status
 *     flips the linked offer to rejected.
 *
 * No re-appraisal happens during sync — the matched offer's frozen value
 * IS the payout, immune to market movement after publish.
 */
class ContractService
{
    /**
     * Notification log entries older than this are pruned at the
     * start of each sync cycle. No separate cron required.
     */
    private const NOTIFICATION_LOG_RETENTION_DAYS = 30;

    protected OfferMatcher $offerMatcher;

    protected OfferService $offerService;

    protected EventPublisher $eventPublisher;

    protected LocationResolver $locationResolver;

    public function __construct(
        OfferMatcher $offerMatcher,
        OfferService $offerService,
        EventPublisher $eventPublisher,
        LocationResolver $locationResolver
    ) {
        $this->offerMatcher = $offerMatcher;
        $this->offerService = $offerService;
        $this->eventPublisher = $eventPublisher;
        $this->locationResolver = $locationResolver;
    }

    public function syncContracts(): void
    {
        $this->pruneOldNotificationLogs();
        $this->offerService->expireStale();

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
                // Nothing to sync — offer stays pending for manual confirm.
                return;
            }

            // Pull the candidate contract ids from the right pivot feed.
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
                    $this->buildEnvelope($existing->fresh(), $setting, $previousStatus, $existing->offer)
                );

                if ($eventType === 'cancelled' && in_array($contract->status, ['rejected', 'failed'], true)) {
                    $this->propagateRejectionToOffer($existing);
                }
            }
            return;
        }

        // ---- First sighting: require a valid offer id in the description ----
        $offer = $this->offerMatcher->findByContract($contract, $setting);
        if ($offer === null) {
            // No claimable BB offer matched. Two sub-cases:
            $attemptedId = $this->offerMatcher->extractPublicId((string) ($contract->title ?? ''));
            if ($attemptedId !== null) {
                // The member DID reference a BB offer id, but it didn't
                // resolve to a claimable pending offer (typo, expired,
                // already-claimed, or wrong corp/issuer). Alert directors
                // to review via the contract_unmatched category — but do
                // NOT create a tracked contract (there's no offer to value
                // against). Idempotency: keyed on contract_id so a re-sync
                // of the same unmatched contract doesn't re-alert.
                $this->publishUnmatchedAttempt($contract, $setting, $attemptedId);
            }
            // No bb-id at all = pure noise (random/deleted item-exchange
            // contract to the corp). Skip silently. Either way, nothing is
            // added to the Contracts list — the operator asked for only
            // valid-offer contracts.
            return;
        }

        // Location gate: if the corp restricts buyback to specific
        // locations, reject contracts created anywhere else. The offer
        // flips to rejected (with a reason that rides the offer_rejected
        // Discord category), so the next sync won't re-match it and no
        // tracked contract is created.
        if (! $this->locationResolver->isAllowed((int) ($contract->start_location_id ?? 0), $setting)) {
            $this->offerService->rejectPending(
                $offer,
                'Contract created outside the allowed buyback locations.'
            );
            Log::info('[Buyback Manager] Offer ' . $offer->public_id . ' rejected: contract ' . $contract->contract_id . ' at disallowed location ' . ($contract->start_location_id ?? 'unknown'));
            return;
        }

        // The contract fulfils a known offer. Build the BuybackContract
        // straight from the offer's FROZEN snapshot (value + items) — no
        // re-appraisal needed, and the displayed payout is the locked value.
        $savedContract = null;
        DB::transaction(function () use ($contract, $setting, $offer, &$savedContract) {
            $buybackContract = BuybackContract::create([
                'contract_id' => $contract->contract_id,
                'corporation_id' => $setting->corporation_id,
                'issuer_id' => $contract->issuer_id,
                'offer_id' => $offer->id,
                'offer_public_id' => $offer->public_id,
                'status' => $contract->status,
                'total_value' => (float) $offer->total_buyback_value,
                'items_count' => $offer->items->count(),
                'issued_date' => $contract->date_issued,
                'completed_date' => $contract->date_completed,
            ]);

            foreach ($offer->items as $oi) {
                BuybackContractItem::create([
                    'contract_id' => $buybackContract->id,
                    'type_id' => (int) $oi->type_id,
                    'quantity' => (int) $oi->quantity,
                    'unit_price' => (float) $oi->buyback_price,
                    'total_value' => (float) $oi->total_buyback,
                    'category_id' => $oi->category_id,
                    'group_id' => $oi->group_id,
                ]);
            }

            $savedContract = $buybackContract;
        });

        if ($savedContract === null) {
            return;
        }

        // Claim the offer + announce the match. A first-sighting contract
        // ALWAYS has an offer now (we skipped the no-offer case), so this
        // is the single "new buyback contract" signal — there's no separate
        // created/unmatched event to double-deliver.
        $this->offerService->markMatched($offer, $savedContract->id);
        $this->eventPublisher->publish(
            'buyback.contract.matched',
            $this->buildEnvelope($savedContract, $setting, null, $offer)
        );
    }

    /**
     * Alert directors that a contract referenced a BB offer id that didn't
     * resolve to a claimable offer. Fires buyback.contract.unmatched once
     * per contract (cache-guarded so the 15-min re-sync doesn't re-alert —
     * these contracts never get a tracked row, so they'd otherwise re-enter
     * the first-sighting path every cycle).
     */
    protected function publishUnmatchedAttempt(ContractDetail $contract, BuybackSetting $setting, string $attemptedOfferId): void
    {
        $guardKey = 'bb:unmatched_alerted:' . $contract->contract_id;
        // Cache::add returns true only the first time within the window.
        if (! \Illuminate\Support\Facades\Cache::add($guardKey, true, now()->addDays(7))) {
            return;
        }

        $this->eventPublisher->publish('buyback.contract.unmatched', [
            'source_plugin' => 'buyback-manager',
            'schema_version' => 1,
            'event_id' => 'bb-evt-' . Str::uuid()->toString(),
            'corporation_id' => (int) $setting->corporation_id,
            'contract_id' => (int) $contract->contract_id,
            'issuer_id' => (int) $contract->issuer_id,
            'attempted_offer_id' => $attemptedOfferId,
            'status' => (string) $contract->status,
        ]);
    }

    /**
     * Classify a status transition into a lifecycle event name.
     * Only called for non-first-sighting contracts (first sightings are
     * handled by the matched/unmatched events upstream).
     *
     * @return string|null  'completed' | 'cancelled' | null
     */
    protected function classifyEventType(?string $previousStatus, string $newStatus): ?string
    {
        $completedStates = ['finished', 'finished_issuer', 'finished_contractor'];
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
     * Flip a matched offer to rejected status (no reason captured —
     * operator can add via the UI). Idempotent: a no-op if no offer
     * is linked or it's already in a terminal state.
     */
    protected function propagateRejectionToOffer(BuybackContract $contract): void
    {
        $offer = BuybackOffer::where('linked_contract_id', $contract->id)
            ->where('status', BuybackOffer::STATUS_MATCHED)
            ->first();

        if ($offer === null) {
            return;
        }

        $offer->update(['status' => BuybackOffer::STATUS_REJECTED]);
        Log::info('[Buyback Manager] Offer ' . $offer->public_id . ' marked rejected from ESI-side contract status transition');
    }

    /**
     * Build the cross-plugin event envelope used by every
     * buyback.contract.* publish. Optionally includes a reference to
     * the matched offer when one was linked.
     */
    protected function buildEnvelope(
        BuybackContract $contract,
        BuybackSetting $setting,
        ?string $previousStatus,
        ?BuybackOffer $matchedOffer = null
    ): array {
        // `total_value` is the BB-stored payout amount, which is either
        // the frozen offer value (when matched) or the fresh appraisal
        // (when unmatched). A previous version duplicated this as
        // `total_buyback_value` for "subscriber convenience" but that
        // just meant two field names carrying the same number with no
        // way to distinguish them. Subscribers should read `total_value`.
        // OfferService::envelopeFor still carries both `total_market_value`
        // AND `total_buyback_value` distinctly (because offers track
        // both at publish time).
        $env = [
            'source_plugin' => 'buyback-manager',
            'schema_version' => 1,
            'event_id' => 'bb-evt-' . Str::uuid()->toString(),
            'corporation_id' => (int) $setting->corporation_id,
            'contract_id' => (int) $contract->contract_id,
            'issuer_id' => (int) $contract->issuer_id,
            'status' => (string) $contract->status,
            'previous_status' => $previousStatus,
            'total_value' => (float) $contract->total_value,
            'items_count' => (int) $contract->items_count,
            'issued_date' => optional($contract->issued_date)->toIso8601String(),
            'completed_date' => optional($contract->completed_date)->toIso8601String(),
            'url' => route('buyback-manager.contracts.show', $contract->id),
        ];

        if ($matchedOffer !== null) {
            $env['offer_id'] = (int) $matchedOffer->id;
            $env['offer_public_id'] = (string) $matchedOffer->public_id;
            $env['mode'] = (string) $matchedOffer->mode;
        }

        return $env;
    }

    /**
     * Reminder pass: matched contracts still awaiting acceptance (status
     * 'outstanding') that have sat longer than their corp's auto-nudge
     * window fire buyback.contract.nudge once (guarded by nudged_at).
     * Folded into the sync cycle so it needs no extra cron.
     *
     * The column is named private_auto_nudge_hours for historical reasons
     * (it began as a private-mode feature); a value of 0 disables it.
     */
    protected function nudgeStaleContracts(): void
    {
        $settings = BuybackSetting::where('enabled', true)
            ->where('private_auto_nudge_hours', '>', 0)
            ->get()
            ->keyBy('corporation_id');

        if ($settings->isEmpty()) {
            return;
        }

        $candidates = BuybackContract::whereNull('nudged_at')
            ->where('status', 'outstanding')
            ->whereNotNull('offer_id')
            ->whereIn('corporation_id', $settings->keys()->all())
            ->with('offer')
            ->get();

        foreach ($candidates as $contract) {
            $setting = $settings->get($contract->corporation_id);
            $hours = $setting ? (int) $setting->private_auto_nudge_hours : 0;

            if ($hours <= 0 || $contract->issued_date === null) {
                continue;
            }
            if ($contract->issued_date->copy()->addHours($hours)->isFuture()) {
                continue; // not stale yet
            }

            // Stamp first so a failure in the publish path can't loop-nudge.
            $contract->update(['nudged_at' => now()]);
            $this->eventPublisher->publish('buyback.contract.nudge', $this->buildNudgeEnvelope($contract, $setting));
        }
    }

    /**
     * Envelope for buyback.contract.nudge (idle-contract reminder).
     */
    protected function buildNudgeEnvelope(BuybackContract $contract, BuybackSetting $setting): array
    {
        return [
            'source_plugin' => 'buyback-manager',
            'schema_version' => 1,
            'event_id' => 'bb-evt-' . Str::uuid()->toString(),
            'corporation_id' => (int) $setting->corporation_id,
            'contract_id' => (int) $contract->contract_id,
            'issuer_id' => (int) $contract->issuer_id,
            'issuer_character_id' => (int) $contract->issuer_id,
            'status' => (string) $contract->status,
            'total_value' => (float) $contract->total_value,
            'total_buyback_value' => (float) $contract->total_value,
            'items_count' => (int) $contract->items_count,
            'offer_public_id' => $contract->offer_public_id,
            'send_to' => optional($contract->offer)->sendToLabel(),
            'nudge_hours' => (int) $setting->private_auto_nudge_hours,
            'issued_date' => optional($contract->issued_date)->toIso8601String(),
            'url' => route('buyback-manager.contracts.show', $contract->id),
        ];
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
