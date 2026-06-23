<?php

namespace BuybackManager\Services;

use BuybackManager\Models\BuybackOffer;
use BuybackManager\Models\BuybackOfferItem;
use BuybackManager\Models\BuybackSetting;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Lifecycle owner for BuybackOffer rows.
 *
 *   publishFromAppraisal()  freeze the appraisal result into an offer
 *                           + offer-items, set expires_at, return the
 *                           persisted row. The AppraisalService result
 *                           shape is the input — caller doesn't have
 *                           to re-appraise.
 *
 *   cancel()                issuer-only, while pending. Sets status =
 *                           cancelled. No-op if already in a terminal
 *                           state.
 *
 *   reject()                designated person (or admin) annotates a
 *                           matched offer as rejected with optional
 *                           reason. ASSUMES the designated person has
 *                           already rejected the contract in-game via
 *                           EVE — this method just captures the why.
 *
 *   expireStale()           cron-driven sweep. Pending offers past
 *                           their expires_at flip to status=expired.
 *
 * Publishing emits a buyback.offer.published event via EventPublisher
 * (which fans out to MC EventBus + BB's own webhooks). The other
 * lifecycle methods emit their respective events the same way.
 */
class OfferService
{
    protected AppraisalService $appraisalService;

    protected EventPublisher $eventPublisher;

    public function __construct(AppraisalService $appraisalService, EventPublisher $eventPublisher)
    {
        $this->appraisalService = $appraisalService;
        $this->eventPublisher = $eventPublisher;
    }

    /**
     * Freeze an appraisal result into a persisted offer. Returns the
     * created BuybackOffer, or null + an error message on failure.
     *
     * @param  string  $rawInput        Original paste text — re-run
     *                                  through AppraisalService so the
     *                                  legal numbers come from live
     *                                  pricing at THIS moment, not
     *                                  whatever the user previewed
     *                                  seconds ago.
     * @param  int     $corporationId   Target corp's buyback program.
     * @param  int     $issuerCharacterId  Authenticated SeAT user's
     *                                  primary EVE character id.
     */
    public function publishFromAppraisal(string $rawInput, int $corporationId, int $issuerCharacterId): array
    {
        $setting = BuybackSetting::where('corporation_id', $corporationId)
            ->where('enabled', true)
            ->first();

        if (! $setting) {
            return ['success' => false, 'message' => 'Buyback is not enabled for this corporation'];
        }

        // Re-appraise live at publish moment. The legal payout value
        // is computed from THIS pricing snapshot, not whatever the
        // user saw on the preview a few seconds ago. Subtle UX caveat
        // documented in the offer detail page.
        $appraisal = $this->appraisalService->createAppraisal($rawInput, $corporationId);

        if (! $appraisal['success'] || empty($appraisal['items'])) {
            return [
                'success' => false,
                'message' => $appraisal['message'] ?? 'Could not build a quote from this input',
            ];
        }

        $offer = null;
        try {
            DB::transaction(function () use (&$offer, $setting, $appraisal, $rawInput, $issuerCharacterId, $corporationId) {
                $targetType = $setting->target_type ?? BuybackSetting::TARGET_MY_CORP;
                $offer = BuybackOffer::create([
                    'public_id' => PublicIdGenerator::generate(),
                    'corporation_id' => $corporationId,
                    'issuer_character_id' => $issuerCharacterId,
                    // Derive legacy mode for the visibility layer:
                    // player target = private, everything else = public.
                    'mode' => $targetType === BuybackSetting::TARGET_PLAYER
                        ? BuybackOffer::MODE_PRIVATE
                        : BuybackOffer::MODE_PUBLIC,
                    // Freeze the full target at publish time.
                    'target_type' => $targetType,
                    'target_character_id' => $targetType === BuybackSetting::TARGET_PLAYER
                        ? $setting->character_id
                        : null,
                    'target_corporation_id' => $targetType === BuybackSetting::TARGET_CORP
                        ? $setting->target_corporation_id
                        : null,
                    'target_corporation_name' => $targetType === BuybackSetting::TARGET_CORP
                        ? $setting->target_corporation_name
                        : null,
                    'status' => BuybackOffer::STATUS_PENDING,
                    'total_market_value' => (float) $appraisal['total_market_value'],
                    'total_buyback_value' => (float) $appraisal['total_buyback_value'],
                    'average_percentage' => (float) $appraisal['average_percentage'],
                    'market' => (string) $appraisal['market'],
                    'provider' => (string) ($setting->price_provider ?? 'fuzzwork'),
                    'expires_at' => Carbon::now()->addHours((int) ($setting->offer_lock_hours ?? 24)),
                    'raw_input' => $rawInput,
                ]);

                foreach ($appraisal['items'] as $item) {
                    BuybackOfferItem::create([
                        'offer_id' => $offer->id,
                        'type_id' => (int) $item['type_id'],
                        'type_name' => (string) $item['type_name'],
                        'group_id' => $item['group_id'],
                        'category_id' => $item['category_id'],
                        'quantity' => (int) $item['quantity'],
                        'market_price' => (float) $item['market_price'],
                        'buyback_price' => (float) $item['buyback_price'],
                        'percentage' => (float) $item['percentage'],
                        'total_market' => (float) $item['total_market'],
                        'total_buyback' => (float) $item['total_buyback'],
                    ]);
                }
            });
        } catch (\Throwable $e) {
            Log::error('[Buyback Manager] OfferService publish failed', [
                'error' => $e->getMessage(),
                'corporation_id' => $corporationId,
                'issuer_character_id' => $issuerCharacterId,
            ]);
            return ['success' => false, 'message' => 'Failed to persist offer: ' . $e->getMessage()];
        }

        // Publish lifecycle event AFTER the transaction commits.
        $this->eventPublisher->publish('buyback.offer.published', $this->envelopeFor($offer));

        return ['success' => true, 'offer' => $offer];
    }

    /**
     * Issuer cancels a pending offer. Returns true on success, false
     * on any non-pending status or unauthorised caller.
     *
     * Admins can bypass the issuer-match check by passing $isAdmin=true
     * (OfferController vets the admin permission upstream).
     */
    public function cancel(BuybackOffer $offer, int $byCharacterId, bool $isAdmin = false): bool
    {
        if ($offer->status !== BuybackOffer::STATUS_PENDING) {
            return false;
        }
        if (! $isAdmin && $offer->issuer_character_id !== $byCharacterId) {
            return false;
        }

        $offer->update(['status' => BuybackOffer::STATUS_CANCELLED]);
        $this->eventPublisher->publish('buyback.offer.cancelled', $this->envelopeFor($offer));
        return true;
    }

    /**
     * Designated person (or admin) records the reason a matched
     * contract was rejected in-game. Assumes the EVE-side rejection
     * has already happened; this method just captures the why.
     *
     * Returns true if the rejection was recorded.
     */
    public function reject(BuybackOffer $offer, int $byCharacterId, ?string $reason): bool
    {
        if ($offer->status !== BuybackOffer::STATUS_MATCHED) {
            return false;
        }

        $offer->update([
            'status' => BuybackOffer::STATUS_REJECTED,
            'rejected_reason' => $reason,
            'rejected_by_character_id' => $byCharacterId,
        ]);

        $this->eventPublisher->publish('buyback.offer.rejected', $this->envelopeFor($offer));
        return true;
    }

    /**
     * System-side rejection of a still-pending offer — e.g. the member's
     * contract appeared at a location outside the corp's allowed buyback
     * locations. Captures the reason and announces buyback.offer.rejected.
     * No-op unless the offer is still pending.
     */
    public function rejectPending(BuybackOffer $offer, string $reason): bool
    {
        if ($offer->status !== BuybackOffer::STATUS_PENDING) {
            return false;
        }

        $offer->update([
            'status' => BuybackOffer::STATUS_REJECTED,
            'rejected_reason' => $reason,
        ]);

        $this->eventPublisher->publish('buyback.offer.rejected', $this->envelopeFor($offer));
        return true;
    }

    /**
     * Mark an offer as matched to a buyback contract. Called by
     * OfferMatcher when ContractService syncs a new EVE contract that
     * pairs with this offer.
     */
    public function markMatched(BuybackOffer $offer, int $linkedContractId): void
    {
        $offer->update([
            'status' => BuybackOffer::STATUS_MATCHED,
            'linked_contract_id' => $linkedContractId,
        ]);

        $this->eventPublisher->publish('buyback.offer.matched', $this->envelopeFor($offer));
    }

    /**
     * Cron-driven sweep. Flips pending offers past their expires_at to
     * status=expired and publishes buyback.offer.expired for each.
     *
     * Skips offers belonging to corporations whose BuybackSetting is
     * disabled — when an admin pauses buyback for a corp, the pending
     * offers freeze in place (don't auto-expire) so the operator can
     * inspect them. Re-enable the corp and the next sweep handles them.
     *
     * Returns the count of offers transitioned.
     */
    public function expireStale(): int
    {
        $enabledCorpIds = BuybackSetting::where('enabled', true)->pluck('corporation_id')->all();
        if (empty($enabledCorpIds)) {
            return 0;
        }

        $stale = BuybackOffer::pending()
            ->where('expires_at', '<', Carbon::now())
            ->whereIn('corporation_id', $enabledCorpIds)
            ->get();

        $count = 0;
        foreach ($stale as $offer) {
            $offer->update(['status' => BuybackOffer::STATUS_EXPIRED]);
            $this->eventPublisher->publish('buyback.offer.expired', $this->envelopeFor($offer));
            $count++;
        }

        if ($count > 0) {
            Log::info("[Buyback Manager] Expired {$count} stale offers");
        }
        return $count;
    }

    /**
     * Build the standard cross-plugin event envelope for this offer.
     * Mirrors the integration-contracts shape used by ContractService.
     */
    protected function envelopeFor(BuybackOffer $offer): array
    {
        return [
            'source_plugin' => 'buyback-manager',
            'schema_version' => 1,
            'event_id' => 'bb-evt-' . \Illuminate\Support\Str::uuid()->toString(),
            'corporation_id' => (int) $offer->corporation_id,
            'offer_public_id' => $offer->public_id,
            'offer_id' => (int) $offer->id,
            'issuer_character_id' => (int) $offer->issuer_character_id,
            'target_character_id' => $offer->target_character_id !== null ? (int) $offer->target_character_id : null,
            'target_corporation_id' => $offer->target_corporation_id !== null ? (int) $offer->target_corporation_id : null,
            'target_type' => $offer->target_type,
            'send_to' => $offer->sendToLabel(),
            'mode' => $offer->mode,
            'status' => $offer->status,
            'total_market_value' => (float) $offer->total_market_value,
            'total_buyback_value' => (float) $offer->total_buyback_value,
            'average_percentage' => (float) $offer->average_percentage,
            'market' => $offer->market,
            'provider' => $offer->provider,
            'expires_at' => optional($offer->expires_at)->toIso8601String(),
            'linked_contract_id' => $offer->linked_contract_id !== null ? (int) $offer->linked_contract_id : null,
            'rejected_reason' => $offer->rejected_reason,
            'url' => $offer->detailUrl(),
        ];
    }
}
