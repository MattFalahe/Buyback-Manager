<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A tracked in-game contract, paired to the appraisal key its Description
 * carried.
 *
 * `total_value` is the value that was QUOTED by the appraisal for the place
 * the contract was made, after the haul fee there (kept in `haul_fee`), and
 * `asked_price` is the ISK the member actually set on the contract. When
 * the two disagree by more than the corporation's tolerance, or the quote
 * was stale, or the items do not line up, the difference is recorded in
 * `deviation_percent` and the reasons in `flags_json`. The contract is
 * still tracked so a director can review and decline it in game, rather
 * than it being dropped silently.
 *
 * A contract quoting a key that resolves to no appraisal is tracked too,
 * with no appraisal and the key as written in `quoted_key`. Keeping the row
 * is what makes its alert fire once rather than on every sync.
 *
 * EVE has no expired status: an expired contract stays "outstanding" with
 * its expiry date in the past. `expires_at` is copied from the contract so
 * the lapse can be seen, and `expired_at` marks that it was announced.
 *
 * The target is copied from the programme when the contract is first seen,
 * and every later event reads it from here. A programme whose target
 * changes while contracts are open would otherwise say they were sent
 * somewhere else, and HR Manager would credit them as if they had been.
 */
class CreateBuybackManagerContractsTable extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('buyback_manager_contracts')) {
            return;
        }

        Schema::create('buyback_manager_contracts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('contract_id')->unique();
            $table->unsignedBigInteger('corporation_id');
            $table->unsignedBigInteger('issuer_id')->index();
            // The claimed appraisal. Null when the quoted key matched none.
            $table->unsignedBigInteger('appraisal_id')->nullable()->index();
            $table->string('appraisal_public_id', 16)->nullable();
            // The key as it appeared in the contract Description.
            $table->string('quoted_key', 16)->nullable();
            $table->string('status', 32);
            // Who the contract was made out to: my_corp, corp or player, the
            // ids HR Manager credits by, and the name shown as "Send to".
            $table->string('target_type', 16)->nullable();
            $table->unsignedBigInteger('target_corporation_id')->nullable();
            $table->unsignedBigInteger('target_character_id')->nullable();
            $table->string('send_to')->nullable();
            // Quoted by the appraisal, less the haul fee where it was made.
            $table->decimal('total_value', 20, 2)->default(0);
            $table->decimal('haul_fee', 20, 2)->nullable();
            // Asked for on the contract itself.
            $table->decimal('asked_price', 20, 2)->nullable();
            // Signed drift: positive = asked MORE than quoted.
            $table->decimal('deviation_percent', 8, 2)->nullable();
            // Review reasons, e.g. ["price_mismatch","stale_quote"].
            $table->json('flags_json')->nullable();
            $table->unsignedInteger('items_count')->default(0);
            // The m3 the contract carries, from its own items, or from the
            // quote while SeAT has not read them. Null before anything did.
            $table->decimal('total_volume', 24, 4)->nullable();
            $table->timestamp('issued_date')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('completed_date')->nullable();
            // Set when the idle-contract reminder has fired, so it fires once.
            $table->timestamp('nudged_at')->nullable();
            // Set when the lapse was announced, so it is announced once.
            $table->timestamp('expired_at')->nullable();
            $table->timestamps();

            // The sync, the lapse and reminder passes read one corporation's
            // contracts by status; the Contracts page lists them newest first;
            // Statistics reads completed ones by completion date.
            $table->index(['corporation_id', 'status'], 'bb_contract_corp_status');
            $table->index(['corporation_id', 'issued_date'], 'bb_contract_corp_issued');
            $table->index(['status', 'completed_date'], 'bb_contract_status_completed');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buyback_manager_contracts');
    }
}
