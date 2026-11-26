<?php

namespace BuybackManager\Services;

use BuybackManager\Models\BuybackContract;
use BuybackManager\Models\BuybackNotificationLog;
use BuybackManager\Models\BuybackWebhook;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Dispatches buyback events to configured Discord webhooks.
 *
 * Event-to-category mapping is the only thing that connects domain
 * events (buyback.contract.matched, buyback.contract.flagged, etc.) to
 * webhook subscription categories. This keeps the BuybackWebhook
 * categories array meaningful for operators (contract_matched,
 * contract_unmatched, etc.) without leaking event-naming details into
 * the settings UI.
 *
 * Every post gets a row in buyback_manager_notification_log before it is sent.
 * The unique (webhook_id, payload_hash) key decides which worker sends
 * it, and the row keeps the body, so a post Discord refused, or one the
 * local cap held back, is sent again by retryPending() on a later sync.
 */
class WebhookDispatcher
{
    /**
     * Maps fully-qualified event names to webhook category keys. The
     * category keys are what operators see in the Settings UI and what
     * BuybackWebhook stores in its JSON column.
     */
    private const EVENT_TO_CATEGORY = [
        // First sighting fires exactly ONE of matched / flagged /
        // unmatched, so a webhook subscribing to several of these can
        // never be double-pinged for a single contract. (The payload_hash
        // dedup would not catch that on its own: different event names
        // hash differently.)
        'buyback.contract.matched' => BuybackWebhook::CATEGORY_CONTRACT_MATCHED,
        'buyback.contract.flagged' => BuybackWebhook::CATEGORY_CONTRACT_FLAGGED,
        'buyback.contract.unmatched' => BuybackWebhook::CATEGORY_CONTRACT_UNMATCHED,
        // Lifecycle transitions after first sighting. A lapse ends the
        // buyback as surely as a cancel, so it is heard in the same place.
        'buyback.contract.completed' => BuybackWebhook::CATEGORY_CONTRACT_COMPLETED,
        'buyback.contract.cancelled' => BuybackWebhook::CATEGORY_CONTRACT_CANCELLED,
        'buyback.contract.expired' => BuybackWebhook::CATEGORY_CONTRACT_CANCELLED,
        'buyback.contract.nudge' => BuybackWebhook::CATEGORY_CONTRACT_NUDGE,
        // The price provider failing, and answering again. Each is sent once
        // per change, so a provider that stays down is announced once.
        'buyback.pricing.failing' => BuybackWebhook::CATEGORY_PRICE_PROVIDER,
        'buyback.pricing.recovered' => BuybackWebhook::CATEGORY_PRICE_PROVIDER,
        // SeAT losing the programme's contracts, and getting them back. Also
        // once per change.
        'buyback.feed.failing' => BuybackWebhook::CATEGORY_CONTRACT_FEED,
        'buyback.feed.recovered' => BuybackWebhook::CATEGORY_CONTRACT_FEED,
    ];

    private const HTTP_TIMEOUT = 10;

    /**
     * Soft cap on posts per webhook per minute. Discord's documented
     * limit is 30/min; we throttle at 25 to leave headroom for concurrent
     * workers. A post held back by the cap waits for retryPending().
     */
    private const PER_WEBHOOK_RATE_LIMIT = 25;

    /**
     * Rate-limit window in seconds. Matches Discord's per-webhook limit.
     */
    private const PER_WEBHOOK_RATE_WINDOW = 60;

    /**
     * Posts Discord answered are counted; three answers that were not a
     * success mean the webhook itself is wrong, and trying again will not
     * fix it. A post the local cap held back was never tried, so it does
     * not count.
     */
    public const MAX_ATTEMPTS = 3;

    /**
     * A post is worth sending late for a day. After that the contract has
     * moved on, and the ping would only confuse.
     */
    private const RETRY_WITHIN_HOURS = 24;

    /**
     * A row touched more recently than this may still be on its way out:
     * the claim is written before the post, and the post can take up to
     * HTTP_TIMEOUT. Leaving it alone stops a retry racing the first send.
     */
    private const IN_FLIGHT_MINUTES = 2;

