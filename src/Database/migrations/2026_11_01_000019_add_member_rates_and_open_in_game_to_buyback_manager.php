<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Member rates, and opening a contract in the game client.
 *
 * A programme names its members once, as its own corporation or the alliance
 * already saved for Who can sell (seller_alliance_id), and the same group is
 * who "members only" lets sell. Members signed in to SeAT are quoted a bonus
 * on top of every rule; a quote remembers which rates it used, so a member
 * quote contracted by someone else can be flagged. A contract from a member's
 * alt keeps who it belongs to, as it stood when the contract was first seen.
 *
 * Every programme starts with no bonus and the game button off, which is
 * what they all did before.
 */
class AddMemberRatesAndOpenInGameToBuybackManager extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('buyback_manager_settings')) {
            if (! Schema::hasColumn('buyback_manager_settings', 'member_scope')) {
                Schema::table('buyback_manager_settings', function (Blueprint $table) {
                    $table->string('member_scope', 16)->default('corporation')->after('seller_alliance_id');
                });

                // Who can sell already named an alliance on these, and the
                // two now share one group.
                DB::table('buyback_manager_settings')
                    ->where('seller_scope', 'alliance')
                    ->update(['member_scope' => 'alliance']);
            }

            if (! Schema::hasColumn('buyback_manager_settings', 'member_bonus')) {
                Schema::table('buyback_manager_settings', function (Blueprint $table) {
                    $table->decimal('member_bonus', 5, 2)->default(0)->after('member_scope');
                });
            }

            if (! Schema::hasColumn('buyback_manager_settings', 'open_in_game')) {
                Schema::table('buyback_manager_settings', function (Blueprint $table) {
                    $table->boolean('open_in_game')->default(false)->after('member_bonus');
                });
            }
        }

        if (Schema::hasTable('buyback_manager_appraisals')
            && ! Schema::hasColumn('buyback_manager_appraisals', 'rate_tier')) {
            Schema::table('buyback_manager_appraisals', function (Blueprint $table) {
                $table->string('rate_tier', 16)->default('public')->after('average_percentage');
            });
        }

        if (Schema::hasTable('buyback_manager_contracts')
            && ! Schema::hasColumn('buyback_manager_contracts', 'seller_note')) {
            Schema::table('buyback_manager_contracts', function (Blueprint $table) {
                $table->string('seller_note', 255)->nullable()->after('flags_json');
            });
        }
    }

    public function down(): void
    {
        $drop = [
            'buyback_manager_settings' => ['open_in_game', 'member_bonus', 'member_scope'],
            'buyback_manager_appraisals' => ['rate_tier'],
            'buyback_manager_contracts' => ['seller_note'],
        ];

        foreach ($drop as $tableName => $columns) {
            foreach ($columns as $column) {
                if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, $column)) {
                    Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn($column));
                }
            }
        }
    }
}
