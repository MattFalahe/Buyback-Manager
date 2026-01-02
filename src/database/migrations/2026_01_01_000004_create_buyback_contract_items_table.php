<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('buyback_contract_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained('buyback_contracts')->onDelete('cascade');
            $table->bigInteger('type_id')->unsigned();
            $table->bigInteger('quantity')->default(0);
            $table->decimal('unit_price', 20, 2)->default(0);
            $table->decimal('total_value', 20, 2)->default(0);
            $table->integer('category_id')->unsigned()->nullable();
            $table->integer('group_id')->unsigned()->nullable();
            $table->timestamps();

            $table->index('contract_id');
            $table->index('type_id');
            $table->index('category_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('buyback_contract_items');
    }
};
