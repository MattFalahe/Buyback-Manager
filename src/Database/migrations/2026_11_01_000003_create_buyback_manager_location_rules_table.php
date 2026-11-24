<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Allow-list of locations a corporation accepts buyback contracts at.
 * Each row is a region, constellation, system, NPC station or player
 * structure; a contract qualifies if it falls within ANY row, so a single
 * region entry covers everything inside it.
 *
 * An empty list means no restriction. Contracts created outside the list
 * are still tracked but flagged for review, so a director can see and
 * decline them rather than them vanishing silently.
 *
 * `haul_fee_per_m3` is taken off the quote for a contract made there, for
 * the cost of moving the goods to market. A contract inside several rules
 * pays the fee of the most specific one, so a station's own fee beats its
 * region's.
 */
class CreateBuybackManagerLocationRulesTable extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('buyback_manager_location_rules')) {
            return;
        }

        Schema::create('buyback_manager_location_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('setting_id');
            $table->string('location_type', 16); // region|constellation|system|station|structure
            // bigint: player structure ids are int64.
            $table->unsignedBigInteger('location_id');
            $table->string('location_name')->nullable();
            $table->decimal('haul_fee_per_m3', 20, 2)->nullable();
            $table->timestamps();

            $table->unique(['setting_id', 'location_type', 'location_id'], 'bb_loc_rule_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buyback_manager_location_rules');
    }
}
