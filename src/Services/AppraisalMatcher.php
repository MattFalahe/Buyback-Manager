<?php

namespace BuybackManager\Services;

use BuybackManager\Models\BuybackAppraisal;
use BuybackManager\Models\BuybackSetting;
use Seat\Eveapi\Models\Contracts\ContractDetail;

/**
 * Resolves an incoming EVE contract to the appraisal whose key its
 * Description carries.
 *
 * Sellers are told to paste their appraisal key (for example
 * "bb-zj2cc262") into the contract's Description. Buyback Manager reads
 * that from the synced contract's `title` and looks the appraisal up.
 *
 * Deliberately NO account check: an appraisal can be generated with no
 * login at all, so there is no account to compare against. Abuse is
 * handled downstream by flags instead — a contract whose items or asked
 * price do not line up with the appraisal is flagged for review rather
 * than being silently accepted or silently dropped.
 *
 * A contract with no key in its Description is not a buyback contract and
 * is ignored entirely, which is what keeps unrelated contracts out of the
 * list.
 */
class AppraisalMatcher
{
    /**
     * Appraisal key shape: 'bb-' + 8 chars from the disambiguated alphabet
     * (see PublicIdGenerator). Matched case-insensitively and loosely
     * (6-12 trailing chars) so minor format drift still resolves; the DB
     * lookup is the authoritative check.
     */
    private const KEY_PATTERN = '/bb-[a-z0-9]{6,12}/i';

    protected AppraisalRecordService $records;

    public function __construct(AppraisalRecordService $records)
    {
        $this->records = $records;
    }

    /**
     * Look for an appraisal key in a contract Description and resolve it.
     *
     * Outcomes:
     *   ['key' => null,  'appraisal' => null, 'reused' => false]
     *       no key present — not a buyback contract, ignore it
     *   ['key' => 'bb-x','appraisal' => null, 'reused' => false]
     *       key present but unknown to this corporation (typo, expired,
     *       pruned) — a review signal, nothing to value against
     *   ['key' => 'bb-x','appraisal' => Appraisal, 'reused' => false]
     *       clean claim
     *   ['key' => 'bb-x','appraisal' => Appraisal, 'reused' => true]
     *       the key was already claimed by another contract — still
     *       resolved so the contract can be valued, but flagged
     *
     * @return array{key: ?string, appraisal: ?BuybackAppraisal, reused: bool}
     */
    public function resolve(ContractDetail $contract, BuybackSetting $setting): array
    {
        $key = $this->extractKey((string) ($contract->title ?? ''));
        if ($key === null) {
            return ['key' => null, 'appraisal' => null, 'reused' => false];
        }

        $corporationId = (int) $setting->corporation_id;

        $appraisal = $this->records->findClaimable($key, $corporationId);
        if ($appraisal !== null) {
            $appraisal->load('items');

            return ['key' => $key, 'appraisal' => $appraisal, 'reused' => false];
        }

        // Not claimable. Either it never existed for this corporation, or
        // it has already been used — the two need different handling, so
        // check which.
        $existing = $this->records->findAny($key, $corporationId);
        if ($existing !== null) {
            $existing->load('items');

            return ['key' => $key, 'appraisal' => $existing, 'reused' => true];
        }

        return ['key' => $key, 'appraisal' => null, 'reused' => false];
    }

    /**
     * Extract the first appraisal key from a contract Description, or null
     * when there is none. Normalised to lower case to match storage.
     */
    public function extractKey(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return null;
        }

        if (preg_match(self::KEY_PATTERN, $text, $matches) === 1) {
            return strtolower($matches[0]);
        }

        return null;
    }
}
