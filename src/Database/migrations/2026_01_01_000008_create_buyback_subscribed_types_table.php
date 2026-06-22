<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Local ledger of type IDs Buyback Manager has subscribed to Manager
 * Core's pricing service, per market.
 *
 * Used by the "lazy on encounter" subscription pattern: every time
 * PriceProviderService asks MC for prices on a list of typeIds, it
 * diffs against this table and only calls pricing.subscribeTypes for
 * the new ones. After first encounter a type is subscribed forever;
 * future reads skip the bridge call entirely.
 *
 * Why a local ledger instead of querying MC's
 * manager_core_type_subscriptions directly?
 *   - Standalone-safe: works when MC isn't installed (table just sits
 *     empty, no harm).
 *   - Survives MC uninstall: when an operator removes MC and reinstalls
 *     later, BB can re-subscribe the known universe without rebuilding.
 *   - Cheap dedup: indexed (type_id, market) lookup is microseconds.
 */
return new class extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('buyback_subscribed_types')) {
            Schema::create('buyback_subscribed_types', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('type_id');
                $table->string('market')->default('jita');
                $table->timestamp('subscribed_at')->useCurrent();
                $table->timestamps();

                $table->unique(['type_id', 'market']);
                $table->index('subscribed_at');
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('buyback_subscribed_types');
    }
};
