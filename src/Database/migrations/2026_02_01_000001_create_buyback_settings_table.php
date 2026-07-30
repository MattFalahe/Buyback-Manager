<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-corporation buyback programme configuration: pricing provider,
 * rates, where contracts are sent, how far a quote may drift before it is
 * flagged, retention windows, and the public landing page.
 *
 * One row per corporation. Many corporations can run their own
 * independent programme on the same SeAT install.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buyback_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('corporation_id')->unique();

            // Designated operator character. Required when contracts are
            // sent to a specific player rather than a corporation.
            $table->unsignedBigInteger('character_id')->nullable();
            $table->boolean('enabled')->default(false);

            // --- Rates ---
            $table->decimal('base_percentage', 5, 2)->default(90.00);

            // --- Pricing provider ---
            $table->string('price_source', 16)->default('jita');   // jita | region
            $table->unsignedInteger('region_id')->nullable();
            $table->string('price_provider', 32)->default('fuzzwork'); // fuzzwork | janice | manager-core
            $table->string('janice_api_key')->nullable();
            $table->string('janice_market', 16)->nullable()->default('jita');
            $table->string('janice_price_method', 16)->nullable()->default('buy');
            $table->string('manager_core_market', 64)->nullable()->default('jita');
            $table->string('manager_core_variant', 16)->nullable()->default('min');
            $table->boolean('fallback_to_jita')->default(true);
            $table->unsignedInteger('price_cache_ttl_minutes')->default(60);

            // --- Contract target: where the member sends the contract ---
            $table->string('target_type', 16)->default('my_corp'); // my_corp | corp | player
            $table->unsignedBigInteger('target_corporation_id')->nullable();
            $table->string('target_corporation_name')->nullable();

            // --- Review thresholds + housekeeping ---
            // How far the ISK the member asked for may drift from the
            // quoted value before the contract is flagged for review.
            $table->decimal('max_deviation_percent', 5, 2)->default(1.00);
            // A quote older than this is flagged as stale, because market
            // prices may have moved since it was generated.
            $table->unsignedInteger('appraisal_stale_hours')->default(48);
            // Per-item appraisal rows are pruned after this many days; the
            // appraisal header is kept longer for statistics.
            $table->unsignedInteger('appraisal_item_retention_days')->default(14);
            $table->unsignedInteger('appraisal_retention_days')->default(180);
            // Reminder window for a matched contract left unaccepted. 0 disables.
            $table->unsignedInteger('auto_nudge_hours')->default(48);

            // --- Public landing page ---
            $table->boolean('public_page_enabled')->default(false);
            $table->boolean('public_show_rates')->default(true);
            $table->boolean('public_show_all_rules')->default(false);
            $table->boolean('public_show_pricing_detail')->default(false);
            // The public appraisal tool is the primary seller flow, so it
            // is on by default. Turn it off for an internal-only programme.
            $table->boolean('public_appraisal_enabled')->default(true);
            $table->string('public_layout', 16)->default('stacked'); // stacked | split
            $table->string('public_headline')->nullable();
            $table->text('public_blurb')->nullable();
            $table->string('public_accent_color', 7)->nullable();
            $table->unsignedTinyInteger('public_overlay_opacity')->default(55);
            $table->string('public_background_path')->nullable();
            $table->string('public_logo_path')->nullable();
            $table->string('public_logo_style', 16)->default('dark'); // dark | none | light
            $table->string('public_footer_text', 500)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buyback_settings');
    }
};
