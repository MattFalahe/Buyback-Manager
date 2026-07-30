<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Webhook dispatch dedup and audit trail.
 *
 * payload_hash is a digest of the event name plus its canonicalised
 * payload, so the unique (webhook_id, payload_hash) key means "this exact
 * event already fired to this webhook". That is what stops a re-sync over
 * an unchanged contract from announcing it twice.
 *
 * Rows are pruned inside the contract-sync cycle, so no extra cron.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buyback_notification_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('webhook_id')->index();
            $table->string('event_name', 64);
            $table->string('payload_hash', 40);
            $table->timestamp('sent_at')->nullable()->index();
            $table->string('status', 16)->default('sent'); // sent | failed | rate_limited
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['webhook_id', 'payload_hash'], 'bb_notif_log_dedup_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buyback_notification_log');
    }
};
