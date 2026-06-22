<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Offer-based buyback workflow — primary table.
 *
 * Each offer is a frozen quote: the price snapshot from
 * PriceProviderService at publish time, locked for a configurable
 * window (offer_lock_hours from buyback_settings). When the member
 * creates an EVE contract within the window, OfferMatcher pairs it
 * to the offer; if the lock expires without a contract, the offer
 * status flips to 'expired' via the ExpirePendingOffers job.
 *
 * Status lifecycle:
 *   pending -> matched      (contract sync paired to this offer)
 *   pending -> expired      (lock_hours elapsed, no contract)
 *   pending -> cancelled    (issuer cancelled before contract)
 *   matched -> rejected     (designated person rejected via EVE, BB
 *                            captures optional reason)
 *
 * public_id is an 8-char unguessable token (Str::random with a
 * disambiguated alphabet) — the canonical sharing URL for the offer.
 */
return new class extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('buyback_offers')) {
            Schema::create('buyback_offers', function (Blueprint $table) {
                $table->id();
                $table->string('public_id', 16)->unique();
                $table->unsignedBigInteger('corporation_id');
                $table->unsignedBigInteger('issuer_character_id');

                $table->string('mode', 16)->default('public');             // public | private
                $table->unsignedBigInteger('target_character_id')->nullable(); // private mode

                $table->string('status', 16)->default('pending');
                // pending | matched | expired | rejected | cancelled

                $table->decimal('total_market_value', 20, 2)->default(0);
                $table->decimal('total_buyback_value', 20, 2)->default(0);
                $table->decimal('average_percentage', 5, 2)->default(0);

                $table->string('market', 64);
                $table->string('provider', 32);

                $table->timestamp('expires_at')->nullable();
                $table->unsignedBigInteger('linked_contract_id')->nullable();
                $table->text('rejected_reason')->nullable();
                $table->unsignedBigInteger('rejected_by_character_id')->nullable();
                $table->text('raw_input')->nullable();

                $table->timestamps();

                $table->index(['corporation_id', 'status']);
                $table->index(['expires_at', 'status']);
                $table->index('linked_contract_id');
                $table->index('issuer_character_id');
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('buyback_offers');
    }
};
