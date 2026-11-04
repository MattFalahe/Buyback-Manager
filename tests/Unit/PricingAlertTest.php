<?php

namespace BuybackManager\Tests\Unit;

use BuybackManager\Models\BuybackWebhook;
use BuybackManager\Services\WebhookDispatcher;
use PHPUnit\Framework\TestCase;

class PricingAlertTest extends TestCase
{
    private const FAILING = [
        'corporation_id' => 98000001,
        'corporation_name' => 'Test Corp',
        'provider' => 'fuzzwork',
        'market' => 'Amarr',
        'error' => 'Fuzzwork returned HTTP 503',
        'since' => '2026-11-04T10:00:00+00:00',
        'last_success' => null,
        'advice' => 'Test the provider under Buyback Settings, or pick another one. The Diagnostics page shows the last error.',
    ];

    private function embed(string $event, array $payload): array
    {
        $dispatcher = new class extends WebhookDispatcher {
            public function embedFor(string $event, array $payload): array
            {
                return $this->buildEmbed($event, $payload);
            }
        };

        return $dispatcher->embedFor($event, $payload);
    }

    private static function field(array $embed, string $name): ?string
    {
        foreach ($embed['fields'] as $field) {
            if ($field['name'] === $name) {
                return $field['value'];
            }
        }

        return null;
    }

    public function test_every_category_hears_its_own_events_and_every_event_has_a_category(): void
    {
        $routes = (new \ReflectionClass(WebhookDispatcher::class))->getConstant('EVENT_TO_CATEGORY');

        $named = [];
        foreach (BuybackWebhook::categoryMeta() as $category => $meta) {
            foreach ($meta['events'] as $event) {
                $this->assertSame($category, $routes[$event] ?? null, $event);
                $named[] = $event;
            }
        }

        $this->assertEqualsCanonicalizing(array_keys($routes), $named);
        $this->assertEqualsCanonicalizing(BuybackWebhook::ALL_CATEGORIES, array_keys(BuybackWebhook::categoryMeta()));
        $this->assertEqualsCanonicalizing(BuybackWebhook::ALL_CATEGORIES, array_merge(...array_column(BuybackWebhook::categoryGroups(), 'keys')));
    }

    public function test_a_failing_alert_names_the_provider_and_when_it_last_answered(): void
    {
        $embed = $this->embed('buyback.pricing.failing', self::FAILING + ['fallback' => 'Janice, Amarr', 'fallback_ok' => true]);

        $this->assertSame('Buyback prices: Fuzzwork is not answering for Test Corp', $embed['title']);
        $this->assertSame('Fuzzwork @ Amarr', self::field($embed, 'Provider'));
        $this->assertSame('Never', self::field($embed, 'Last answered'));
        $this->assertSame('Fuzzwork returned HTTP 503', self::field($embed, 'Error'));
        $this->assertStringStartsWith('2026-11-04 10:00 EVE', self::field($embed, 'Since'));
    }

    public function test_a_failing_alert_says_what_quotes_use_meanwhile(): void
    {
        $meanwhile = fn (array $fallback) => self::field($this->embed('buyback.pricing.failing', self::FAILING + $fallback), 'Meanwhile');

        $this->assertStringStartsWith(
            'Janice, Amarr prices quotes.',
            $meanwhile(['fallback' => 'Janice, Amarr', 'fallback_ok' => true])
        );
        $this->assertStringStartsWith(
            'Janice, Amarr is not answering either (HTTP 429).',
            $meanwhile(['fallback' => 'Janice, Amarr', 'fallback_ok' => false, 'fallback_error' => 'HTTP 429'])
        );
        $this->assertSame(
            'No fallback provider is set. Quotes use the last cached prices, and an item never priced is not accepted.',
            $meanwhile(['fallback' => null, 'fallback_ok' => false, 'fallback_error' => 'No fallback provider is set.'])
        );
        $this->assertStringStartsWith(
            'The fallback provider cannot run: Janice has no API key. Quotes use',
            $meanwhile(['fallback' => null, 'fallback_ok' => false, 'fallback_error' => 'The fallback provider cannot run: Janice has no API key.'])
        );
    }

    public function test_the_all_clear_says_since_when_the_provider_was_not_answering(): void
    {
        $embed = $this->embed('buyback.pricing.recovered', ['provider' => 'fuzzwork', 'market' => 'Amarr', 'corporation_name' => 'Test Corp', 'since' => '2026-11-04T10:00:00+00:00']);

        $this->assertSame('Buyback prices: Fuzzwork is answering again for Test Corp', $embed['title']);
        $this->assertStringStartsWith('2026-11-04 10:00 EVE', self::field($embed, 'Not answering since'));
        $this->assertNull(self::field($embed, 'Meanwhile'));
    }
}
