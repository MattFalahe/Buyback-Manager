<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ledger of the type ids Buyback Manager has told Manager Core's pricing
 * service to track, per market and provider. Powers the
 * subscribe-on-encounter pattern: each price request diffs against this
 * ledger and only subscribes the genuinely new types.
 *
 * The ledger is Buyback Manager's own source of truth, so it survives
 * Manager Core being absent or reinstalled.
 */
class CreateBuybackManagerSubscribedTypesTable extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('buyback_manager_subscribed_types')) {
            return;
        }

        Schema::create('buyback_manager_subscribed_types', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('type_id');
            $table->string('market', 64);
            // The provider a corporation named for the market, or '' for the
            // market's own. Never null, so the unique key holds for it.
            $table->string('provider', 32)->default('');
            $table->timestamp('subscribed_at')->nullable();
            $table->timestamps();

            // Market and provider first: every read names both.
            $table->unique(['market', 'provider', 'type_id'], 'bb_subscribed_type_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buyback_manager_subscribed_types');
    }
}
