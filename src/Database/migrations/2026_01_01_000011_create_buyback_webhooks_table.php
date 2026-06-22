<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Buyback Manager owns its own Discord webhook plumbing — mirrors
 * Mining Manager's pattern. Per-corp scoping (null corporation_id =
 * global webhook fired for all corps).
 *
 * categories: JSON array of category keys this webhook subscribes to.
 * Recognized keys are defined by WebhookDispatcher's CATEGORIES const:
 *   offer_published, offer_matched, offer_rejected,
 *   contract_unmatched, contract_completed, contract_cancelled
 *
 * role_mention: stored as a Discord-ready string like '<@&123>' for
 * a role or '<@456>' for a user. Plugins never construct this at send
 * time — they just use the stored string verbatim.
 *
 * NOTE: this is intentionally per-CORP, not per-USER. Buyback Manager
 * follows the "route to groups via webhooks, not individuals" rule.
 */
return new class extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('buyback_webhooks')) {
            Schema::create('buyback_webhooks', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('corporation_id')->nullable();
                $table->string('name', 100);
                // Discord webhook URLs are ~80 chars. string(500) matches
                // the WebhookController validator's max:500 rule.
                $table->string('url', 500);
                $table->boolean('enabled')->default(true);
                $table->string('role_mention', 50)->nullable();
                $table->json('categories');
                $table->timestamps();

                $table->index(['corporation_id', 'enabled']);
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('buyback_webhooks');
    }
};
