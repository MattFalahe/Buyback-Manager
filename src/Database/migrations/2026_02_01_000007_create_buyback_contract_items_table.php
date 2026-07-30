<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durable per-line record of what a tracked contract contained and what it
 * was valued at. Copied from the claimed appraisal at match time, which is
 * why the appraisal's own item rows can be pruned on a short window.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buyback_contract_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('contract_id')->index();
            $table->unsignedBigInteger('type_id');
            $table->unsignedBigInteger('quantity')->default(0);
            $table->decimal('unit_price', 20, 2)->default(0);
            $table->decimal('total_value', 20, 2)->default(0);
            $table->unsignedBigInteger('category_id')->nullable();
            $table->unsignedBigInteger('group_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buyback_contract_items');
    }
};
