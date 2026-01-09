<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('buyback_prices', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('type_id')->unsigned();
            $table->bigInteger('region_id')->unsigned();
            $table->string('price_type')->default('sell'); // sell, buy
            $table->decimal('price', 20, 2)->default(0);
            $table->bigInteger('volume')->default(0);
            $table->timestamp('updated_at');

            $table->unique(['type_id', 'region_id', 'price_type']);
            $table->index('type_id');
            $table->index('updated_at');
        });
    }

    public function down()
    {
        Schema::dropIfExists('buyback_prices');
    }
};
