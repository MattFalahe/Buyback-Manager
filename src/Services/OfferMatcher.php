<?php

namespace BuybackManager\Services;

use BuybackManager\Models\BuybackOffer;
use BuybackManager\Models\BuybackSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Seat\Eveapi\Models\Contracts\ContractDetail;

/**
 * Matches an incoming EVE contract to the offer it fulfils by the offer's
 * public_id embedded in the contract Description/Title.
 *
 * When a member publishes an offer they're told to paste the offer id
 * (e.g. "bb-zj2cc262") into the EVE contract's Description field. BB
 * extracts that id from the synced contract's `title` and looks up the
 * matching offer. This is far more robust than the old item-set
 * canonical comparison:
 *
 *   - Exact: a typo-free id pairs the contract to exactly one offer.
 *   - Survives rule changes: the offer's frozen value is used regardless
 *     of whether pricing rules changed after publish.
 *   - Removes noise: a contract with NO valid BB id in its description
 *     isn't a buyback contract and is skipped entirely (no more random
 *     / deleted item-exchange contracts cluttering the Contracts list).
 *
 * Match constraints:
 *   - public_id present in the contract title AND resolves to an offer
 *   - same corporation (the offer's program corp)
 *   - same SeAT ACCOUNT: the contract issuer is any character belonging
 *     to the same user as the offer's issuer (so a member can publish
 *     with their main and contract from an alt)
 *   - offer status = pending (a fresh, unclaimed offer)
 *
 * Returns the matched BuybackOffer, or null when the contract carries no
 * valid offer id (or it doesn't resolve to a claimable offer).
 */
class OfferMatcher
{
    /**
     * Offer public_id shape: 'bb-' + 8 chars from the disambiguated
     * alphabet (see PublicIdGenerator). Matched case-insensitively and
     * loosely (6-12 trailing chars) so minor format drift still resolves;
     * the DB lookup is the authoritative check.
     */
    private const PUBLIC_ID_PATTERN = '/bb-[a-z0-9]{6,12}/i';

    public function findByContract(ContractDetail $contract, BuybackSetting $setting): ?BuybackOffer
    {
        $issuerId = (int) $contract->issuer_id;
        if ($issuerId === 0) {
            return null;
        }

        $publicId = $this->extractPublicId((string) ($contract->title ?? ''));
        if ($publicId === null) {
            // No BB offer id in the contract description. Not a buyback
            // contract we track. (This is the common case for the noise
            // contracts the operator wanted filtered out.)
            return null;
        }

        $offer = BuybackOffer::with('items')
            ->where('public_id', $publicId)
            ->where('corporation_id', (int) $setting->corporation_id)
            ->where('status', BuybackOffer::STATUS_PENDING)
            ->first();

        if ($offer === null) {
            // The id was present but didn't resolve to a claimable offer:
            // wrong corp, already matched, expired, cancelled, or a typo.
            Log::info('[Buyback Manager] Contract ' . $contract->contract_id
                . ' references offer ' . $publicId . ' but no claimable pending offer matched.', [
                    'corporation_id' => (int) $setting->corporation_id,
                    'issuer_id' => $issuerId,
                ]);
            return null;
        }

        // Account-level issuer check: the contract may be created by ANY
        // character on the same SeAT account as the offer's issuer (member
        // publishes with their main, contracts from an alt). Prevents a
        // different player from claiming someone else's offer id.
        if (! $this->sameAccount((int) $offer->issuer_character_id, $issuerId)) {
            Log::info('[Buyback Manager] Contract ' . $contract->contract_id
                . ' references offer ' . $publicId . ' but the contract issuer is on a different account than the offer issuer.', [
                    'offer_issuer' => (int) $offer->issuer_character_id,
                    'contract_issuer' => $issuerId,
                ]);
            return null;
        }

        return $offer;
    }

    /**
     * Whether two character ids belong to the same SeAT user account.
     * Resolves each character to its user_id via refresh_tokens and
     * compares. Exact-character is a fast path. Returns false when either
     * character has no live token (can't establish the account link).
     */
    protected function sameAccount(int $charA, int $charB): bool
    {
        if ($charA === $charB) {
            return true;
        }

        $userA = DB::table('refresh_tokens')
            ->where('character_id', $charA)
            ->whereNull('deleted_at')
            ->value('user_id');
        if ($userA === null) {
            return false;
        }

        $userB = DB::table('refresh_tokens')
            ->where('character_id', $charB)
            ->whereNull('deleted_at')
            ->value('user_id');

        return $userB !== null && (int) $userA === (int) $userB;
    }

    /**
     * Pull a BB offer public_id out of a free-text contract title.
     * Returns the lowercased id (matching how PublicIdGenerator stores
     * it) or null when none is present.
     */
    public function extractPublicId(string $title): ?string
    {
        if ($title === '') {
            return null;
        }
        if (preg_match(self::PUBLIC_ID_PATTERN, $title, $m)) {
            return strtolower($m[0]);
        }
        return null;
    }
}
