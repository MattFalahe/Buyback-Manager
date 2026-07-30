<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Discord webhooks for buyback notifications.
 *
 * A null corporation_id is a global webhook that fires for every
 * corporation's events — convenient on a single-corp install, but on a
 * multi-programme server prefer per-corporation webhooks so one corp's
 * activity is not announced in another's channel.
 *
 * `categories` is a JSON array of subscription keys, and `role_mention` is
 * stored ready to send (for example "<@&123>") and used verbatim.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buyback_webhooks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('corporation_id')->nullable()->index();
            $table->string('name', 100);
            $table->string('url', 500);
            $table->boolean('enabled')->default(true);
            $table->string('role_mention', 50)->nullable();
            $table->json('categories')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buyback_webhooks');
    }
};
