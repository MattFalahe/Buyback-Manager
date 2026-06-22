<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-item snapshot rows for buyback_offers — frozen at offer publish
 * time. Stays in sync with the offer row's status lifecycle (cascade
 * delete when an offer is hard-deleted, though normal operation only
 * status-transitions offers, never deletes them).
 *
 * Distinct from buyback_contract_items because:
 *   - Offers exist before contracts (and may never get a contract)
 *   - Frozen prices here are the legal price the corp pays out, not
 *     what's recomputed from current market state
 *   - Rejected/expired offers still keep their item history for audit
 */
return new class extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('buyback_offer_items')) {
            Schema::create('buyback_offer_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('offer_id')->constrained('buyback_offers')->onDelete('cascade');
                $table->unsignedBigInteger('type_id');
                $table->string('type_name', 200);
                $table->unsignedInteger('group_id')->nullable();
                $table->unsignedInteger('category_id')->nullable();
                $table->unsignedBigInteger('quantity')->default(0);
                $table->decimal('market_price', 20, 2)->default(0);
                $table->decimal('buyback_price', 20, 2)->default(0);
                $table->decimal('percentage', 5, 2)->default(0);
                $table->decimal('total_market', 20, 2)->default(0);
                $table->decimal('total_buyback', 20, 2)->default(0);
                $table->timestamps();

                $table->index('offer_id');
                $table->index('type_id');
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('buyback_offer_items');
    }
};
