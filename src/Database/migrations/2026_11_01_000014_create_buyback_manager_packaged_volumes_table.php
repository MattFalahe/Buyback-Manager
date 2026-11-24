<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What an item takes up packaged, for the haul fee and the m3 on quotes and
 * contracts.
 *
 * SeAT's SDE tables keep only the assembled volume, and a ship, a capital
 * module or a cargo container packs far smaller. The packaged figure comes
 * from the copy of CCP's static data SeAT downloads (source sde), for every
 * type in a group where any type repackages, so a type of such a group that
 * is missing here is newer than that copy. Janice answers and ESI fill in
 * what the copy does not have.
 */
class CreateBuybackManagerPackagedVolumesTable extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('buyback_manager_packaged_volumes')) {
            return;
        }

        Schema::create('buyback_manager_packaged_volumes', function (Blueprint $table) {
            $table->unsignedBigInteger('type_id')->primary();
            $table->unsignedInteger('group_id')->nullable();
            $table->decimal('packaged_volume', 20, 4);
            // sde, janice or esi
            $table->string('source', 10)->default('esi');
            // The static data build an sde row was read from.
            $table->unsignedInteger('sde_build')->nullable();
            $table->timestamp('fetched_at')->nullable();

            $table->index(['source', 'group_id'], 'bb_packaged_volume_source_group');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buyback_manager_packaged_volumes');
    }
}
