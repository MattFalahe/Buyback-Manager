<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Local price cache, written through after every successful provider fetch
 * and read as the last-resort source when the upstream provider throws, so
 * a sync never returns zeros just because the network blipped.
 *
 * Manager Core bypasses this table and uses its own cache.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buyback_price_cache', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('type_id');
            $table->unsignedBigInteger('region_id');
            $table->decimal('sell_price', 20, 2)->default(0);
            $table->decimal('buy_price', 20, 2)->default(0);
            $table->decimal('average_price', 20, 2)->default(0);
            $table->timestamp('cached_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['type_id', 'region_id'], 'bb_price_cache_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buyback_price_cache');
    }
};
