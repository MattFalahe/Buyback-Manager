<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ONE-TIME PRE-RELEASE RESET.
 *
 * Buyback Manager's core workflow was redesigned before its first release:
 * the login-gated "offer" (a frozen-price quote owned by a SeAT user) was
 * replaced by the no-login "appraisal key" model. Because the plugin has
 * never been tagged or published, the incremental migration history was
 * consolidated into the clean set that follows this file instead of being
 * patched forward.
 *
 * This migration drops every table from the pre-redesign schema so the
 * consolidated create migrations can build the new shape cleanly. It also
 * removes the stale rows the old migration files left behind in Laravel's
 * `migrations` table, since those files no longer exist.
 *
 * It is intentionally destructive and intentionally one-time. On a fresh
 * install it is a no-op (nothing to drop). Nothing after 1.0.0 will ever
 * do this again — released migrations are immutable and cleanups are
 * forward-only from here.
 */
return new class extends Migration
{
    /**
     * Every table the pre-redesign plugin owned, including the retired
     * offer tables and the long-dead `buyback_prices` table.
     */
    private const LEGACY_TABLES = [
        'buyback_notification_log',
        'buyback_webhooks',
        'buyback_offer_items',
        'buyback_offers',
        'buyback_contract_items',
        'buyback_contracts',
        'buyback_location_rules',
        'buyback_pricing_rules',
        'buyback_subscribed_types',
        'buyback_price_cache',
        'buyback_prices',
        'buyback_settings',
    ];

    public function up(): void
    {
        foreach (self::LEGACY_TABLES as $table) {
            Schema::dropIfExists($table);
        }

        // Drop the history rows for the deleted migration files so the
        // migrations table reflects what actually ships.
        try {
            DB::table('migrations')
                ->where('migration', 'like', '2026_01_01_%')
                ->where('migration', 'like', '%buyback%')
                ->delete();
        } catch (\Throwable $e) {
            // Tidy-up only — never block the redesign on it.
        }
    }

    /**
     * Not reversible: the pre-redesign schema is gone for good.
     */
    public function down(): void
    {
        // No-op by design.
    }
};
