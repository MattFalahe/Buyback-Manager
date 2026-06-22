<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-shot self-healing migration to clean up orphaned `migrations`
 * table entries left behind by the 2026-05-23 renumber.
 *
 * Background: BB's migration numbering had a 000005/000006 gap from
 * historical pre-release deletions. Closing the gap renamed every
 * file from 000007 onwards to 000005-000014. Any test install that
 * had already run the old-named files now has stale rows in the
 * `migrations` table referencing filenames that no longer exist.
 *
 * On the next deploy:
 *   1. Laravel sees the new-named files as fresh migrations and runs
 *      them. Every renamed file is idempotent (Schema::hasColumn /
 *      hasTable guards), so the re-run is a no-op.
 *   2. The migrations table gets BOTH the new-named row AND retains
 *      the old-named orphan row for each renamed file.
 *   3. This migration runs last and deletes the orphans, leaving a
 *      clean contiguous history matching the current filenames.
 *
 * Idempotent: re-runs are safe (whereIn->delete is a no-op when the
 * old names aren't present). After v1.0.0 ships and the test-install
 * window closes, this migration is a permanent harmless no-op for
 * any fresh install (the old names never existed in their migrations
 * table to begin with).
 */
return new class extends Migration
{
    public function up(): void
    {
        $orphaned = [
            '2026_01_01_000007_remove_local_pricing_system',
            '2026_01_01_000008_add_provider_columns_to_buyback_settings',
            '2026_01_01_000009_create_buyback_price_cache_table',
            '2026_01_01_000010_create_buyback_subscribed_types_table',
            '2026_01_01_000011_create_buyback_offers_table',
            '2026_01_01_000012_create_buyback_offer_items_table',
            '2026_01_01_000013_create_buyback_webhooks_table',
            '2026_01_01_000014_create_buyback_notification_log_table',
            '2026_01_01_000015_add_mode_columns_to_buyback_settings',
            '2026_01_01_000016_add_price_side_to_buyback_pricing_rules',
        ];

        DB::table('migrations')
            ->whereIn('migration', $orphaned)
            ->delete();
    }

    public function down(): void
    {
        // Intentional no-op. Recreating the orphan rows would only
        // confuse the renamed-files state. If a rollback is genuinely
        // needed, manage the migrations table directly.
    }
};
