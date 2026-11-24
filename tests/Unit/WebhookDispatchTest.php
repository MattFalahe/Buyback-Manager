<?php

namespace BuybackManager\Tests\Unit;

use BuybackManager\Models\BuybackNotificationLog;
use BuybackManager\Models\BuybackWebhook;
use BuybackManager\Services\WebhookDispatcher;
use BuybackManager\Tests\ScriptedDispatcher;
use BuybackManager\Tests\Sqlite;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\TestCase;

/**
 * The real WebhookDispatcher on the plugin's own webhook and log tables, with
 * only the HTTP post scripted.
 */
class WebhookDispatchTest extends TestCase
{
    private const CORP = 98000001;
    private const OTHER_CORP = 98000002;

    private ScriptedDispatcher $discord;

    protected function setUp(): void
    {
        Sqlite::migrate('000010', '000011');
        Carbon::setTestNow('2026-11-21 12:00:00');
        $this->discord = new ScriptedDispatcher();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Sqlite::close();
    }

    private function webhook(string $name, array $categories, ?int $corporationId = self::CORP, bool $enabled = true): BuybackWebhook
    {
        return BuybackWebhook::create([
            'corporation_id' => $corporationId,
            'name' => $name,
            'url' => 'https://discord.test/api/webhooks/' . $name,
            'enabled' => $enabled,
            'categories' => $categories,
        ]);
    }

    /** A contract event as ContractService sends it, with a fresh event id each time. */
    private static function event(int $contractId = 300000001, array $extra = []): array
    {
        return $extra + [
            'source_plugin' => 'buyback-manager',
            'event_id' => 'bb-evt-' . bin2hex(random_bytes(8)),
            'corporation_id' => self::CORP,
            'contract_id' => $contractId,
            'status' => 'outstanding',
            'total_buyback_value' => 45000000.0,
            'items_count' => 2,
            'appraisal_public_id' => 'bb-k3y' . $contractId,
        ];
    }

    private function later(int $minutes): void
    {
        Carbon::setTestNow(Carbon::now()->addMinutes($minutes));
    }

    private static function row(BuybackWebhook $webhook): BuybackNotificationLog
    {
        return BuybackNotificationLog::where('webhook_id', $webhook->id)->sole();
    }

    public function test_an_event_reaches_each_webhook_on_its_category_and_corporation_once(): void
    {
        $corp = $this->webhook('corp', [BuybackWebhook::CATEGORY_CONTRACT_MATCHED]);
        $global = $this->webhook('global', [BuybackWebhook::CATEGORY_CONTRACT_MATCHED], null);
        $this->webhook('completions', [BuybackWebhook::CATEGORY_CONTRACT_COMPLETED]);
        $this->webhook('elsewhere', [BuybackWebhook::CATEGORY_CONTRACT_MATCHED], self::OTHER_CORP);
        $this->webhook('off', [BuybackWebhook::CATEGORY_CONTRACT_MATCHED], self::CORP, false);

        $this->discord->dispatch('buyback.contract.matched', self::event());

        $this->assertEqualsCanonicalizing([$corp->url, $global->url], $this->discord->urls());

        $sent = self::row($corp);
        $this->assertSame(BuybackNotificationLog::STATUS_SENT, $sent->status);
        $this->assertSame(1, $sent->attempts);
        $this->assertNull($sent->error);
        $this->assertSame($this->discord->posts[0]['body'], $sent->payload, 'the body is kept as it was posted');

        // ContractService builds a fresh event id for every publish. The same
        // event again must still not ping twice.
        $this->later(15);
        $this->discord->dispatch('buyback.contract.matched', self::event());
        $this->assertCount(2, $this->discord->posts);
    }

