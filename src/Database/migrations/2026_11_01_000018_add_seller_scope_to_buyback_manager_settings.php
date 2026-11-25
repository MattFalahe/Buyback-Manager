<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who may sell to a programme: anyone, the programme's own corporation, or
 * the corporations of one alliance. A contract from outside is flagged for a
 * director, never refused. Every programme starts at anyone, which is what
 * they all did before.
 */
class AddSellerScopeToBuybackManagerSettings extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('buyback_manager_settings')) {
            return;
        }

        if (! Schema::hasColumn('buyback_manager_settings', 'seller_scope')) {
            Schema::table('buyback_manager_settings', function (Blueprint $table) {
                $table->string('seller_scope', 16)->default('any')->after('target_corporation_name');
            });
        }

        if (! Schema::hasColumn('buyback_manager_settings', 'seller_alliance_id')) {
            Schema::table('buyback_manager_settings', function (Blueprint $table) {
                $table->unsignedBigInteger('seller_alliance_id')->nullable()->after('seller_scope');
            });
        }
    }

    public function down(): void
    {
        foreach (['seller_alliance_id', 'seller_scope'] as $column) {
            if (Schema::hasColumn('buyback_manager_settings', $column)) {
                Schema::table('buyback_manager_settings', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
}
