<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pricing follows the same chain as Mining Manager: the corporation's
 * provider, then a fallback provider when that one fails outright, then the
 * last price this install saw.
 *
 * - buyback_settings gains the fallback provider and loses the Manager Core
 *   variant, which read the same statistic on both sides of the spread, so
 *   "min" gave the lowest buy order rather than the highest.
 * - buyback_price_cache is rebuilt keyed by market and item. The old key was a
 *   region id, which cannot hold a Manager Core citadel market, and a side
 *   with no orders was stored as 0. Each side now keeps its last real price
 *   and when it was seen. The old rows are only cache, so they are dropped.
 * - buyback_price_status remembers how each corporation's provider last did,
 *   so an alert goes out when that changes rather than on every quote.
 * - buyback_appraisals.pricing_note says when a quote was priced by the
 *   fallback provider or from cached prices.
 */
class UpdateBuybackPricingChain extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('buyback_settings')) {
            if (! Schema::hasColumn('buyback_settings', 'fallback_provider')) {
                Schema::table('buyback_settings', function (Blueprint $table) {
                    $table->string('fallback_provider', 32)->default('fuzzwork')->after('price_provider');
                });
            }

            if (Schema::hasColumn('buyback_settings', 'manager_core_variant')) {
                Schema::table('buyback_settings', function (Blueprint $table) {
                    $table->dropColumn('manager_core_variant');
                });
            }
        }

        if (Schema::hasTable('buyback_price_cache') && ! Schema::hasColumn('buyback_price_cache', 'market')) {
            Schema::drop('buyback_price_cache');
        }

        if (! Schema::hasTable('buyback_price_cache')) {
            Schema::create('buyback_price_cache', function (Blueprint $table) {
                $table->id();
                $table->string('market', 80);
                $table->unsignedBigInteger('type_id');
                $table->string('provider', 32)->nullable();
                $table->decimal('buy_price', 20, 2)->nullable();
                $table->timestamp('buy_seen_at')->nullable();
                $table->decimal('sell_price', 20, 2)->nullable();
                $table->timestamp('sell_seen_at')->nullable();
                $table->timestamp('checked_at')->nullable();
                $table->timestamps();

                $table->unique(['market', 'type_id'], 'bb_price_cache_market_type_unique');
                $table->index('checked_at', 'bb_price_cache_checked_idx');
            });
        }

        if (! Schema::hasTable('buyback_price_status')) {
            Schema::create('buyback_price_status', function (Blueprint $table) {
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

        if (Schema::hasTable('buyback_appraisals') && ! Schema::hasColumn('buyback_appraisals', 'pricing_note')) {
            Schema::table('buyback_appraisals', function (Blueprint $table) {
                $table->string('pricing_note', 255)->nullable()->after('provider');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('buyback_appraisals') && Schema::hasColumn('buyback_appraisals', 'pricing_note')) {
            Schema::table('buyback_appraisals', function (Blueprint $table) {
                $table->dropColumn('pricing_note');
            });
        }

        Schema::dropIfExists('buyback_price_status');

        if (Schema::hasTable('buyback_price_cache') && Schema::hasColumn('buyback_price_cache', 'market')) {
            Schema::drop('buyback_price_cache');
        }

        if (! Schema::hasTable('buyback_price_cache')) {
            Schema::create('buyback_price_cache', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('type_id');
                $table->unsignedBigInteger('region_id');
                $table->decimal('sell_price', 20, 2)->default(0);
                $table->decimal('buy_price', 20, 2)->default(0);
                $table->decimal('average_price', 20, 2)->default(0);
                $table->timestamp('cached_at')->nullable()->index();
                $table->timestamps();

                $table->unique(['type_id', 'region_id'], 'bb_price_cache_unique');
            });
        }

        if (Schema::hasTable('buyback_settings')) {
            if (! Schema::hasColumn('buyback_settings', 'manager_core_variant')) {
                Schema::table('buyback_settings', function (Blueprint $table) {
                    $table->string('manager_core_variant', 16)->nullable()->default('min')->after('manager_core_market');
                });
            }

            if (Schema::hasColumn('buyback_settings', 'fallback_provider')) {
                Schema::table('buyback_settings', function (Blueprint $table) {
                    $table->dropColumn('fallback_provider');
                });
            }
        }
    }
}
