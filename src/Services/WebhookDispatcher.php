<?php

namespace BuybackManager\Services;

use BuybackManager\Models\BuybackNotificationLog;
use BuybackManager\Models\BuybackWebhook;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Dispatches buyback events to configured Discord webhooks.
 *
 * Event-to-category mapping is the only thing that connects domain
 * events (buyback.offer.published, buyback.contract.matched, etc.) to
 * webhook subscription categories. This keeps the BuybackWebhook
 * categories array meaningful for operators (offer_published,
 * contract_unmatched, etc.) without leaking event-naming details into
 * the settings UI.
 *
 * Dedup via buyback_notification_log: (webhook_id, payload_hash) is
 * unique, so a second dispatch of the same payload to the same webhook
 * is silently skipped. Important because ContractService can re-run
 * over the same contract on its 15-minute cycle.
 */
class WebhookDispatcher
{
    /**
     * Maps fully-qualified event names to webhook category keys. The
     * category keys are what operators see in the Settings UI and what
     * BuybackWebhook stores in its JSON column.
     */
    private const EVENT_TO_CATEGORY = [
        'buyback.offer.published' => BuybackWebhook::CATEGORY_OFFER_PUBLISHED,
        'buyback.offer.expired' => BuybackWebhook::CATEGORY_OFFER_PUBLISHED,
        'buyback.offer.cancelled' => BuybackWebhook::CATEGORY_OFFER_PUBLISHED,
        'buyback.offer.matched' => BuybackWebhook::CATEGORY_OFFER_MATCHED,
        'buyback.offer.rejected' => BuybackWebhook::CATEGORY_OFFER_REJECTED,
        // First-sighting contract events. `contract.created` deliberately
        // omitted — first sightings fire matched OR unmatched only, which
        // already carry the "first time we saw this" context. Adding
        // `contract.created` would double-deliver to any webhook
        // subscribing to offer_matched (it'd get matched + created for
        // matched contracts, or unmatched + created for unmatched).
        'buyback.contract.matched' => BuybackWebhook::CATEGORY_OFFER_MATCHED,
        'buyback.contract.unmatched' => BuybackWebhook::CATEGORY_CONTRACT_UNMATCHED,
        // Lifecycle transitions after first sighting.
        'buyback.contract.completed' => BuybackWebhook::CATEGORY_CONTRACT_COMPLETED,
        'buyback.contract.cancelled' => BuybackWebhook::CATEGORY_CONTRACT_CANCELLED,
        'buyback.contract.rejected' => BuybackWebhook::CATEGORY_OFFER_REJECTED,
    ];

    private const HTTP_TIMEOUT = 10;

    /**
     * Soft cap on dispatches per webhook per minute. Discord's documented
     * limit is 30/min; we throttle at 25 to leave headroom for retries
     * and concurrent workers. When the cap trips, the dispatch is logged
     * as rate_limited and not sent (subscribers get the next opportunity
     * on the following minute window via the ContractService re-sync).
     */
    private const PER_WEBHOOK_RATE_LIMIT = 25;

    /**
     * Rate-limit window in seconds. Matches Discord's per-webhook limit.
     */
    private const PER_WEBHOOK_RATE_WINDOW = 60;

    /**
     * Render + dispatch a single event to every matching webhook.
     * Honors:
     *   - corp scope (payload.corporation_id matches webhook.corporation_id
     *     OR webhook.corporation_id is null = global)
     *   - category match (webhook subscribes to this event's category)
     *   - dedup (skip if (webhook_id, payload_hash) already exists)
     */
    public function dispatch(string $eventName, array $payload): void
    {
        $category = self::EVENT_TO_CATEGORY[$eventName] ?? null;
        if ($category === null) {
            // Unknown event — not a domain concern; just skip.
            return;
        }

        $corporationId = (int) ($payload['corporation_id'] ?? 0);
        $webhooks = BuybackWebhook::enabled()
            ->matchingCorp($corporationId)
            ->forCategory($category)
            ->get();

        if ($webhooks->isEmpty()) {
            return;
        }

        $payloadHash = BuybackNotificationLog::computeHash($eventName, $payload);

        foreach ($webhooks as $webhook) {
            $this->dispatchOne($webhook, $eventName, $payload, $payloadHash);
        }
    }

