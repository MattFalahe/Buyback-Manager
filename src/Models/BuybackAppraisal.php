<?php

namespace BuybackManager\Models;

use Illuminate\Database\Eloquent\Model;
use Seat\Eveapi\Models\Character\CharacterInfo;
use Seat\Eveapi\Models\Corporation\CorporationInfo;

/**
 * A stored valuation with a short public key. See the
 * create_buyback_appraisals migration for the full rationale.
 *
 * The key is single-use: once a contract claims it, matched_contract_id is
 * set and the key can no longer be claimed. That column is the only marker
 * of "used", so there is no status field to drift out of sync with it.
 */
class BuybackAppraisal extends Model
{
    protected $table = 'buyback_appraisals';

    protected $fillable = [
        'public_id',
        'corporation_id',
        'user_id',
        'issuer_character_id',
        'raw_input',
        'total_market_value',
        'total_buyback_value',
        'average_percentage',
        'market',
        'provider',
        'excluded_json',
        'matched_contract_id',
        'matched_at',
    ];

    protected $casts = [
        'corporation_id' => 'integer',
        'user_id' => 'integer',
        'issuer_character_id' => 'integer',
        'total_market_value' => 'decimal:2',
        'total_buyback_value' => 'decimal:2',
        'average_percentage' => 'decimal:2',
        'excluded_json' => 'array',
        'matched_contract_id' => 'integer',
        'matched_at' => 'datetime',
    ];

    /**
     * The raw paste can be long and is only needed on the detail page, so
     * keep it out of casual array/JSON output.
     */
    protected $hidden = ['raw_input'];

    public function items()
    {
        return $this->hasMany(BuybackAppraisalItem::class, 'appraisal_id');
    }

    public function corporation()
    {
        return $this->belongsTo(CorporationInfo::class, 'corporation_id', 'corporation_id');
    }

    /**
     * The character credited with generating this appraisal, when known.
     */
    public function issuer()
    {
        return $this->belongsTo(CharacterInfo::class, 'issuer_character_id', 'character_id');
    }

    public function contract()
    {
        return $this->belongsTo(BuybackContract::class, 'matched_contract_id');
    }

    /**
     * True once a contract has claimed this key.
     */
    public function isClaimed(): bool
    {
        return $this->matched_contract_id !== null;
    }

    /**
     * Display name for whoever generated this appraisal. Guests have no
     * SeAT account, so they are labelled rather than left blank.
     */
    public function generatedByLabel(): string
    {
        if ($this->issuer_character_id) {
            return optional($this->issuer)->name ?? ('Character #' . $this->issuer_character_id);
        }

        return 'Guest';
    }

    /**
     * Unclaimed appraisals only — the pool a contract may claim from.
     */
    public function scopeClaimable($query)
    {
        return $query->whereNull('matched_contract_id');
    }

    public function scopeForCorp($query, int $corporationId)
    {
        return $query->where('corporation_id', $corporationId);
    }
}
