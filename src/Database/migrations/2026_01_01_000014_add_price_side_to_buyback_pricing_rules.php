<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-rule price-side override.
 *
 * Each pricing rule can now independently pick which side of the
 * market spread its percentage applies to: 'buy' (max buy order),
 * 'sell' (min sell order), or 'split' (average of both). NULL means
 * "use the BuybackSetting's default side preference" — backwards-
 * compatible for existing rows.
 *
 * Resolves the limitation that the buy/sell choice was previously
 * a single setting-wide flag. Operators can now mix policies in a
 * single corp (e.g. "Tritanium pays 95% of sell" while "Modules
 * pay 80% of buy").
 *
 * Forward-only with hasColumn guard so re-running is safe.
 */
return new class extends Migration
{
    public function up()
    {
        Schema::table('buyback_pricing_rules', function (Blueprint $table) {
            if (! Schema::hasColumn('buyback_pricing_rules', 'price_side')) {
                $table->string('price_side', 16)->nullable()->after('percentage');
            }
        });
    }

    public function down()
    {
        Schema::table('buyback_pricing_rules', function (Blueprint $table) {
            if (Schema::hasColumn('buyback_pricing_rules', 'price_side')) {
                $table->dropColumn('price_side');
            }
        });
    }
};
