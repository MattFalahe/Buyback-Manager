<?php

namespace BuybackManager\Models;

use Illuminate\Database\Eloquent\Model;
use Seat\Eveapi\Models\Character\CharacterInfo;
use Seat\Eveapi\Models\Corporation\CorporationInfo;

/**
 * A tracked in-game contract paired to the appraisal key its Description
 * carried. `total_value` is what the appraisal QUOTED; `asked_price` is
 * what the member actually put on the contract.
 */
class BuybackContract extends Model
{
    /**
     * Review reasons recorded in flags_json. A contract carrying any of
     * these is announced through the contract_flagged category instead of
     * contract_matched, so directors can check it before paying.
     */
    public const FLAG_PRICE_MISMATCH = 'price_mismatch';
    public const FLAG_STALE_QUOTE = 'stale_quote';
    public const FLAG_ITEM_MISMATCH = 'item_mismatch';
    public const FLAG_KEY_REUSED = 'key_reused';
    public const FLAG_WRONG_LOCATION = 'wrong_location';
    // The key in the Description matches no appraisal of this corporation:
    // a typo, another corporation's key, or one already pruned.
    public const FLAG_UNKNOWN_KEY = 'unknown_key';
    // The seller's corporation, or its alliance, is not one the programme
    // buys from; or no source could say which alliance it is in.
    public const FLAG_OUTSIDE_SELLER = 'outside_seller';
    public const FLAG_SELLER_UNVERIFIED = 'seller_unverified';
    // The seller is not among the members, but their SeAT account holds a
    // character who is: an alt. seller_note says whose.
    public const FLAG_MEMBER_ALT = 'member_alt';
    // The quote used member rates and nothing ties the seller to a member.
    public const FLAG_MEMBER_RATE_OUTSIDER = 'member_rate_outsider';

    /**
     * Human wording for each flag, used in the UI and Discord embeds.
     */
    public const FLAG_LABELS = [
        self::FLAG_PRICE_MISMATCH => 'Asked price does not match the quote',
        self::FLAG_STALE_QUOTE => 'Quote was stale when the contract was made',
        self::FLAG_ITEM_MISMATCH => 'Contract items do not match the appraisal',
        self::FLAG_KEY_REUSED => 'Appraisal key had already been used',
        self::FLAG_WRONG_LOCATION => 'Created outside the accepted buyback locations',
        self::FLAG_UNKNOWN_KEY => 'Appraisal key matches no appraisal',
        self::FLAG_OUTSIDE_SELLER => 'Seller is outside who can sell to this programme',
        self::FLAG_SELLER_UNVERIFIED => 'Could not tell which alliance the seller is in',
        self::FLAG_MEMBER_ALT => 'Seller is an alt of a member',
        self::FLAG_MEMBER_RATE_OUTSIDER => 'Quoted at member rates, but the seller is not a member',
    ];

    /**
     * ESI contract statuses that mean the buyback was actually completed and
     * paid. ESI never reports a plain "completed" — it distinguishes who
     * finished the contract — so anything filtering on payouts must use this
     * list rather than guessing a single value.
     */
    public const COMPLETED_STATES = ['finished', 'finished_issuer', 'finished_contractor'];

    /**
     * ESI statuses that end a contract without a payout.
     */
    public const DEAD_STATES = ['cancelled', 'rejected', 'failed', 'deleted', 'reversed'];

    /**
     * Statuses a contract never leaves, so the sync stops reading it.
     */
    public const FINAL_STATES = [...self::COMPLETED_STATES, ...self::DEAD_STATES];

    protected $table = 'buyback_manager_contracts';

    protected $fillable = [
        'contract_id',
        'corporation_id',
        'issuer_id',
        'appraisal_id',
        'appraisal_public_id',
        'quoted_key',
        'status',
        'target_type',
        'target_corporation_id',
        'target_character_id',
        'send_to',
        'total_value',
        'haul_fee',
        'asked_price',
        'deviation_percent',
        'flags_json',
        'seller_note',
        'items_count',
        'total_volume',
        'issued_date',
        'expires_at',
        'completed_date',
        'nudged_at',
        'expired_at',
    ];

