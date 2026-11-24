<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The yield a programme refines ore, ice and moon ore at, used by every
 * refining rule that does not set its own. 90% is near the best a refinery
 * gets, so a corporation refining at less should lower it, or it pays for
 * minerals it never gets.
 */
class AddRefineYieldToBuybackManagerSettings extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('buyback_manager_settings') || Schema::hasColumn('buyback_manager_settings', 'refine_yield')) {
            return;
        }

        Schema::table('buyback_manager_settings', function (Blueprint $table) {
            $table->decimal('refine_yield', 5, 2)->default(90.00)->after('base_percentage');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('buyback_manager_settings', 'refine_yield')) {
            Schema::table('buyback_manager_settings', fn (Blueprint $table) => $table->dropColumn('refine_yield'));
        }
    }
}
