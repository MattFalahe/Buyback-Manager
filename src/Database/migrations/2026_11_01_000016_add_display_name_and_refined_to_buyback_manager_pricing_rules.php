<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A rule's own name for sellers, and whether it pays on what its items refine
 * into. EVE's names are not what miners say: a group rule on "Rare Moon
 * Asteroids" can read "Moon Goo" wherever sellers see it.
 *
 * `refined` turns refining on. The rule then refines at the programme's
 * yield, which reaches only ore, ice and moon ore, or at `refine_yield` when
 * the rule sets one of its own. Until now a yield on the rule was the only
 * switch, so a rule that already has one is marked refined.
 */
class AddDisplayNameAndRefinedToBuybackManagerPricingRules extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('buyback_manager_pricing_rules')) {
            return;
        }

        $addName = ! Schema::hasColumn('buyback_manager_pricing_rules', 'display_name');
        $addRefined = ! Schema::hasColumn('buyback_manager_pricing_rules', 'refined');

        if ($addName || $addRefined) {
            Schema::table('buyback_manager_pricing_rules', function (Blueprint $table) use ($addName, $addRefined) {
                if ($addName) {
                    $table->string('display_name', 100)->nullable()->after('type_id');
                }
                if ($addRefined) {
                    $table->boolean('refined')->default(false)->after('price_side');
                }
            });
        }

        if ($addRefined) {
            DB::table('buyback_manager_pricing_rules')->whereNotNull('refine_yield')->update(['refined' => true]);
        }
    }

    public function down(): void
    {
        foreach (['display_name', 'refined'] as $column) {
            if (Schema::hasColumn('buyback_manager_pricing_rules', $column)) {
                Schema::table('buyback_manager_pricing_rules', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
}
