<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How each corporation's last contract sync went.
 *
 * A failing sync is caught per corporation so one bad programme cannot stop
 * the others, which means the queued job itself never fails. This row is
 * where the failure shows instead: Diagnostics warns when the last good run
 * is old or an error is set.
 *
 * A sync can also finish cleanly on tables SeAT has stopped filling, when the
 * token behind the target is gone or lost its contract scope. The feed_*
 * columns keep whether the feed looked usable at the last run, so the change
 * is announced once rather than on every sync.
 */
class CreateBuybackManagerSyncStatusTable extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('buyback_manager_sync_status')) {
            return;
        }

        Schema::create('buyback_manager_sync_status', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('setting_id');
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('last_ok_at')->nullable();
            $table->text('last_error')->nullable();
            // Runs in a row that ended in an error. Reset by a good run.
            $table->unsignedInteger('failures')->default(0);
            $table->boolean('feed_failing')->default(false);
            $table->string('feed_reason', 500)->nullable();
            $table->timestamp('feed_since')->nullable();
            // What the last good run looked at and changed.
            $table->unsignedInteger('contracts_seen')->default(0);
            $table->unsignedInteger('contracts_new')->default(0);
            $table->unsignedInteger('contracts_updated')->default(0);
            $table->timestamps();

            $table->unique('setting_id', 'bb_sync_status_setting_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buyback_manager_sync_status');
    }
}
