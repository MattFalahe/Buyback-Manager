<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The last price this install saw for each item at each market, written
 * after every provider answer. Fuzzwork and Janice quotes are read from it
 * while fresh; for every provider it is the last word when the provider and
 * the fallback both fail.
 *
 * Keyed by market rather than region, so a Manager Core citadel market has
 * a place of its own. Each side keeps its last real price and when it was
 * seen, because a side with no orders is not a price of 0.
 */
class CreateBuybackManagerPriceCacheTable extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('buyback_manager_price_cache')) {
            return;
        }

        Schema::create('buyback_manager_price_cache', function (Blueprint $table) {
            $table->id();
            $table->string('market', 80);
            $table->unsignedBigInteger('type_id');
            $table->string('provider', 32)->nullable();
            $table->decimal('buy_price', 20, 2)->nullable();
            $table->timestamp('buy_seen_at')->nullable();
            // The average of the top 5% of buy orders, seen with buy_price.
            $table->decimal('buy_percentile', 20, 2)->nullable();
            $table->decimal('sell_price', 20, 2)->nullable();
            $table->timestamp('sell_seen_at')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();

            $table->unique(['market', 'type_id'], 'bb_price_cache_market_type_unique');
            $table->index('checked_at', 'bb_price_cache_checked_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buyback_manager_price_cache');
    }
}
