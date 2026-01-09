<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('buyback_contracts', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('contract_id')->unsigned()->unique();
            $table->bigInteger('corporation_id')->unsigned();
            $table->bigInteger('issuer_id')->unsigned();
            $table->string('status'); // outstanding, in_progress, completed, deleted, cancelled
            $table->decimal('total_value', 20, 2)->default(0);
            $table->integer('items_count')->default(0);
            $table->timestamp('issued_date');
            $table->timestamp('completed_date')->nullable();
            $table->timestamps();

            $table->index('contract_id');
            $table->index(['corporation_id', 'status']);
            $table->index('issuer_id');
            $table->index('issued_date');
        });
    }

    public function down()
    {
        Schema::dropIfExists('buyback_contracts');
    }
};
