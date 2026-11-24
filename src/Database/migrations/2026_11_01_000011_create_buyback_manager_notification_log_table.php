<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Webhook dispatch dedup and audit trail.
 *
 * payload_hash is a digest of the event name plus its canonicalised
 * payload, so the unique (webhook_id, payload_hash) key means "this exact
 * event already went to this webhook". That is what stops a re-sync over
 * an unchanged contract from announcing it twice.
 *
 * `payload` keeps what was sent, so a post Discord refused or rate limited
 * can be sent again; `attempts` counts the tries.
 *
 * Rows are pruned inside the contract-sync cycle, so no extra cron.
 */
class CreateBuybackManagerNotificationLogTable extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('buyback_manager_notification_log')) {
            return;
        }

        Schema::create('buyback_manager_notification_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('webhook_id');
            $table->string('event_name', 64);
            $table->string('payload_hash', 40);
            $table->json('payload')->nullable();
            $table->unsignedTinyInteger('attempts')->default(1);
            $table->timestamp('sent_at')->nullable()->index();
            $table->string('status', 16)->default('sent'); // sent | failed | rate_limited
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['webhook_id', 'payload_hash'], 'bb_notif_log_dedup_unique');
            // Each webhook's last delivery, for Settings and Diagnostics.
            $table->index(['webhook_id', 'sent_at'], 'bb_notif_log_webhook_sent');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buyback_manager_notification_log');
    }
}
