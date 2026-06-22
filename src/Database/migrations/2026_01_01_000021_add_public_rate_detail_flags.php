<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional public-page rate display flags:
 *   - public_show_all_rules: list every (non-excluded) pricing rule, not
 *     just the "most wanted" featured ones.
 *   - public_show_pricing_detail: show the sourced market + each rule's
 *     price side (buy / sell / split).
 * Additive + guarded; both default off so existing pages are unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buyback_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('buyback_settings', 'public_show_all_rules')) {
                $table->boolean('public_show_all_rules')->default(false)->after('public_show_rates');
            }
            if (! Schema::hasColumn('buyback_settings', 'public_show_pricing_detail')) {
                $table->boolean('public_show_pricing_detail')->default(false)->after('public_show_all_rules');
            }
        });
    }

    public function down(): void
    {
        Schema::table('buyback_settings', function (Blueprint $table) {
            foreach (['public_show_all_rules', 'public_show_pricing_detail'] as $col) {
                if (Schema::hasColumn('buyback_settings', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