    /** Retries sent per sweep, so a long outage drains over a few syncs. */
    private const RETRY_BATCH = 100;

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
        // A row for this exact event means it went out already, or is
        // waiting for retryPending(). Either way it is not sent from here.
        $already = BuybackNotificationLog::where('webhook_id', $webhook->id)
            ->where('payload_hash', $payloadHash)
            ->exists();
        if ($already) {
            return;
        }

        $body = $this->buildPayload($eventName, $payload, $webhook);

        try {
            $log = BuybackNotificationLog::create([
                'webhook_id' => $webhook->id,
                'event_name' => $eventName,
                'payload_hash' => $payloadHash,
                'payload' => $body,
                'attempts' => 0,
                'sent_at' => now(),
                'status' => BuybackNotificationLog::STATUS_PENDING,
            ]);
        } catch (\Throwable $e) {
            // Another worker claimed the same row between the check and
            // the insert. It sends the post, so this one must not.
            Log::debug('[Buyback Manager] Notification log insert raced: ' . $e->getMessage());

            return;
        }

        $this->deliver($webhook, $log, $body);
    }

    /**
     * Send again what Discord refused, or what the local cap held back.
     * Runs inside the contract-sync cycle, so the wait between tries is the
     * sync interval. A webhook switched off or deleted since is skipped:
     * the operator chose that.
     *
     * @return int posts tried
     */
    public function retryPending(): int
    {
        $tried = 0;

        $rows = BuybackNotificationLog::with('webhook')
            ->where('status', '!=', BuybackNotificationLog::STATUS_SENT)
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->whereNotNull('payload')
            ->where('created_at', '>=', now()->subHours(self::RETRY_WITHIN_HOURS))
            ->where('updated_at', '<', now()->subMinutes(self::IN_FLIGHT_MINUTES))
            ->orderBy('id')
            ->limit(self::RETRY_BATCH)
            ->get();

        foreach ($rows as $log) {
            $webhook = $log->webhook;
            if ($webhook === null || ! $webhook->enabled || ! is_array($log->payload)) {
                continue;
            }

            $this->deliver($webhook, $log, $log->payload);
            $tried++;
        }

        return $tried;
    }

    /**
     * Post a sample event to this one webhook, whatever it subscribes to,
     * and say how Discord answered. Nothing else hears it, it is never
     * retried, and it does not touch the dedup of real events.
     */
    public function sendTest(BuybackWebhook $webhook, string $eventName, array $payload): BuybackNotificationLog
    {
        $body = $this->buildPayload($eventName, $payload, $webhook);

        $log = BuybackNotificationLog::create([
            'webhook_id' => $webhook->id,
            'event_name' => 'test:' . $eventName,
            'payload_hash' => sha1('test|' . Str::uuid()->toString()),
            'payload' => null,
            'attempts' => 0,
            'sent_at' => now(),
            'status' => BuybackNotificationLog::STATUS_PENDING,
        ]);

        $this->deliver($webhook, $log, $body);

        return $log;
    }

    /**
     * One try at posting a claimed row, with the outcome written back to it.
     */
    protected function deliver(BuybackWebhook $webhook, BuybackNotificationLog $log, array $body): void
    {
        if (! $this->takeRateSlot($webhook)) {
            Log::warning('[Buyback Manager] Webhook ' . $webhook->id . ' rate-limited (cap reached for ' . self::PER_WEBHOOK_RATE_WINDOW . 's window)', [
                'event_name' => $log->event_name,
            ]);
            $this->record($log, [
                'status' => BuybackNotificationLog::STATUS_RATE_LIMITED,
                'error' => 'Held back by the local cap (' . self::PER_WEBHOOK_RATE_LIMIT . ' posts a minute per webhook)',
                'sent_at' => now(),
            ]);

            return;
        }

        $status = BuybackNotificationLog::STATUS_SENT;
        $error = null;

        try {
            if (! $webhook->url) {
                throw new \RuntimeException('The webhook URL cannot be read with this app key. Enter it again under Settings.');
            }

            $response = $this->post($webhook->url, $body);

            if ($response['status'] === 429) {
                $status = BuybackNotificationLog::STATUS_RATE_LIMITED;
                $error = 'Discord rate limit (HTTP 429)';
            } elseif ($response['status'] < 200 || $response['status'] >= 300) {
                $status = BuybackNotificationLog::STATUS_FAILED;
                $error = 'HTTP ' . $response['status'] . ': ' . substr($response['body'], 0, 500);
            }
        } catch (\Throwable $e) {
            $status = BuybackNotificationLog::STATUS_FAILED;
            $error = $e->getMessage();
            Log::warning('[Buyback Manager] Webhook dispatch threw for ' . $webhook->id . ': ' . $error);
        }

        $this->record($log, [
            'status' => $status,
            'error' => $error,
            'attempts' => (int) $log->attempts + 1,
            'sent_at' => now(),
        ]);
    }

    /**
     * Discord's limit is per webhook per minute. The counter is a fixed
     * cache bucket, not a sliding window: simple, and accurate enough.
     */
    protected function takeRateSlot(BuybackWebhook $webhook): bool
    {
        $rateKey = 'bb:webhook:rate:' . $webhook->id;
        $first = Cache::add($rateKey, 1, self::PER_WEBHOOK_RATE_WINDOW);
        $count = $first ? 1 : (int) Cache::increment($rateKey);

        return $count <= self::PER_WEBHOOK_RATE_LIMIT;
    }

    /**
     * The one place a post leaves the plugin.
     *
     * @return array{status: int, body: string}
     */
    protected function post(string $url, array $body): array
    {
        $response = Http::timeout(self::HTTP_TIMEOUT)
            ->asJson()
            ->post($url, $body);

        return ['status' => $response->status(), 'body' => (string) $response->body()];
    }

    private function record(BuybackNotificationLog $log, array $values): void
    {
        try {
            $log->update($values);
        } catch (\Throwable $e) {
            Log::warning('[Buyback Manager] Notification log update failed for ' . $log->id . ': ' . $e->getMessage());
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
        $fields = match (true) {
            str_starts_with($eventName, 'buyback.pricing.') => $this->pricingFields($eventName, $payload),
            str_starts_with($eventName, 'buyback.feed.') => $this->feedFields($eventName, $payload),
            default => $this->fieldsFor($eventName, $payload),
        };

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
        $contractId = $payload['contract_id'] ?? null;
        $valueText = isset($payload['total_buyback_value'])
            ? number_format((float) $payload['total_buyback_value'], 2) . ' ISK'
            : null;

        $provider = $this->providerLabel((string) ($payload['provider'] ?? ''));
        $corporation = ! empty($payload['corporation_name']) ? ' for ' . $payload['corporation_name'] : '';

        return match ($eventName) {
            'buyback.contract.matched' => 'New buyback contract' . ($contractId ? " #{$contractId}" : '') . ($valueText ? ": {$valueText}" : ''),
            'buyback.contract.flagged' => 'Buyback contract needs review' . ($contractId ? " #{$contractId}" : ''),
            'buyback.contract.unmatched' => 'Unmatched buyback attempt' . ($contractId ? " (contract #{$contractId})" : ''),
            'buyback.contract.completed' => 'Buyback completed' . ($valueText ? ": {$valueText}" : ''),
            'buyback.contract.cancelled' => 'Buyback contract cancelled' . ($contractId ? " #{$contractId}" : ''),
            'buyback.contract.expired' => 'Buyback contract expired unaccepted' . ($contractId ? " #{$contractId}" : ''),
            'buyback.contract.nudge' => 'Buyback contract awaiting action' . ($contractId ? " #{$contractId}" : ''),
            'buyback.pricing.failing' => "Buyback prices: {$provider} is not answering{$corporation}",
            'buyback.pricing.recovered' => "Buyback prices: {$provider} is answering again{$corporation}",
            'buyback.feed.failing' => "Buyback contracts are not coming in{$corporation}",
            'buyback.feed.recovered' => "Buyback contracts are coming in again{$corporation}",
            default => 'Buyback event: ' . $eventName,
        };
    }

    private function colorFor(string $eventName): int
    {
        // Indigo / green / yellow / red palette matching the canonical
        // design system tokens (#6366f1, #22c55e, #eab308, #ef4444).
        return match ($eventName) {
            'buyback.contract.matched' => 0x6366f1,
            'buyback.contract.completed' => 0x22c55e,
            'buyback.contract.unmatched',
            'buyback.contract.cancelled',
            'buyback.contract.expired',
            'buyback.contract.nudge' => 0xeab308,
            'buyback.contract.flagged',
            'buyback.pricing.failing',
            'buyback.feed.failing' => 0xef4444,
            'buyback.pricing.recovered',
            'buyback.feed.recovered' => 0x22c55e,
            default => 0x6366f1,
        };
    }

    /**
     * What failed and since when, and what prices quotes until it answers.
     */
    private function pricingFields(string $eventName, array $payload): array
    {
        $fields = [];
        $add = function (string $name, ?string $value, bool $inline = true) use (&$fields) {
            if ($value !== null && $value !== '') {
                $fields[] = ['name' => $name, 'value' => mb_substr($value, 0, 1024), 'inline' => $inline];
            }
        };

        $add('Provider', $this->providerLabel((string) ($payload['provider'] ?? ''))
            . (! empty($payload['market']) ? ' @ ' . $payload['market'] : ''));

        if ($eventName === 'buyback.pricing.recovered') {
            $add('Not answering since', ! empty($payload['since']) ? $this->formatTime((string) $payload['since']) : null);

            return $fields;
        }

        $add('Since', ! empty($payload['since']) ? $this->formatTime((string) $payload['since']) : null);
        $add('Last answered', ! empty($payload['last_success']) ? $this->formatTime((string) $payload['last_success']) : 'Never');
        $add('Error', $payload['error'] ?? null, false);

        $cached = 'Anything else keeps the last cached price, and an item never priced is not accepted.';
        if (empty($payload['fallback'])) {
            // The reason is a sentence of its own: none set, or why the one
            // set cannot run.
            $meanwhile = (! empty($payload['fallback_error']) ? $payload['fallback_error'] : 'No fallback provider is set.')
                . ' Quotes use the last cached prices, and an item never priced is not accepted.';
        } elseif (! empty($payload['fallback_ok'])) {
            $meanwhile = $payload['fallback'] . ' prices quotes. ' . $cached
                . (! empty($payload['fallback_note']) ? ' ' . $payload['fallback_note'] : '');
        } else {
            $meanwhile = $payload['fallback'] . ' is not answering either'
                . (! empty($payload['fallback_error']) ? ' (' . $payload['fallback_error'] . ')' : '')
                . '. Quotes use the last cached prices, and an item never priced is not accepted.';
        }
        $add('Meanwhile', $meanwhile, false);
        $add('What to do', $payload['advice'] ?? null, false);

        return $fields;
    }

    /**
     * Where contracts should be coming from, why they are not, and what
     * brings them back.
     */
    private function feedFields(string $eventName, array $payload): array
    {
        $fields = [];
        $add = function (string $name, ?string $value, bool $inline = true) use (&$fields) {
            if ($value !== null && $value !== '') {
                $fields[] = ['name' => $name, 'value' => mb_substr($value, 0, 1024), 'inline' => $inline];
            }
        };

        $add('Send to', $payload['send_to'] ?? null);
        $since = ! empty($payload['since']) ? $this->formatTime((string) $payload['since']) : null;

        if ($eventName === 'buyback.feed.recovered') {
            $add('Not coming in since', $since);

            return $fields;
        }

        $add('Since', $since);
        $add('Why', $payload['reason'] ?? null, false);
        $add('Meanwhile', 'New contracts are not seen, so none is checked, posted or credited. Each is picked up on the first sync after SeAT reads it.', false);
        $add('What to do', $payload['advice'] ?? null, false);

        return $fields;
    }

    private function fieldsFor(string $eventName, array $payload): array
    {
        $fields = [];

        if (isset($payload['total_buyback_value'])) {
            $fields[] = [
                'name' => 'Quoted',
                'value' => number_format((float) $payload['total_buyback_value'], 2) . ' ISK',
                'inline' => true,
            ];
        }
        // Already off the quote above, so the seller is not paid for hauling
        // the goods to market twice.
        if (! empty($payload['haul_fee']) && (float) $payload['haul_fee'] > 0) {
            $fields[] = [
                'name' => 'Haul fee',
                'value' => number_format((float) $payload['haul_fee'], 2) . ' ISK off the quote',
                'inline' => true,
            ];
        }
        // Only worth showing alongside the quote when they differ — an
        // exact match would just repeat the line above.
        if (isset($payload['asked_price'], $payload['deviation_percent'])
            && $payload['deviation_percent'] !== null
            && abs((float) $payload['deviation_percent']) > 0.001) {
            $deviation = (float) $payload['deviation_percent'];
            $fields[] = [
                'name' => 'Asked',
                'value' => number_format((float) $payload['asked_price'], 2) . ' ISK'
                    . ' (' . ($deviation > 0 ? '+' : '') . number_format($deviation, 2) . '%)',
                'inline' => true,
            ];
        }
        if (! empty($payload['appraisal_public_id'])) {
            $fields[] = [
                'name' => 'Appraisal',
                'value' => '`' . $payload['appraisal_public_id'] . '`',
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
            $marketValue = ucfirst((string) $payload['market']);
            if (! empty($payload['provider'])) {
                $marketValue = $this->providerLabel((string) $payload['provider']) . ' @ ' . $marketValue;
            }
            $fields[] = [
                'name' => 'Market',
                'value' => $marketValue,
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
        if (isset($payload['contract_id'])) {
            $fields[] = [
                'name' => 'Contract ID',
                'value' => (string) $payload['contract_id'],
                'inline' => true,
            ];
        }
        if ($eventName === 'buyback.contract.expired' && ! empty($payload['expires_at'])) {
            $fields[] = [
                'name' => 'Expired',
                'value' => $this->formatTime((string) $payload['expires_at']),
                'inline' => true,
            ];
        }
        if (! empty($payload['attempted_appraisal_key'])) {
            $fields[] = [
                'name' => 'Quoted key (unresolved)',
                'value' => '`' . $payload['attempted_appraisal_key'] . '`',
                'inline' => true,
            ];
        }
        // Why the contract needs review, spelled out rather than as codes.
        if (! empty($payload['flags']) && is_array($payload['flags'])) {
            $labels = array_map(
                fn ($flag) => '• ' . BuybackContract::flagText((string) $flag, $payload['seller_note'] ?? null),
                $payload['flags']
            );
            $fields[] = [
                'name' => 'Needs review',
                'value' => substr(implode("\n", $labels), 0, 1024),
                'inline' => false,
            ];
        }
        return $fields;
    }

    /**
     * Resolve the contract issuer to "Character (Corporation)" using
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

    private function providerLabel(string $provider): string
    {
        return match ($provider) {
            'manager-core' => 'Manager Core',
            'fuzzwork' => 'Fuzzwork',
            'janice' => 'Janice',
            default => ucfirst($provider),
        };
    }

    /**
     * Render an ISO timestamp as EVE (UTC) time plus Discord's locale-aware
     * timestamp markdown, so each reader also sees the time in their own
     * timezone and how long ago it was. Falls back to the raw string if
     * parsing fails.
     */
    private function formatTime(string $iso): string
    {
        try {
            $ts = \Illuminate\Support\Carbon::parse($iso);
            $unix = $ts->getTimestamp();

            return $ts->utc()->format('Y-m-d H:i') . ' EVE'
                . "\n<t:{$unix}:f> · <t:{$unix}:R>";
        } catch (\Throwable $e) {
            return $iso;
        }
    }
}