    protected function dispatchOne(BuybackWebhook $webhook, string $eventName, array $payload, string $payloadHash): void
    {
        // Dedup: skip if this exact event+payload already fired to this webhook.
        $already = BuybackNotificationLog::where('webhook_id', $webhook->id)
            ->where('payload_hash', $payloadHash)
            ->exists();
        if ($already) {
            return;
        }

        // Per-webhook rate limit. Discord enforces 30 req/min/webhook;
        // we cap at 25 to leave headroom. The counter is a fixed
        // PER_WEBHOOK_RATE_WINDOW-second cache bucket, NOT a sliding
        // window — simple, accurate enough, no Redis sorted-set dance.
        $rateKey = 'bb:webhook:rate:' . $webhook->id;
        $first = Cache::add($rateKey, 1, self::PER_WEBHOOK_RATE_WINDOW);
        $count = $first ? 1 : (int) Cache::increment($rateKey);
        if ($count > self::PER_WEBHOOK_RATE_LIMIT) {
            Log::warning('[Buyback Manager] Webhook ' . $webhook->id . ' rate-limited (cap reached for ' . self::PER_WEBHOOK_RATE_WINDOW . 's window)', [
                'event_name' => $eventName,
                'count' => $count,
            ]);
            try {
                BuybackNotificationLog::create([
                    'webhook_id' => $webhook->id,
                    'event_name' => $eventName,
                    'payload_hash' => $payloadHash,
                    'sent_at' => now(),
                    'status' => BuybackNotificationLog::STATUS_RATE_LIMITED,
                    'error' => 'Local rate-limit cap (' . self::PER_WEBHOOK_RATE_LIMIT . '/' . self::PER_WEBHOOK_RATE_WINDOW . 's) reached before dispatch',
                ]);
            } catch (\Throwable $e) {
                // ignore — already logged
            }
            return;
        }

        $body = $this->buildPayload($eventName, $payload, $webhook);

        $status = BuybackNotificationLog::STATUS_SENT;
        $error = null;

        try {
            $response = Http::timeout(self::HTTP_TIMEOUT)
                ->asJson()
                ->post($webhook->url, $body);

            if ($response->status() === 429) {
                $status = BuybackNotificationLog::STATUS_RATE_LIMITED;
                $error = 'Discord rate limit (HTTP 429)';
            } elseif (! $response->successful()) {
                $status = BuybackNotificationLog::STATUS_FAILED;
                $error = 'HTTP ' . $response->status() . ': ' . substr($response->body(), 0, 500);
            }
        } catch (\Throwable $e) {
            $status = BuybackNotificationLog::STATUS_FAILED;
            $error = $e->getMessage();
            Log::warning('[Buyback Manager] Webhook dispatch threw for ' . $webhook->id . ': ' . $error);
        }

        try {
            BuybackNotificationLog::create([
                'webhook_id' => $webhook->id,
                'event_name' => $eventName,
                'payload_hash' => $payloadHash,
                'sent_at' => now(),
                'status' => $status,
                'error' => $error,
            ]);
        } catch (\Throwable $e) {
            // Race: another worker raced us to the unique (webhook_id, payload_hash) row.
            // That's fine — the other worker either sent it or recorded the failure.
            Log::debug('[Buyback Manager] Notification log insert raced: ' . $e->getMessage());
        }
    }

    /**
     * Build the Discord-compatible JSON body for a webhook dispatch.
     * Embed shape + color + fields vary per event so operators get a
     * scannable, sensibly-formatted message rather than a JSON dump.
     */
    protected function buildPayload(string $eventName, array $payload, BuybackWebhook $webhook): array
    {
        $body = [
            'username' => 'Buyback Manager',
            'embeds' => [$this->buildEmbed($eventName, $payload)],
        ];

        if (! empty($webhook->role_mention)) {
            $body['content'] = $webhook->role_mention;
            $body['allowed_mentions'] = [
                'parse' => ['roles', 'users'],
            ];
        }

        return $body;
    }

    /**
     * Per-event embed builder. Returns the Discord embed structure
     * (title, description, color, fields, footer, timestamp).
     */
    protected function buildEmbed(string $eventName, array $payload): array
    {
        $title = $this->titleFor($eventName, $payload);
        $color = $this->colorFor($eventName);
        $fields = $this->fieldsFor($eventName, $payload);

        $embed = [
            'title' => $title,
            'color' => $color,
            'fields' => $fields,
            'footer' => ['text' => 'Buyback Manager'],
            'timestamp' => now()->toIso8601String(),
        ];

        if (! empty($payload['url'])) {
            $embed['url'] = $payload['url'];
        }

        return $embed;
    }

