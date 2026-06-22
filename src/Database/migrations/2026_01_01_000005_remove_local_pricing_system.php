<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Drop buyback_prices table - now using Manager Core for all pricing
        if (Schema::hasTable('buyback_prices')) {
            Schema::dropIfExists('buyback_prices');
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        // Recreate buyback_prices table for rollback
        if (!Schema::hasTable('buyback_prices')) {
            Schema::create('buyback_prices', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('type_id');
                $table->unsignedInteger('region_id');
                $table->enum('price_type', ['buy', 'sell']);
                $table->decimal('price', 20, 2);
                $table->timestamps();

                $table->unique(['type_id', 'region_id', 'price_type']);
                $table->index('updated_at');
            });
        }
    }
};
