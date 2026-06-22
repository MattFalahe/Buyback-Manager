<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Webhook dispatch dedup + audit log.
 *
 * payload_hash is a 40-char SHA1 of (webhook_id, event_name, payload)
 * — the unique key for "this specific event already fired to this
 * specific webhook." Used by WebhookDispatcher to skip duplicate
 * dispatches on contract-sync retries.
 *
 * status: 'sent' | 'failed' | 'rate_limited'.
 * error: human-readable reason on failure (HTTP status, exception
 * message, etc.) — null on success.
 *
 * Retention: rotated by the existing 'buyback-manager:sync-contracts'
 * sweep — entries older than 30 days get pruned on the next contract
 * sync. No separate cron needed.
 */
return new class extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('buyback_notification_log')) {
            Schema::create('buyback_notification_log', function (Blueprint $table) {
                $table->id();
                $table->foreignId('webhook_id')->constrained('buyback_webhooks')->onDelete('cascade');
                $table->string('event_name', 100);
                $table->string('payload_hash', 40);
                $table->timestamp('sent_at')->useCurrent();
                $table->string('status', 16)->default('sent');
                $table->text('error')->nullable();
                $table->timestamps();

                $table->unique(['webhook_id', 'payload_hash']);
                $table->index(['webhook_id', 'sent_at']);
                $table->index('payload_hash');
                $table->index('sent_at');
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('buyback_notification_log');
    }
};