    public function test_a_refused_post_goes_out_again_on_a_later_sync_exactly_as_built(): void
    {
        $hook = $this->webhook('corp', [BuybackWebhook::CATEGORY_CONTRACT_MATCHED]);
        $this->discord->answers = [500];

        $this->discord->dispatch('buyback.contract.matched', self::event());

        $failed = self::row($hook);
        $this->assertSame(BuybackNotificationLog::STATUS_FAILED, $failed->status);
        $this->assertSame(1, $failed->attempts);
        $this->assertStringStartsWith('HTTP 500: ', $failed->error);

        // A sync in the same minute leaves it: it may still be on its way out.
        $this->assertSame(0, $this->discord->retryPending());

        $this->later(15);
        $this->assertSame(1, $this->discord->retryPending());

        $sent = self::row($hook);
        $this->assertSame(BuybackNotificationLog::STATUS_SENT, $sent->status);
        $this->assertSame(2, $sent->attempts);
        $this->assertNull($sent->error);
        $this->assertSame($this->discord->posts[0]['body'], $this->discord->posts[1]['body']);

        $this->later(15);
        $this->assertSame(0, $this->discord->retryPending());
        $this->assertCount(2, $this->discord->posts);
    }

    public function test_a_webhook_that_keeps_refusing_is_given_up_after_three_tries(): void
    {
        $hook = $this->webhook('gone', [BuybackWebhook::CATEGORY_CONTRACT_MATCHED]);
        $this->discord->answers = [404, new \RuntimeException('cURL error 28: timed out'), 404, 204];

        $this->discord->dispatch('buyback.contract.matched', self::event());
        for ($sync = 0; $sync < 4; $sync++) {
            $this->later(15);
            $this->discord->retryPending();
        }

        $this->assertCount(WebhookDispatcher::MAX_ATTEMPTS, $this->discord->posts);
        $row = self::row($hook);
        $this->assertSame(BuybackNotificationLog::STATUS_FAILED, $row->status);
        $this->assertSame(3, $row->attempts);
        $this->assertStringStartsWith('HTTP 404: ', $row->error);
    }

    public function test_discord_rate_limiting_counts_as_a_try_and_is_retried(): void
    {
        $hook = $this->webhook('busy', [BuybackWebhook::CATEGORY_CONTRACT_MATCHED]);
        $this->discord->answers = [429];

        $this->discord->dispatch('buyback.contract.matched', self::event());
        $row = self::row($hook);
        $this->assertSame(BuybackNotificationLog::STATUS_RATE_LIMITED, $row->status);
        $this->assertSame(1, $row->attempts);

        $this->later(15);
        $this->discord->retryPending();
        $this->assertSame(BuybackNotificationLog::STATUS_SENT, self::row($hook)->status);
    }

    public function test_a_post_held_back_by_the_local_cap_is_not_a_try_and_goes_out_later(): void
    {
        $hook = $this->webhook('corp', [BuybackWebhook::CATEGORY_CONTRACT_MATCHED]);

        for ($contract = 300000001; $contract <= 300000026; $contract++) {
            $this->discord->dispatch('buyback.contract.matched', self::event($contract));
        }

        $this->assertCount(25, $this->discord->posts);
        $held = BuybackNotificationLog::where('status', BuybackNotificationLog::STATUS_RATE_LIMITED)->sole();
        $this->assertSame(0, $held->attempts);
        $this->assertStringContainsString('local cap', $held->error);

        // The next sync is well past the minute the cap counts.
        $this->later(15);
        Cache::forget('bb:webhook:rate:' . $hook->id);
        $this->assertSame(1, $this->discord->retryPending());

        $held->refresh();
        $this->assertSame(BuybackNotificationLog::STATUS_SENT, $held->status);
        $this->assertSame(1, $held->attempts);
        $this->assertStringContainsString('#300000026', $this->discord->posts[25]['body']['embeds'][0]['title']);
    }

    public function test_a_post_cut_off_mid_send_is_retried_once_it_is_clearly_not_in_flight(): void
    {
        $hook = $this->webhook('corp', [BuybackWebhook::CATEGORY_CONTRACT_MATCHED]);
        BuybackNotificationLog::create([
            'webhook_id' => $hook->id,
            'event_name' => 'buyback.contract.matched',
            'payload_hash' => sha1('claimed'),
            'payload' => ['username' => 'Buyback Manager', 'embeds' => [['title' => 'New buyback contract #300000001']]],
            'attempts' => 0,
            'sent_at' => now(),
            'status' => BuybackNotificationLog::STATUS_PENDING,
        ]);

        $this->later(1);
        $this->assertSame(0, $this->discord->retryPending());

        $this->later(2);
        $this->assertSame(1, $this->discord->retryPending());
        $this->assertSame(BuybackNotificationLog::STATUS_SENT, self::row($hook)->status);
    }

