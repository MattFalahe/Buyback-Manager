<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How each corporation's price provider last did, and its fallback with it.
 * The alert goes out when this changes (failing, then answering again)
 * rather than on every quote that hits a dead provider.
 */
class CreateBuybackManagerPriceStatusTable extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('buyback_manager_price_status')) {
            return;
        }

        Schema::create('buyback_manager_price_status', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('setting_id');
            $table->string('provider', 32);
            $table->string('market', 80)->nullable();
            $table->boolean('failing')->default(false);
            $table->text('error')->nullable();
            $table->timestamp('since')->nullable();
            $table->timestamp('last_success')->nullable();
            $table->string('fallback', 32)->nullable();
            $table->boolean('fallback_ok')->default(false);
            $table->text('fallback_error')->nullable();
            $table->timestamps();

            $table->unique('setting_id', 'bb_price_status_setting_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buyback_manager_price_status');
    }
}
