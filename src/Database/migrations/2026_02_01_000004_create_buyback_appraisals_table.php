<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A stored valuation with a short public key (for example bb-5yvbtq3m).
 *
 * This is the seller-facing artefact and it replaces the old "offer".
 * Anyone can generate one from the public page without logging in: they
 * paste items, get a quote plus a shareable page, and paste the key into
 * their in-game contract's Description. When the contract is synced,
 * Buyback Manager resolves the key back to this row and compares what the
 * member asked to be paid against what was quoted.
 *
 * The quote is a REFERENCE, not a guarantee. Nothing here locks a price;
 * a mismatch or a stale quote raises a review flag on the contract rather
 * than binding the corporation to an old number.
 *
 * `user_id` is nullable on purpose: it is set when the visitor happened to
 * have a SeAT session, and left null for a guest (displayed as "Guest").
 * `matched_contract_id` doubles as the single-use marker — a key that has
 * already been claimed cannot be claimed again, so there is no separate
 * status column to drift out of sync with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buyback_appraisals', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 16)->unique();
            $table->unsignedBigInteger('corporation_id')->index();

            // Who generated it, when known. Null = guest.
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->unsignedBigInteger('issuer_character_id')->nullable();

            $table->text('raw_input')->nullable();

            $table->decimal('total_market_value', 20, 2)->default(0);
            $table->decimal('total_buyback_value', 20, 2)->default(0);
            $table->decimal('average_percentage', 6, 2)->default(0);
            $table->string('market', 64)->nullable();
            $table->string('provider', 32)->nullable();

            // Items the programme does not buy, kept so the appraisal page
            // can explain what was left out and why.
            $table->json('excluded_json')->nullable();

            // Set once a contract claims this key. Also the single-use gate.
            $table->unsignedBigInteger('matched_contract_id')->nullable()->index();
            $table->timestamp('matched_at')->nullable();

            $table->timestamps();

            // Pruning scans by age.
            $table->index('created_at', 'bb_appraisal_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buyback_appraisals');
    }
};
