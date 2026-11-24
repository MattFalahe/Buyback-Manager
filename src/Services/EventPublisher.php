<?php

namespace BuybackManager\Services;

use BuybackManager\Integrations\ManagerCoreIntegration;
use Illuminate\Support\Facades\Log;

/**
 * Fan-out point for every buyback event publish.
 *
 * One call from a domain service (ContractService)
 * fans out to:
 *
 *   1. Manager Core 1.2.0 or later, when it is installed, so other plugins
 *      (HR, SeAT Broadcast) can subscribe. Its Topics check the required
 *      fields and key the event on the contract. Standalone installs, and
 *      a Manager Core too old to use, skip this step.
 *
 *   2. WebhookDispatcher — BB owns its Discord webhook plumbing
 *      regardless of MC presence. WebhookDispatcher resolves which
 *      webhooks match the event's category, dedup-checks against
 *      buyback_manager_notification_log, then POSTs.
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

    /**
     * An alert about a programme rather than a contract, for its directors
     * only: it goes to Discord and not to Manager Core, whose topics carry
     * contract events for other plugins to act on.
     */
    public function announce(string $eventName, array $payload): void
    {
        $this->publishToWebhooks($eventName, $payload);
    }

    /**
     * Send again the Discord posts that did not get through. Called once per
     * sync cycle.
     */
    public function retryWebhooks(): void
    {
        try {
            $tried = $this->webhookDispatcher->retryPending();
            if ($tried > 0) {
                Log::info('[Buyback Manager] Retried ' . $tried . ' webhook post(s)');
            }
        } catch (\Throwable $e) {
            Log::warning('[Buyback Manager] Webhook retry failed: ' . $e->getMessage());
        }
    }

    protected function publishToManagerCore(string $eventName, array $payload): void
    {
        if (! ManagerCoreIntegration::isAvailable()) {
            return;
        }

        try {
            $result = \ManagerCore\Topics::publish($eventName, $payload);
            Log::debug('[Buyback Manager] Published ' . $eventName . ' through MC Topics', [
                'event_id' => $payload['event_id'] ?? null,
                'published' => $result !== null,
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
