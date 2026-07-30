<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-item breakdown behind an appraisal: what was priced, at what market
 * value, which side of the spread, and the resulting buyback value.
 *
 * These rows are the bulk of the appraisal data, so they are pruned on a
 * shorter window than the appraisal header. That is safe because when a
 * contract claims an appraisal the item snapshot is copied onto
 * buyback_contract_items, which is the durable payout record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buyback_appraisal_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('appraisal_id')->index();
            $table->unsignedBigInteger('type_id');
            $table->string('type_name')->nullable();
            $table->unsignedBigInteger('quantity')->default(0);
            $table->decimal('market_price', 20, 2)->default(0);
            $table->decimal('buyback_price', 20, 2)->default(0);
            $table->decimal('percentage', 5, 2)->default(0);
            $table->string('price_side', 8)->nullable();
            $table->decimal('total_market', 20, 2)->default(0);
            $table->decimal('total_buyback', 20, 2)->default(0);
            $table->unsignedBigInteger('group_id')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buyback_appraisal_items');
    }
};
