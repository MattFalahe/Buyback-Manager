<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-corp price-cache TTL.
 *
 * Adds `price_cache_ttl_minutes` so PriceProviderService can serve
 * cached Fuzzwork / Janice prices without re-hitting the upstream
 * on every appraisal + contract sync. Default 60 minutes mirrors
 * Mining Manager's cache_duration default ladder for the same
 * use case.
 *
 * Manager Core provider intentionally bypasses BB's cache entirely
 * (MC has its own `manager_core_market_prices` cache layer with a
 * 4h refresh cron; double-caching would only delay propagation).
 * The TTL column applies to Fuzzwork + Janice only.
 *
 * Forward-only with hasColumn guard.
 */
return new class extends Migration
{
    public function up()
    {
        Schema::table('buyback_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('buyback_settings', 'price_cache_ttl_minutes')) {
                $table->unsignedInteger('price_cache_ttl_minutes')->default(60)->after('fallback_to_jita');
            }
        });
    }

    public function down()
    {
        Schema::table('buyback_settings', function (Blueprint $table) {
            if (Schema::hasColumn('buyback_settings', 'price_cache_ttl_minutes')) {
                $table->dropColumn('price_cache_ttl_minutes');
            }
        });
    }
};
