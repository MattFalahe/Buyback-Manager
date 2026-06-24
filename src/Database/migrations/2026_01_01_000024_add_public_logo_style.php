<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces the boolean public_logo_bg (white square on/off) with a
 * three-way logo background style:
 *   dark  (default) - dark rounded box behind the logo (the old default look)
 *   none            - no box, logo sits directly on the hero image
 *   light           - white square panel (the old public_logo_bg=true look)
 *
 * The old boolean column is left in place (vestigial) for backwards
 * compatibility; its value is migrated into the new style so existing
 * white-square pages keep their look.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buyback_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('buyback_settings', 'public_logo_style')) {
                $table->string('public_logo_style', 16)->default('dark')->after('public_logo_bg');
            }
        });

        // Preserve the existing white-square choice.
        if (Schema::hasColumn('buyback_settings', 'public_logo_bg')) {
            DB::table('buyback_settings')
                ->where('public_logo_bg', true)
                ->update(['public_logo_style' => 'light']);
        }
    }

    public function down(): void
    {
        Schema::table('buyback_settings', function (Blueprint $table) {
            if (Schema::hasColumn('buyback_settings', 'public_logo_style')) {
                $table->dropColumn('public_logo_style');
            }
        });
    }
};
