<?php

namespace BuybackManager\Tests\Unit;

use BuybackManager\Models\BuybackNotificationLog;
use BuybackManager\Models\BuybackSetting;
use BuybackManager\Models\BuybackWebhook;
use BuybackManager\Tests\ScriptedDispatcher;
use BuybackManager\Tests\Sqlite;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;

class EncryptedSecretsTest extends TestCase
{
    private const URL = 'https://discord.test/api/webhooks/1/secret-token';
    private const JANICE_KEY = 'G9KwKq3465588VPd6747t95Zh94q3W2E';

    protected function setUp(): void
    {
        Sqlite::migrate('000001', '000010', '000011', '000016', '000017');
    }

    protected function tearDown(): void
    {
        Sqlite::close();
    }

    private function webhook(): BuybackWebhook
    {
        return BuybackWebhook::create([
            'corporation_id' => 98000001,
            'name' => 'Directors',
            'url' => self::URL,
            'enabled' => true,
            'categories' => [BuybackWebhook::CATEGORY_CONTRACT_MATCHED],
        ]);
    }

    private static function stored(string $table, string $column, int $id): string
    {
        return (string) DB::table($table)->where('id', $id)->value($column);
    }

    public function testAWebhookUrlIsStoredEncryptedAndReadBackWhole(): void
    {
        $webhook = $this->webhook();

        $raw = self::stored('buyback_manager_webhooks', 'url', $webhook->id);
        $this->assertStringNotContainsString('secret-token', $raw);
        $this->assertSame(self::URL, BuybackWebhook::find($webhook->id)->url);
    }

    public function testAJaniceKeyIsStoredEncryptedAndNeverSerialised(): void
    {
        $setting = BuybackSetting::create([
            'corporation_id' => 98000001,
            'target_type' => BuybackSetting::TARGET_MY_CORP,
            'price_provider' => 'janice',
            'janice_api_key' => self::JANICE_KEY,
        ]);

        $this->assertStringNotContainsString(self::JANICE_KEY, self::stored('buyback_manager_settings', 'janice_api_key', $setting->id));
        $this->assertSame(self::JANICE_KEY, BuybackSetting::find($setting->id)->janice_api_key);
        $this->assertArrayNotHasKey('janice_api_key', BuybackSetting::find($setting->id)->toArray());
    }

    public function testAValueSavedBeforeEncryptionStillWorksAndIsEncryptedOnItsNextSave(): void
    {
        $webhook = $this->webhook();
        DB::table('buyback_manager_webhooks')->where('id', $webhook->id)->update(['url' => self::URL]);

        $loaded = BuybackWebhook::find($webhook->id);
        $this->assertSame(self::URL, $loaded->url);

        $loaded->update(['url' => $loaded->url, 'name' => 'Directors and CEO']);
        $this->assertStringNotContainsString('secret-token', self::stored('buyback_manager_webhooks', 'url', $webhook->id));
        $this->assertSame(self::URL, BuybackWebhook::find($webhook->id)->url);
    }

    public function testAUrlEncryptedUnderAnotherKeyIsNeverPostedAsCiphertext(): void
    {
        $webhook = $this->webhook();
        $foreign = (new Encrypter(random_bytes(32), 'aes-256-cbc'))->encryptString(self::URL);
        DB::table('buyback_manager_webhooks')->where('id', $webhook->id)->update(['url' => $foreign]);

        $loaded = BuybackWebhook::find($webhook->id);
        $this->assertNull($loaded->url);

        $discord = new ScriptedDispatcher();
        $log = $discord->sendTest($loaded, 'buyback.contract.matched', [
            'corporation_id' => 98000001,
            'contract_id' => 300000001,
            'status' => 'outstanding',
            'total_buyback_value' => 45000000.0,
            'items_count' => 2,
            'appraisal_public_id' => 'bb-k3y00001',
        ]);

        $this->assertSame([], $discord->posts);
        $this->assertSame(BuybackNotificationLog::STATUS_FAILED, $log->fresh()->status);
        $this->assertStringContainsString('Enter it again', (string) $log->fresh()->error);
    }
}
