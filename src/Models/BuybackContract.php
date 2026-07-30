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

    /**
     * Human wording for each flag, used in the UI and Discord embeds.
     */
    public const FLAG_LABELS = [
        self::FLAG_PRICE_MISMATCH => 'Asked price does not match the quote',
        self::FLAG_STALE_QUOTE => 'Quote was stale when the contract was made',
        self::FLAG_ITEM_MISMATCH => 'Contract items do not match the appraisal',
        self::FLAG_KEY_REUSED => 'Appraisal key had already been used',
        self::FLAG_WRONG_LOCATION => 'Created outside the accepted buyback locations',
    ];

    protected $table = 'buyback_contracts';

    protected $fillable = [
        'contract_id',
        'corporation_id',
        'issuer_id',
        'appraisal_id',
        'appraisal_public_id',
        'status',
        'total_value',
        'asked_price',
        'deviation_percent',
        'flags_json',
        'items_count',
        'issued_date',
        'completed_date',
        'nudged_at',
    ];

    protected $casts = [
        'contract_id' => 'integer',
        'corporation_id' => 'integer',
        'issuer_id' => 'integer',
        'appraisal_id' => 'integer',
        'total_value' => 'decimal:2',
        'asked_price' => 'decimal:2',
        'deviation_percent' => 'decimal:2',
        'flags_json' => 'array',
        'items_count' => 'integer',
        'issued_date' => 'datetime',
        'completed_date' => 'datetime',
        'nudged_at' => 'datetime',
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
            fn ($flag) => self::FLAG_LABELS[$flag] ?? $flag,
            $this->flags()
        );
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', ['outstanding', 'in_progress']);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeFlagged($query)
    {
        return $query->whereNotNull('flags_json')->where('flags_json', '!=', '[]');
    }
}
