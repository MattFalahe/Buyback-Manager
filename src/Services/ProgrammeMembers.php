<?php

namespace BuybackManager\Services;

use BuybackManager\Models\BuybackSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Who counts as one of a programme's members.
 *
 * Membership belongs to a SeAT account, not a character: an account holding
 * any character in the members' corporation or alliance is a member, and so
 * are all of its characters. Member rates are quoted on that basis. When a
 * contract arrives, a seller outside the group whose account holds a member
 * is an alt, and the contract says whose.
 */
class ProgrammeMembers
{
    /** The seller is in the members' corporation or alliance. */
    public const MEMBER = 'member';

    /** The seller is not, but their SeAT account holds a character who is. */
    public const MEMBER_ALT = 'member_alt';

    public const OUTSIDER = 'outsider';

    /** Nothing could say which alliance the seller sold from. */
    public const UNKNOWN = 'unknown';

    protected SellerAffiliation $sellers;

    public function __construct(?SellerAffiliation $sellers = null)
    {
        $this->sellers = $sellers ?? new SellerAffiliation();
    }

    public function isMember(?int $userId, BuybackSetting $setting): bool
    {
        return $userId !== null && $this->memberCharacters($userId, $setting) !== [];
    }

    /**
     * The account's characters among the members, main first.
     *
     * @return array<int, string>  character_id => name
     */
    public function memberCharacters(int $userId, BuybackSetting $setting): array
    {
        try {
            $mainId = (int) (DB::table('users')->where('id', $userId)->value('main_character_id') ?? 0);

            $query = DB::table('refresh_tokens')
                ->join('character_affiliations', 'refresh_tokens.character_id', '=', 'character_affiliations.character_id')
                ->leftJoin('character_infos', 'character_infos.character_id', '=', 'refresh_tokens.character_id')
                ->where('refresh_tokens.user_id', $userId)
                ->whereNull('refresh_tokens.deleted_at');

            if ($setting->memberScope() === BuybackSetting::MEMBERS_ALLIANCE) {
                $query->where('character_affiliations.alliance_id', (int) $setting->seller_alliance_id);
            } else {
                $query->where('character_affiliations.corporation_id', (int) $setting->corporation_id);
            }

            return $query
                ->orderByRaw('refresh_tokens.character_id = ? DESC', [$mainId])
                ->orderBy('refresh_tokens.character_id')
                ->get(['refresh_tokens.character_id', 'character_infos.name'])
                ->mapWithKeys(fn ($row) => [(int) $row->character_id => (string) ($row->name ?? ('Character #' . $row->character_id))])
                ->all();
        } catch (\Throwable $e) {
            // Without an answer the quote falls back to the public rates,
            // which never pays anyone more than they are owed.
            Log::warning('[Buyback Manager] Could not check programme membership for user ' . $userId . ': ' . $e->getMessage());

            return [];
        }
    }

    /**
     * Where a contract's seller stands with the programme, judged from the
     * corporation the contract was issued from and the seller's SeAT
     * account. The note says whose alt a member's alt is.
     *
     * @return array{standing: string, note: ?string}
     */
    public function standingOf(int $issuerId, int $issuerCorporationId, BuybackSetting $setting): array
    {
        $alliance = $setting->memberScope() === BuybackSetting::MEMBERS_ALLIANCE;

        $found = null;
        if (! $alliance) {
            if ($issuerCorporationId > 0 && $issuerCorporationId === (int) $setting->corporation_id) {
                return ['standing' => self::MEMBER, 'note' => null];
            }
        } elseif ($issuerCorporationId > 0) {
            $found = $this->sellers->allianceOf($issuerId, $issuerCorporationId);
            if ($found !== null && $found['alliance_id'] === (int) $setting->seller_alliance_id) {
                return ['standing' => self::MEMBER, 'note' => null];
            }
        }

        $note = $this->altNote($issuerId, $setting);
        if ($note !== null) {
            return ['standing' => self::MEMBER_ALT, 'note' => $note];
        }

        $placed = $issuerCorporationId > 0 && (! $alliance || $found !== null);

        return ['standing' => $placed ? self::OUTSIDER : self::UNKNOWN, 'note' => null];
    }

    /**
     * "belongs to X, who is in Y" when the seller's account holds a member,
     * naming the account's main when the main is one.
     */
    protected function altNote(int $issuerId, BuybackSetting $setting): ?string
    {
        if ($issuerId <= 0) {
            return null;
        }

        try {
            $userId = DB::table('refresh_tokens')
                ->where('character_id', $issuerId)
                ->whereNull('deleted_at')
                ->value('user_id');
        } catch (\Throwable $e) {
            Log::warning('[Buyback Manager] Could not look up the SeAT account of character ' . $issuerId . ': ' . $e->getMessage());

            return null;
        }

        if ($userId === null) {
            return null;
        }

        $members = $this->memberCharacters((int) $userId, $setting);
        unset($members[$issuerId]);
        if ($members === []) {
            return null;
        }

        $note = 'belongs to ' . reset($members) . ', who is in ' . $setting->memberGroupName();

        return mb_substr($note, 0, 255);
    }
}
