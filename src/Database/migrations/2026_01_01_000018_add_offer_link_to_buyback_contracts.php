<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link a synced contract back to the offer it fulfils.
 *
 * Contracts are now matched to offers by the offer's public_id embedded
 * in the EVE contract Description/Title (e.g. "bb-zj2cc262"), not by
 * fragile item-set comparison. A contract WITHOUT a valid BB offer id in
 * its description isn't a buyback contract BB cares about and is skipped
 * entirely (removes the noise of random/deleted item-exchange contracts
 * to the corp).
 *
 * offer_id        — FK to buyback_offers (the matched offer).
 * offer_public_id — denormalised slug for display without a join.
 *
 * Both nullable so any legacy rows (pre-offer-id matching) survive; the
 * Contracts listing filters to whereNotNull('offer_id') so legacy junk
 * no longer shows.
 *
 * Forward-only, additive, hasColumn-guarded.
 */
return new class extends Migration
{
    public function up()
    {
        Schema::table('buyback_contracts', function (Blueprint $table) {
            if (! Schema::hasColumn('buyback_contracts', 'offer_id')) {
                $table->unsignedBigInteger('offer_id')->nullable()->after('issuer_id');
                $table->index('offer_id');
            }
            if (! Schema::hasColumn('buyback_contracts', 'offer_public_id')) {
                $table->string('offer_public_id', 16)->nullable()->after('offer_id');
            }
        });
    }

    public function down()
    {
        Schema::table('buyback_contracts', function (Blueprint $table) {
            if (Schema::hasColumn('buyback_contracts', 'offer_id')) {
                $table->dropIndex(['offer_id']);
                $table->dropColumn('offer_id');
            }
            if (Schema::hasColumn('buyback_contracts', 'offer_public_id')) {
                $table->dropColumn('offer_public_id');
            }
        });
    }
};
