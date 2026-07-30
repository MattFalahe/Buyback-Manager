<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-item, per-group and per-category rate overrides for a corporation's
 * buyback programme. Precedence is item > group > category > base, and is
 * enforced by the priority assigned on save (item 30, group 20,
 * category 10) rather than by operator input.
 *
 * `excluded` marks an item the programme does not buy at all; `featured`
 * spotlights it as "most wanted" on the public page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buyback_pricing_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('setting_id')->index();
            $table->string('type', 16);                 // item | group | category
            $table->unsignedBigInteger('type_id');      // typeID / groupID / categoryID
            $table->decimal('percentage', 5, 2)->nullable();
            // buy | sell | split, or null to use the corporation default.
            $table->string('price_side', 8)->nullable();
            $table->boolean('excluded')->default(false);
            $table->boolean('featured')->default(false);
            $table->integer('priority')->default(0);
            $table->timestamps();

            $table->index(['setting_id', 'type', 'type_id'], 'bb_pricing_rule_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buyback_pricing_rules');
    }
};
