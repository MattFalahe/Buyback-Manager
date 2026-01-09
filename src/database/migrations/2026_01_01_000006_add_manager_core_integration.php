<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class AddManagerCoreIntegration extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Add a note to indicate Manager Core integration
        // The buyback_prices table is now DEPRECATED and will be removed in a future version
        // Pricing is now handled by Manager Core via Plugin Bridge

        // Add an index to improve performance for any remaining queries
        if (Schema::hasTable('buyback_prices')) {
            Schema::table('buyback_prices', function (Blueprint $table) {
                // Check if index doesn't exist
                $sm = Schema::getConnection()->getDoctrineSchemaManager();
                $indexesFound = $sm->listTableIndexes('buyback_prices');

                if (!isset($indexesFound['buyback_prices_type_region_idx'])) {
                    $table->index(['type_id', 'region_id', 'price_type'], 'buyback_prices_type_region_idx');
                }
            });
        }

        // Log the integration
        DB::statement('-- Buyback Manager now integrates with Manager Core for pricing');
        DB::statement('-- The buyback_prices table is deprecated and will be removed in v2.0');
        DB::statement('-- All pricing is now fetched via Manager Core Plugin Bridge');
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        // Remove the index if rolling back
        if (Schema::hasTable('buyback_prices')) {
            Schema::table('buyback_prices', function (Blueprint $table) {
                $sm = Schema::getConnection()->getDoctrineSchemaManager();
                $indexesFound = $sm->listTableIndexes('buyback_prices');

                if (isset($indexesFound['buyback_prices_type_region_idx'])) {
                    $table->dropIndex('buyback_prices_type_region_idx');
                }
            });
        }
    }
}
