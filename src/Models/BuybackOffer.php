<?php

namespace BuybackManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Seat\Eveapi\Models\Character\CharacterInfo;
use Seat\Eveapi\Models\Corporation\CorporationInfo;

/**
 * Frozen buyback quote with a sharing URL.
 *
 * Status lifecycle:
 *   pending   -> matched   contract sync paired this offer to an EVE contract
 *   pending   -> expired   ExpirePendingOffers flipped it past expires_at
 *   pending   -> cancelled issuer cancelled before any contract
 *   matched   -> rejected  designated person rejected the linked contract
 *                          (BB captures optional rejected_reason)
 *
 * public_id is an 8-char unguessable token — the canonical URL slug.
 */
class BuybackOffer extends Model
{
    public const MODE_PUBLIC = 'public';
    public const MODE_PRIVATE = 'private';

    public const STATUS_PENDING = 'pending';
    public const STATUS_MATCHED = 'matched';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';

    protected $table = 'buyback_offers';

    /**
     * Hide raw_input from default array/JSON serialization. The field
     * stores the original paste used to generate the offer; corp members
     * viewing a public-mode offer can see the priced items table without
     * also seeing whatever raw text the issuer typed (which may include
     * notes/typos/personal data they didn't intend to share). The
     * issuer's own admin views can still access it explicitly via
     * $offer->raw_input on the model instance.
     */
    protected $hidden = ['raw_input'];

    public const TARGET_MY_CORP = 'my_corp';
    public const TARGET_CORP = 'corp';
    public const TARGET_PLAYER = 'player';

    protected $fillable = [
        'public_id',
        'corporation_id',
        'issuer_character_id',
        'mode',
        'target_type',
        'target_character_id',
        'target_corporation_id',
        'target_corporation_name',
        'status',
        'total_market_value',
        'total_buyback_value',
        'average_percentage',
        'market',
        'provider',
        'expires_at',
        'linked_contract_id',
        'rejected_reason',
        'rejected_by_character_id',
        'raw_input',
    ];

    protected $casts = [
        'corporation_id' => 'integer',
        'issuer_character_id' => 'integer',
        'target_character_id' => 'integer',
        'target_corporation_id' => 'integer',
        'total_market_value' => 'decimal:2',
        'total_buyback_value' => 'decimal:2',
        'average_percentage' => 'decimal:2',
        'expires_at' => 'datetime',
        'linked_contract_id' => 'integer',
        'rejected_by_character_id' => 'integer',
    ];

    // ------------------------------------------------------------
    // Relationships
    // ------------------------------------------------------------

    public function items(): HasMany
    {
        return $this->hasMany(BuybackOfferItem::class, 'offer_id');
    }

    public function corporation(): BelongsTo
    {
        return $this->belongsTo(CorporationInfo::class, 'corporation_id', 'corporation_id');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(CharacterInfo::class, 'issuer_character_id', 'character_id');
    }

    public function targetCharacter(): BelongsTo
    {
        return $this->belongsTo(CharacterInfo::class, 'target_character_id', 'character_id');
    }

    public function targetCorporation(): BelongsTo
    {
        return $this->belongsTo(CorporationInfo::class, 'target_corporation_id', 'corporation_id');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(CharacterInfo::class, 'rejected_by_character_id', 'character_id');
    }

    public function linkedContract(): BelongsTo
    {
        return $this->belongsTo(BuybackContract::class, 'linked_contract_id');
    }

    // ------------------------------------------------------------
    // Scopes
    // ------------------------------------------------------------

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeMatchable($query)
    {
        return $query
            ->where('status', self::STATUS_PENDING)
            ->where('expires_at', '>=', now());
    }

    public function scopeForCorp($query, int $corporationId)
    {
        return $query->where('corporation_id', $corporationId);
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isPrivate(): bool
    {
        return $this->mode === self::MODE_PRIVATE;
    }

    /**
     * The "send to" name shown in the contract instructions, resolved
     * per the frozen target_type.
     */
    public function sendToLabel(): string
    {
        return match ($this->target_type ?? self::TARGET_MY_CORP) {
            self::TARGET_PLAYER => optional($this->targetCharacter)->name
                ?? ('character #' . $this->target_character_id),
            self::TARGET_CORP => $this->target_corporation_name
                ?: (optional($this->targetCorporation)->name
                    ?? ('corporation #' . $this->target_corporation_id)),
            default => optional($this->corporation)->name
                ?? ('corporation #' . $this->corporation_id),
        };
    }

    /**
     * Whether this offer's target can't be auto-confirmed by BB. True for
     * a free-text external corporation (target_type=corp with no resolved
     * target_corporation_id): the contract is sent to a corp whose feed
     * SeAT doesn't sync, so the offer stays pending and the operator
     * confirms manually.
     */
    public function isInstructionsOnly(): bool
    {
        return ($this->target_type ?? self::TARGET_MY_CORP) === self::TARGET_CORP
            && empty($this->target_corporation_id);
    }

    public function detailUrl(): string
    {
        return route('buyback-manager.offers.show', $this->public_id);
    }
}
