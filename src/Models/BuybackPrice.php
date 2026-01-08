<?php

namespace BuybackManager\Models;

use Illuminate\Database\Eloquent\Model;

class BuybackPrice extends Model
{
    protected $table = 'buyback_prices';

    public $timestamps = false;

    protected $fillable = [
        'type_id',
        'region_id',
        'price_type',
        'price',
        'volume',
        'updated_at',
    ];

    protected $casts = [
        'type_id' => 'integer',
        'region_id' => 'integer',
        'price' => 'decimal:2',
        'volume' => 'integer',
        'updated_at' => 'datetime',
    ];

    public static function getPrice(int $typeId, int $regionId = 10000002, string $priceType = 'sell'): ?float
    {
        $price = self::where('type_id', $typeId)
            ->where('region_id', $regionId)
            ->where('price_type', $priceType)
            ->first();

        return $price?->price;
    }
}
