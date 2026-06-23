<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-corp buyback location allow-list. Each row restricts buyback to a
 * region / constellation / system / station / structure. A contract is
 * accepted only when its location falls within ANY listed entry; an empty
 * list means no restriction (every location accepted), preserving the
 * pre-feature behaviour. The "group of systems/regions" the operator wants
 * is simply multiple rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('buyback_location_rules')) {
            return;
        }

        Schema::create('buyback_location_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('setting_id')->index();
            // region | constellation | system | station | structure
            $table->string('location_type', 16);
            // EVE id (regionID / constellationID / solarSystemID / stationID /
            // structure_id). bigint because citadel structure ids are int64.
            $table->unsignedBigInteger('location_id');
            // Cached display name resolved from the SDE at add time.
            $table->string('location_name')->nullable();
            $table->timestamps();

            $table->unique(['setting_id', 'location_type', 'location_id'], 'bb_loc_rule_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buyback_location_rules');
    }
};
