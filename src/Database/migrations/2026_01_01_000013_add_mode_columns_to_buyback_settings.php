<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add buyback-mode + offer-lifecycle columns to buyback_settings.
 *
 *   buyback_mode               'public' (default) | 'private'
 *                              public = contract to corp (anyone can act)
 *                              private = contract to a designated character
 *                                        (only they accept/reject)
 *
 *   offer_lock_hours           default 24. How long an offer's frozen
 *                              quote is valid for matching against a
 *                              new EVE contract. After this window
 *                              elapses, ExpirePendingOffers flips the
 *                              offer to status=expired.
 *
 *   private_auto_nudge_hours   default 48. In private mode, if the
 *                              designated character hasn't accepted or
 *                              rejected a matched contract within this
 *                              window, BB pings the queue webhook with
 *                              a reminder. Set to 0 to disable.
 *
 * NOTE on character_id semantics: the existing nullable character_id
 * column on buyback_settings is now treated as the "programme operator"
 * when buyback_mode='private'. SettingsController validates it's
 * present whenever mode is private.
 *
 * Forward-only with hasColumn guards so re-running is safe.
 */
return new class extends Migration
{
    public function up()
    {
        Schema::table('buyback_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('buyback_settings', 'buyback_mode')) {
                $table->string('buyback_mode', 16)->default('public')->after('fallback_to_jita');
            }
            if (! Schema::hasColumn('buyback_settings', 'offer_lock_hours')) {
                $table->unsignedInteger('offer_lock_hours')->default(24)->after('buyback_mode');
            }
            if (! Schema::hasColumn('buyback_settings', 'private_auto_nudge_hours')) {
                $table->unsignedInteger('private_auto_nudge_hours')->default(48)->after('offer_lock_hours');
            }
        });
    }

    public function down()
    {
        Schema::table('buyback_settings', function (Blueprint $table) {
            foreach (['buyback_mode', 'offer_lock_hours', 'private_auto_nudge_hours'] as $col) {
                if (Schema::hasColumn('buyback_settings', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
