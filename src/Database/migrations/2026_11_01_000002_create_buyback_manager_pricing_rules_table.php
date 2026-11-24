<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-item, per-group and per-category rate overrides for a corporation's
 * buyback programme. A rule for the exact item decides; otherwise an
 * exclusion on its group or category wins, then the group's rate beats the
 * category's. `priority` is kept by kind (item 30, group 20, category 10) for
 * sorting, and never decides a match.
 *
 * One rule per item, group or category per programme: adding the same one
 * again replaces it.
 *
 * `excluded` marks an item the programme does not buy at all; `featured`
 * spotlights it as "most wanted" on the public page. `refine_yield` is the
 * rule's own refining yield; the `refined` switch and the name sellers see
 * are added by 000016.
 */
class CreateBuybackManagerPricingRulesTable extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('buyback_manager_pricing_rules')) {
            return;
        }

        Schema::create('buyback_manager_pricing_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('setting_id');
            $table->string('type', 16);                 // item | group | category
            $table->unsignedBigInteger('type_id');      // typeID / groupID / categoryID
            $table->decimal('percentage', 5, 2)->nullable();
            // buy | sell | split, or null to use the corporation default.
            $table->string('price_side', 8)->nullable();
            // Percent of the materials a refinery gets out, e.g. 90.63.
            $table->decimal('refine_yield', 5, 2)->nullable();
            $table->boolean('excluded')->default(false);
            $table->boolean('featured')->default(false);
            $table->integer('priority')->default(0);
            $table->timestamps();

            $table->unique(['setting_id', 'type', 'type_id'], 'bb_pricing_rule_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buyback_manager_pricing_rules');
    }
}
