<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('buyback_pricing_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('setting_id')->constrained('buyback_settings')->onDelete('cascade');
            $table->string('type'); // category, group, item
            $table->bigInteger('type_id')->unsigned(); // category_id, group_id, or type_id
            $table->decimal('percentage', 5, 2)->nullable();
            $table->boolean('excluded')->default(false);
            $table->integer('priority')->default(0);
            $table->timestamps();

            $table->index(['setting_id', 'type', 'type_id']);
            $table->index('priority');
        });
    }

    public function down()
    {
        Schema::dropIfExists('buyback_pricing_rules');
    }
};
