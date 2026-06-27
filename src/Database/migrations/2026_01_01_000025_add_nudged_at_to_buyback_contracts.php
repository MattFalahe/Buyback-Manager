<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds nudged_at to buyback_contracts so the idle-contract reminder
 * (driven by private_auto_nudge_hours) fires once per contract. Existing
 * contracts are stamped as already-nudged so turning the feature on does
 * not retro-ping a backlog of old contracts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buyback_contracts', function (Blueprint $table) {
            if (! Schema::hasColumn('buyback_contracts', 'nudged_at')) {
                $table->timestamp('nudged_at')->nullable()->after('completed_date');
            }
        });

        // Treat contracts that predate the feature as already nudged.
        DB::table('buyback_contracts')->whereNull('nudged_at')->update(['nudged_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('buyback_contracts', function (Blueprint $table) {
            if (Schema::hasColumn('buyback_contracts', 'nudged_at')) {
                $table->dropColumn('nudged_at');
            }
        });
    }
};
