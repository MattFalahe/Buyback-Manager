<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional solid square backdrop behind the public-page corp logo, so a
 * logo with transparency stands out against a busy hero background.
 * Additive + guarded; default off keeps existing pages unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buyback_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('buyback_settings', 'public_logo_bg')) {
                $table->boolean('public_logo_bg')->default(false)->after('public_logo_path');
            }
        });
    }

    public function down(): void
    {
        Schema::table('buyback_settings', function (Blueprint $table) {
            if (Schema::hasColumn('buyback_settings', 'public_logo_bg')) {
                $table->dropColumn('public_logo_bg');
            }
        });
    }
};