    private function titleFor(string $eventName, array $payload): string
    {
        $publicId = $payload['offer_public_id'] ?? null;
        $contractId = $payload['contract_id'] ?? null;
        $valueText = isset($payload['total_buyback_value'])
            ? number_format((float) $payload['total_buyback_value'], 2) . ' ISK'
            : null;

        return match ($eventName) {
            'buyback.offer.published' => 'New Buyback Offer ' . ($publicId ? "#{$publicId}" : '') . ($valueText ? " — {$valueText}" : ''),
            'buyback.offer.matched' => 'Offer Matched to Contract ' . ($publicId ? "#{$publicId}" : ''),
            'buyback.offer.expired' => 'Offer Expired ' . ($publicId ? "#{$publicId}" : ''),
            'buyback.offer.cancelled' => 'Offer Cancelled ' . ($publicId ? "#{$publicId}" : ''),
            'buyback.offer.rejected' => 'Offer Rejected ' . ($publicId ? "#{$publicId}" : ''),
            'buyback.contract.created' => 'Buyback Contract Created' . ($contractId ? " #{$contractId}" : ''),
            'buyback.contract.matched' => 'Buyback Contract Matched' . ($contractId ? " #{$contractId}" : ''),
            'buyback.contract.unmatched' => 'Unmatched buyback attempt' . ($contractId ? " (contract #{$contractId})" : ''),
            'buyback.contract.completed' => 'Buyback Completed' . ($valueText ? " — {$valueText}" : ''),
            'buyback.contract.cancelled' => 'Buyback Contract Cancelled' . ($contractId ? " #{$contractId}" : ''),
            'buyback.contract.rejected' => 'Buyback Contract Rejected' . ($contractId ? " #{$contractId}" : ''),
            default => 'Buyback Event: ' . $eventName,
        };
    }

    private function colorFor(string $eventName): int
    {
        // Indigo / green / yellow / red palette matching the canonical
        // design system tokens (#6366f1, #22c55e, #eab308, #ef4444).
        return match ($eventName) {
            'buyback.offer.published',
            'buyback.contract.created',
            'buyback.contract.matched',
            'buyback.offer.matched' => 0x6366f1,
            'buyback.contract.completed' => 0x22c55e,
            'buyback.offer.expired',
            'buyback.contract.unmatched',
            'buyback.contract.cancelled',
            'buyback.offer.cancelled' => 0xeab308,
            'buyback.contract.rejected',
            'buyback.offer.rejected' => 0xef4444,
            default => 0x6366f1,
        };
    }

    private function fieldsFor(string $eventName, array $payload): array
    {
        $fields = [];

        if (isset($payload['total_buyback_value'])) {
            $fields[] = [
                'name' => 'Value',
                'value' => number_format((float) $payload['total_buyback_value'], 2) . ' ISK',
                'inline' => true,
            ];
        }
        if (isset($payload['items_count'])) {
            $fields[] = [
                'name' => 'Items',
                'value' => (string) $payload['items_count'],
                'inline' => true,
            ];
        }
        if ($issuer = $this->resolveIssuer($payload)) {
            $fields[] = [
                'name' => 'Posted by',
                'value' => $issuer,
                'inline' => true,
            ];
        }
        if (isset($payload['market'])) {
            $fields[] = [
                'name' => 'Market',
                'value' => (string) $payload['market'],
                'inline' => true,
            ];
        }
        $sendTo = $payload['send_to'] ?? null;
        if (! $sendTo && isset($payload['target_type'])) {
            $sendTo = $this->targetTypeLabel((string) $payload['target_type']);
        }
        if ($sendTo) {
            $fields[] = [
                'name' => 'Send to',
                'value' => (string) $sendTo,
                'inline' => true,
            ];
        }
        if (isset($payload['expires_at'])) {
            $fields[] = [
                'name' => 'Lock expires',
                'value' => (string) $payload['expires_at'],
                'inline' => true,
            ];
        }
        if (isset($payload['contract_id'])) {
            $fields[] = [
                'name' => 'Contract ID',
                'value' => (string) $payload['contract_id'],
                'inline' => true,
            ];
        }
        if (! empty($payload['attempted_offer_id'])) {
            $fields[] = [
                'name' => 'Referenced offer (unresolved)',
                'value' => (string) $payload['attempted_offer_id'],
                'inline' => true,
            ];
        }
        if (! empty($payload['rejected_reason'])) {
            $fields[] = [
                'name' => 'Rejection reason',
                'value' => substr((string) $payload['rejected_reason'], 0, 1024),
                'inline' => false,
            ];
        }

        return $fields;
    }

    /**
     * Resolve the offer/contract issuer to "Character (Corporation)" using
     * SeAT's tables. Returns null when no issuer id is in the payload.
     */
    private function resolveIssuer(array $payload): ?string
    {
        $charId = (int) ($payload['issuer_character_id'] ?? $payload['issuer_id'] ?? 0);
        if ($charId <= 0) {
            return null;
        }

        $name = DB::table('character_infos')->where('character_id', $charId)->value('name');
        $corpId = DB::table('character_affiliations')->where('character_id', $charId)->value('corporation_id');
        $corpName = $corpId
            ? DB::table('corporation_infos')->where('corporation_id', $corpId)->value('name')
            : null;

        $name = $name ?: ('Character #' . $charId);

        return $corpName ? ($name . ' (' . $corpName . ')') : $name;
    }

    /**
     * Friendly label for a contract target type, used when the frozen
     * send-to name is unavailable.
     */
    private function targetTypeLabel(string $type): string
    {
        return match ($type) {
            'my_corp' => 'My Corporation',
            'corp' => 'Specific Corporation',
            'player' => 'Specific Player',
            default => ucfirst($type),
        };
    }
}
