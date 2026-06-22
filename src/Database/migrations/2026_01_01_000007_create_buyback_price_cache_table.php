<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Local price cache for Buyback Manager.
 *
 * Standalone caching layer for prices fetched from any provider
 * (Fuzzwork, Janice, or — opportunistically — Manager Core). Mirrors
 * Mining Manager's mining_price_cache shape so operators see a
 * familiar table layout.
 *
 * Why a local cache despite MC having one?
 *   - When MC is absent, we still need a cache.
 *   - When MC is present but slow/cold, Jita-fallback writes its
 *     results here so subsequent reads don't repeat the work.
 *   - Operators can inspect / truncate this table without touching
 *     MC's `manager_core_market_prices`.
 *
 * Key: (type_id, region_id) — same item priced at different regions
 * gets distinct rows. Default region 10000002 (The Forge / Jita).
 */
return new class extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('buyback_price_cache')) {
            Schema::create('buyback_price_cache', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('type_id');
                $table->unsignedBigInteger('region_id');
                $table->decimal('sell_price', 20, 2)->default(0);
                $table->decimal('buy_price', 20, 2)->default(0);
                $table->decimal('average_price', 20, 2)->default(0);
                $table->timestamp('cached_at')->useCurrent();
                $table->timestamps();

                $table->unique(['type_id', 'region_id']);
                $table->index('cached_at');
                $table->index('type_id');
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('buyback_price_cache');
    }
};
