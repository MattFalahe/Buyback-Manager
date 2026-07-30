<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A tracked in-game contract, paired to the appraisal key its Description
 * carried.
 *
 * `total_value` is the value that was QUOTED by the appraisal, and
 * `asked_price` is the ISK the member actually set on the contract. When
 * the two disagree by more than the corporation's tolerance, or the quote
 * was stale, or the items do not line up, the difference is recorded in
 * `deviation_percent` and the reasons in `flags_json` — the contract is
 * still tracked so a director can review and decline it in game, rather
 * than it being dropped silently.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buyback_contracts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('contract_id')->unique();
            $table->unsignedBigInteger('corporation_id')->index();
            $table->unsignedBigInteger('issuer_id')->index();

            // The claimed appraisal. Null only for legacy/edge rows.
            $table->unsignedBigInteger('appraisal_id')->nullable()->index();
            $table->string('appraisal_public_id', 16)->nullable();

            $table->string('status', 32)->index();

            // Quoted by the appraisal.
            $table->decimal('total_value', 20, 2)->default(0);
            // Asked for on the contract itself.
            $table->decimal('asked_price', 20, 2)->nullable();
            // Signed drift: positive = asked MORE than quoted.
            $table->decimal('deviation_percent', 8, 2)->nullable();
            // Review reasons, e.g. ["price_mismatch","stale_quote"].
            $table->json('flags_json')->nullable();

            $table->unsignedInteger('items_count')->default(0);
            $table->timestamp('issued_date')->nullable();
            $table->timestamp('completed_date')->nullable();
            // Set when the idle-contract reminder has fired, so it fires once.
            $table->timestamp('nudged_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buyback_contracts');
    }
};
