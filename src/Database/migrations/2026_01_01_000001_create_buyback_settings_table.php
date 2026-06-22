<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('buyback_settings', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('corporation_id')->unsigned();
            $table->bigInteger('character_id')->unsigned()->nullable();
            $table->boolean('enabled')->default(true);
            $table->decimal('base_percentage', 5, 2)->default(90.00);
            $table->string('price_source')->default('jita'); // jita, region
            $table->bigInteger('region_id')->unsigned()->nullable();
            $table->timestamps();

            $table->index('corporation_id');
            $table->index('enabled');
        });
    }

    public function down()
    {
        Schema::dropIfExists('buyback_settings');
    }
};
