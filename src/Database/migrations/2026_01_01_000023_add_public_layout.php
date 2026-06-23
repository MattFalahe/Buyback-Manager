<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Public page layout choice for the rates + instructions blocks:
 *   stacked (default) - rates above instructions, full width (current look)
 *   split             - rates left, instructions right, two columns
 * Additive + guarded; default keeps the existing look.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buyback_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('buyback_settings', 'public_layout')) {
                $table->string('public_layout', 16)->default('stacked')->after('public_show_pricing_detail');
            }
        });
    }

    public function down(): void
    {
        Schema::table('buyback_settings', function (Blueprint $table) {
            if (Schema::hasColumn('buyback_settings', 'public_layout')) {
                $table->dropColumn('public_layout');
            }
        });
    }
};