    public function test_retries_skip_webhooks_switched_off_or_deleted_and_posts_a_day_old(): void
    {
        $off = $this->webhook('off', [BuybackWebhook::CATEGORY_CONTRACT_MATCHED]);
        $deleted = $this->webhook('deleted', [BuybackWebhook::CATEGORY_CONTRACT_MATCHED]);
        $this->discord->answers = [500, 500];
        $this->discord->dispatch('buyback.contract.matched', self::event());

        $off->update(['enabled' => false]);
        $deleted->delete();
        $this->later(15);
        $this->assertSame(0, $this->discord->retryPending());

        $late = $this->webhook('late', [BuybackWebhook::CATEGORY_CONTRACT_COMPLETED]);
        $this->discord->answers = [500];
        $this->discord->dispatch('buyback.contract.completed', self::event(300000002, ['status' => 'finished']));
        $this->assertSame(BuybackNotificationLog::STATUS_FAILED, self::row($late)->status);

        $this->later(25 * 60);
        $this->assertSame(0, $this->discord->retryPending());
        $this->assertCount(3, $this->discord->posts);
    }

    public function test_an_expired_contract_is_heard_on_the_cancelled_category_with_its_own_title(): void
    {
        $hook = $this->webhook('ends', [BuybackWebhook::CATEGORY_CONTRACT_CANCELLED]);
        $this->webhook('matches', [BuybackWebhook::CATEGORY_CONTRACT_MATCHED]);

        $this->discord->dispatch('buyback.contract.expired', self::event(300000001, [
            'expires_at' => '2026-11-21T10:00:00+00:00',
            'previous_status' => 'outstanding',
        ]));

        $this->assertSame([$hook->url], $this->discord->urls());
        $embed = $this->discord->posts[0]['body']['embeds'][0];
        $this->assertSame('Buyback contract expired unaccepted #300000001', $embed['title']);
        $this->assertSame(0xeab308, $embed['color']);

        $expired = null;
        foreach ($embed['fields'] as $field) {
            if ($field['name'] === 'Expired') {
                $expired = $field['value'];
            }
        }
        $this->assertNotNull($expired);
        $this->assertStringStartsWith('2026-11-21 10:00 EVE', $expired);
    }

    public function test_a_test_post_reaches_only_its_own_webhook_and_is_never_retried(): void
    {
        // Subscribes to completions only: the old test went through the
        // categories, so this one never heard it and the other one did.
        $tested = $this->webhook('tested', [BuybackWebhook::CATEGORY_CONTRACT_COMPLETED]);
        $other = $this->webhook('other', [BuybackWebhook::CATEGORY_CONTRACT_MATCHED]);
        $sample = self::event(999999999);

        $log = $this->discord->sendTest($tested, 'buyback.contract.matched', $sample);
        $this->assertSame([$tested->url], $this->discord->urls());
        $this->assertSame(BuybackNotificationLog::STATUS_SENT, $log->status);
        $this->assertSame('test:buyback.contract.matched', $log->event_name);

        // Runs as often as the operator likes.
        $this->discord->sendTest($tested, 'buyback.contract.matched', $sample);
        $this->assertCount(2, $this->discord->posts);

        $this->discord->answers = [500];
        $refused = $this->discord->sendTest($tested, 'buyback.contract.matched', $sample);
        $this->assertSame(BuybackNotificationLog::STATUS_FAILED, $refused->status);
        $this->assertStringContainsString('Unknown Webhook', $refused->error);

        $this->later(15);
        $this->assertSame(0, $this->discord->retryPending());

        // And a real event shaped like the sample still reaches its own webhook.
        $this->discord->dispatch('buyback.contract.matched', $sample);
        $this->assertSame($other->url, $this->discord->urls()[3]);
    }
}