    protected $casts = [
        'contract_id' => 'integer',
        'corporation_id' => 'integer',
        'issuer_id' => 'integer',
        'appraisal_id' => 'integer',
        'target_corporation_id' => 'integer',
        'target_character_id' => 'integer',
        'total_value' => 'decimal:2',
        'haul_fee' => 'decimal:2',
        'asked_price' => 'decimal:2',
        'deviation_percent' => 'decimal:2',
        'flags_json' => 'array',
        'items_count' => 'integer',
        'total_volume' => 'float',
        'issued_date' => 'datetime',
        'expires_at' => 'datetime',
        'completed_date' => 'datetime',
        'nudged_at' => 'datetime',
        'expired_at' => 'datetime',
    ];

    public function corporation()
    {
        return $this->belongsTo(CorporationInfo::class, 'corporation_id', 'corporation_id');
    }

    public function appraisal()
    {
        return $this->belongsTo(BuybackAppraisal::class, 'appraisal_id');
    }

    public function issuer()
    {
        return $this->belongsTo(CharacterInfo::class, 'issuer_id', 'character_id');
    }

    public function items()
    {
        return $this->hasMany(BuybackContractItem::class, 'contract_id');
    }

    /**
     * Who the contract was made out to when it was first seen, in the
     * fields HR Manager reads, or null for a row that recorded no target.
     *
     * @return array{target_type: string, target_corporation_id: ?int, target_character_id: ?int}|null
     */
    public function targetIdentity(): ?array
    {
        if ($this->target_type === null) {
            return null;
        }

        return [
            'target_type' => (string) $this->target_type,
            'target_corporation_id' => $this->target_corporation_id,
            'target_character_id' => $this->target_character_id,
        ];
    }

    /**
     * @return array<int, string>
     */
    public function flags(): array
    {
        return is_array($this->flags_json) ? $this->flags_json : [];
    }

    public function isFlagged(): bool
    {
        return ! empty($this->flags());
    }

    /**
     * Human descriptions of this contract's review flags.
     *
     * @return array<int, string>
     */
    public function flagLabels(): array
    {
        return array_map(
            fn ($flag) => self::flagText($flag, $this->seller_note),
            $this->flags()
        );
    }

    /**
     * One flag in words. A member's alt also says whose alt it is, as it
     * stood when the contract was first seen.
     */
    public static function flagText(string $flag, ?string $sellerNote = null): string
    {
        $label = self::FLAG_LABELS[$flag] ?? $flag;

        return $flag === self::FLAG_MEMBER_ALT && $sellerNote !== null && $sellerNote !== ''
            ? $label . ': ' . $sellerNote
            : $label;
    }

    /**
     * False for a contract whose key matched no appraisal. Such a contract
     * has no quote, so it stays out of the buyback totals.
     */
    public function isMatched(): bool
    {
        return $this->appraisal_id !== null;
    }

    /**
     * EVE never reports an expired status: the contract stays outstanding
     * with its expiry in the past. The sync stamps expired_at once it
     * announces the lapse.
     */
    public function isExpired(): bool
    {
        return $this->expired_at !== null && ! in_array($this->status, self::FINAL_STATES, true);
    }

    /**
     * The status to show: ESI's own, except a lapsed contract reads as
     * expired rather than outstanding forever.
     */
    public function displayStatus(): string
    {
        return $this->isExpired() ? 'expired' : (string) $this->status;
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', ['outstanding', 'in_progress'])->whereNull('expired_at');
    }

    public function scopeMatched($query)
    {
        return $query->whereNotNull('appraisal_id');
    }

    public function scopeUnknownKey($query)
    {
        return $query->whereNull('appraisal_id');
    }

    public function scopeCompleted($query)
    {
        return $query->whereIn('status', self::COMPLETED_STATES);
    }

    public function scopeFlagged($query)
    {
        return $query->whereNotNull('flags_json')->where('flags_json', '!=', '[]');
    }
}
