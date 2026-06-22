<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Public buyback landing page (Phase 0).
 *
 * Adds the per-corp public-page configuration to buyback_settings: a
 * master enable toggle, an advertised-rates toggle, a public-appraisal
 * toggle (schema-only for now — the estimator UI/endpoint is a planned
 * fast-follow), corp-authored headline/blurb/footer copy, an accent
 * colour, an overlay opacity for legibility over the background, and the
 * uploaded background/logo paths. Images are served via a streaming
 * route (mirroring HR Manager's hero-image pattern) so they work without
 * `php artisan storage:link` on Docker and bare-metal alike.
 *
 * Also adds a `featured` flag to buyback_pricing_rules so a corp can
 * spotlight "most wanted" items on the public page.
 *
 * Additive + guarded with hasColumn so re-runs and partial states are
 * safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buyback_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('buyback_settings', 'public_page_enabled')) {
                $table->boolean('public_page_enabled')->default(false)->after('private_auto_nudge_hours');
            }
            if (! Schema::hasColumn('buyback_settings', 'public_show_rates')) {
                $table->boolean('public_show_rates')->default(true)->after('public_page_enabled');
            }
            if (! Schema::hasColumn('buyback_settings', 'public_appraisal_enabled')) {
                $table->boolean('public_appraisal_enabled')->default(false)->after('public_show_rates');
            }
            if (! Schema::hasColumn('buyback_settings', 'public_headline')) {
                $table->string('public_headline')->nullable()->after('public_appraisal_enabled');
            }
            if (! Schema::hasColumn('buyback_settings', 'public_blurb')) {
                $table->text('public_blurb')->nullable()->after('public_headline');
            }
            if (! Schema::hasColumn('buyback_settings', 'public_accent_color')) {
                $table->string('public_accent_color', 7)->nullable()->after('public_blurb');
            }
            if (! Schema::hasColumn('buyback_settings', 'public_overlay_opacity')) {
                $table->unsignedTinyInteger('public_overlay_opacity')->default(55)->after('public_accent_color');
            }
            if (! Schema::hasColumn('buyback_settings', 'public_background_path')) {
                $table->string('public_background_path')->nullable()->after('public_overlay_opacity');
            }
            if (! Schema::hasColumn('buyback_settings', 'public_logo_path')) {
                $table->string('public_logo_path')->nullable()->after('public_background_path');
            }
            if (! Schema::hasColumn('buyback_settings', 'public_footer_text')) {
                $table->string('public_footer_text', 500)->nullable()->after('public_logo_path');
            }
        });

        Schema::table('buyback_pricing_rules', function (Blueprint $table) {
            if (! Schema::hasColumn('buyback_pricing_rules', 'featured')) {
                $table->boolean('featured')->default(false)->after('excluded');
            }
        });
    }

    public function down(): void
    {
        Schema::table('buyback_settings', function (Blueprint $table) {
            foreach ([
                'public_page_enabled',
                'public_show_rates',
                'public_appraisal_enabled',
                'public_headline',
                'public_blurb',
                'public_accent_color',
                'public_overlay_opacity',
                'public_background_path',
                'public_logo_path',
                'public_footer_text',
            ] as $col) {
                if (Schema::hasColumn('buyback_settings', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('buyback_pricing_rules', function (Blueprint $table) {
            if (Schema::hasColumn('buyback_pricing_rules', 'featured')) {
                $table->dropColumn('featured');
            }
        });
    }
};
