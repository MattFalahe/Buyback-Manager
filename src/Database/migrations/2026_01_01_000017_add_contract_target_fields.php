<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Three-way contract target model.
 *
 * Replaces the binary public/private mode with an explicit
 * target_type that says WHERE the EVE contract should be sent:
 *
 *   'my_corp' — the member's own corporation (anyone in corp can act).
 *               Was "public". assignee = setting.corporation_id.
 *   'corp'    — a specific (possibly external) corporation. Picked from
 *               SeAT-known corps (resolvable -> target_corporation_id)
 *               OR typed free-text (display-only -> target_corporation_name,
 *               instructions-only since BB can't see an external corp's
 *               contract feed).
 *   'player'  — a specific character running the buyback. Was "private".
 *               assignee = setting.character_id.
 *
 * The legacy buyback_mode / mode columns are KEPT and derived from
 * target_type (player -> private, else public) so the offer-visibility
 * logic (authoriseViewing, isPrivate) and event payloads keep working
 * unchanged.
 *
 * Adds matching columns to buyback_offers so each offer freezes its
 * target at publish time.
 *
 * Forward-only, additive, with hasColumn guards. Backfills target_type
 * from the existing mode value.
 */
return new class extends Migration
{
    public function up()
    {
        Schema::table('buyback_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('buyback_settings', 'target_type')) {
                $table->string('target_type', 16)->default('my_corp')->after('buyback_mode');
            }
            if (! Schema::hasColumn('buyback_settings', 'target_corporation_id')) {
                $table->unsignedBigInteger('target_corporation_id')->nullable()->after('target_type');
            }
            if (! Schema::hasColumn('buyback_settings', 'target_corporation_name')) {
                $table->string('target_corporation_name', 255)->nullable()->after('target_corporation_id');
            }
        });

        Schema::table('buyback_offers', function (Blueprint $table) {
            if (! Schema::hasColumn('buyback_offers', 'target_type')) {
                $table->string('target_type', 16)->default('my_corp')->after('mode');
            }
            if (! Schema::hasColumn('buyback_offers', 'target_corporation_id')) {
                $table->unsignedBigInteger('target_corporation_id')->nullable()->after('target_character_id');
            }
            if (! Schema::hasColumn('buyback_offers', 'target_corporation_name')) {
                $table->string('target_corporation_name', 255)->nullable()->after('target_corporation_id');
            }
        });

        // Backfill target_type from the legacy mode columns.
        if (Schema::hasColumn('buyback_settings', 'target_type') && Schema::hasColumn('buyback_settings', 'buyback_mode')) {
            DB::table('buyback_settings')->where('buyback_mode', 'private')->update(['target_type' => 'player']);
            DB::table('buyback_settings')->where('buyback_mode', '!=', 'private')->update(['target_type' => 'my_corp']);
        }
        if (Schema::hasColumn('buyback_offers', 'target_type') && Schema::hasColumn('buyback_offers', 'mode')) {
            DB::table('buyback_offers')->where('mode', 'private')->update(['target_type' => 'player']);
            DB::table('buyback_offers')->where('mode', '!=', 'private')->update(['target_type' => 'my_corp']);
        }
    }

    public function down()
    {
        Schema::table('buyback_settings', function (Blueprint $table) {
            foreach (['target_type', 'target_corporation_id', 'target_corporation_name'] as $col) {
                if (Schema::hasColumn('buyback_settings', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
        Schema::table('buyback_offers', function (Blueprint $table) {
            foreach (['target_type', 'target_corporation_id', 'target_corporation_name'] as $col) {
                if (Schema::hasColumn('buyback_offers', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
