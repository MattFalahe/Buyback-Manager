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
class CreateBuybackManagerSettingsTable extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('buyback_manager_settings')) {
            return;
        }

        Schema::create('buyback_manager_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('corporation_id')->unique();
            // Designated operator character. Required when contracts are
            // sent to a specific player rather than a corporation.
            $table->unsignedBigInteger('character_id')->nullable();
            $table->boolean('enabled')->default(false);

            // --- Rates ---
            $table->decimal('base_percentage', 5, 2)->default(90.00);
            // Allow-list mode. When true the default rate is ignored and only
            // items covered by a price exception are bought; everything else
            // is reported to the seller as not accepted.
            $table->boolean('buy_listed_only')->default(false);

            // --- Pricing provider ---
            // The chain is the provider, then the fallback provider when that
            // one fails outright, then the last price this install saw.
            $table->string('price_source', 16)->default('jita');   // jita | region
            $table->unsignedInteger('region_id')->nullable();
            $table->string('price_provider', 32)->default('fuzzwork'); // fuzzwork | janice | manager-core
            $table->string('fallback_provider', 32)->default('fuzzwork'); // fuzzwork | janice | manager-core | none
            // text rather than string, so the key can be stored encrypted.
            $table->text('janice_api_key')->nullable();
            $table->string('janice_market', 16)->nullable()->default('jita');
            $table->string('janice_price_method', 16)->nullable()->default('buy');
            $table->string('manager_core_market', 64)->nullable()->default('jita');
            // A provider Manager Core prices that market through for this
            // corporation alone, apart from the market's own (a price
            // variant, Manager Core 1.2.0 and later). Null is the market's own.
            $table->string('manager_core_provider', 32)->nullable();
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
            // A top buy order standing more than this far above the average
            // of the top 5% of buy orders is treated as a thin market, and the
            // item is priced at that average. 0 turns the guard off.
            $table->decimal('thin_market_guard_percent', 5, 2)->default(10.00);
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
            // What the page shows while it is off. A seller following an old
            // link learns the buyback is closed, for now or for good, rather
            // than meeting a bare 404. Hidden keeps the 404.
            $table->string('public_closed_state', 16)->default('temporary'); // temporary | permanent | hidden
            $table->text('public_closed_message')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buyback_manager_settings');
    }
}
