<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Forward-only additive migration: extend buyback_settings with
 * per-provider pricing configuration columns.
 *
 * The pre-existing `price_source` (jita | region) + `region_id` columns
 * remain — they keep their semantic as the Fuzzwork hub-region selector
 * (Fuzzwork is region-based; the user picks Jita's region by default).
 *
 * New columns layer per-provider configuration on top:
 *   price_provider       which backend to use: fuzzwork (default), janice, manager-core
 *   janice_api_key       set when price_provider=janice
 *   janice_market        jita | amarr (Janice supports those two via pricer)
 *   janice_price_method  buy | sell | split (which side to read)
 *   manager_core_market  MC market key (jita, amarr, citadel-X, ...)
 *   manager_core_variant min | max | avg | median | percentile (stats variant)
 *   fallback_to_jita     when the configured market returned 0, retry at Jita
 *
 * Forward-only with hasColumn guards so re-running on an upgrade install is safe.
 */
return new class extends Migration
{
    public function up()
    {
        Schema::table('buyback_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('buyback_settings', 'price_provider')) {
                $table->string('price_provider')->default('fuzzwork')->after('region_id');
            }
            if (!Schema::hasColumn('buyback_settings', 'janice_api_key')) {
                $table->string('janice_api_key')->nullable()->after('price_provider');
            }
            if (!Schema::hasColumn('buyback_settings', 'janice_market')) {
                $table->string('janice_market')->nullable()->after('janice_api_key');
            }
            if (!Schema::hasColumn('buyback_settings', 'janice_price_method')) {
                $table->string('janice_price_method')->nullable()->after('janice_market');
            }
            if (!Schema::hasColumn('buyback_settings', 'manager_core_market')) {
                $table->string('manager_core_market')->nullable()->after('janice_price_method');
            }
            if (!Schema::hasColumn('buyback_settings', 'manager_core_variant')) {
                $table->string('manager_core_variant')->nullable()->after('manager_core_market');
            }
            if (!Schema::hasColumn('buyback_settings', 'fallback_to_jita')) {
                $table->boolean('fallback_to_jita')->default(true)->after('manager_core_variant');
            }
        });
    }

    public function down()
    {
        Schema::table('buyback_settings', function (Blueprint $table) {
            $columns = [
                'price_provider',
                'janice_api_key',
                'janice_market',
                'janice_price_method',
                'manager_core_market',
                'manager_core_variant',
                'fallback_to_jita',
            ];
            foreach ($columns as $column) {
                if (Schema::hasColumn('buyback_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
