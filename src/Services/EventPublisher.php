<?php

namespace BuybackManager\Services;

use Illuminate\Support\Facades\Log;

/**
 * Fan-out point for every buyback event publish.
 *
 * One call from a domain service (OfferService / ContractService)
 * fans out to:
 *
 *   1. Manager Core EventBus (events.publish capability) — when MC is
 *      installed, so other plugins (HR, SeAT Broadcast) can subscribe.
 *      Guarded by class_exists so standalone installs no-op silently.
 *
 *   2. WebhookDispatcher — BB owns its Discord webhook plumbing
 *      regardless of MC presence. WebhookDispatcher resolves which
 *      webhooks match the event's category, dedup-checks against
 *      buyback_notification_log, then POSTs.
 *
 * Both paths are best-effort: a failure in one never blocks the other,
 * and a failure in either never blocks the calling domain operation.
 * The DB row is always the source of truth — the event/webhook world
 * is just observability + integration.
 */
class EventPublisher
{
    protected WebhookDispatcher $webhookDispatcher;

    public function __construct(WebhookDispatcher $webhookDispatcher)
    {
        $this->webhookDispatcher = $webhookDispatcher;
    }

    public function publish(string $eventName, array $payload): void
    {
        $this->publishToManagerCore($eventName, $payload);
        $this->publishToWebhooks($eventName, $payload);
    }

    protected function publishToManagerCore(string $eventName, array $payload): void
    {
        if (! class_exists('\ManagerCore\Services\PluginBridge')) {
            return;
        }

        try {
            $bridge = app(\ManagerCore\Services\PluginBridge::class);
            $bridge->call('ManagerCore', 'events.publish', $eventName, 'buyback-manager', $payload);
            Log::debug('[Buyback Manager] Published ' . $eventName . ' to MC EventBus', [
                'event_id' => $payload['event_id'] ?? null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[Buyback Manager] MC EventBus publish failed for ' . $eventName . ': ' . $e->getMessage());
        }
    }

    protected function publishToWebhooks(string $eventName, array $payload): void
    {
        try {
            $this->webhookDispatcher->dispatch($eventName, $payload);
        } catch (\Throwable $e) {
            Log::warning('[Buyback Manager] Webhook dispatch failed for ' . $eventName . ': ' . $e->getMessage());
        }
    }
}
