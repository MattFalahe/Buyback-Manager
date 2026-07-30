<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ledger of the type ids Buyback Manager has told Manager Core's pricing
 * service to track, per market. Powers the subscribe-on-encounter pattern:
 * each price request diffs against this ledger and only subscribes the
 * genuinely new types.
 *
 * The ledger is Buyback Manager's own source of truth, so it survives
 * Manager Core being absent or reinstalled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buyback_subscribed_types', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('type_id');
            $table->string('market', 64);
            $table->timestamp('subscribed_at')->nullable();
            $table->timestamps();

            $table->unique(['type_id', 'market'], 'bb_subscribed_type_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buyback_subscribed_types');
    }
};
