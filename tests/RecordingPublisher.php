<?php

namespace BuybackManager\Tests;

use BuybackManager\Services\EventPublisher;

/**
 * Keeps every publish instead of sending it, so a test can see exactly what
 * the sync announced and how often.
 */
final class RecordingPublisher extends EventPublisher
{
    /** @var array<int, array{name: string, payload: array}> */
    public array $events = [];

    public function __construct()
    {
    }

    public int $retries = 0;

    public function publish(string $eventName, array $payload): void
    {
        $this->events[] = ['name' => $eventName, 'payload' => $payload];
    }

    /**
     * Alerts about a programme, kept apart from contract events so a test
     * of one never has to account for the other.
     *
     * @var array<int, array{name: string, payload: array}>
     */
    public array $alerts = [];

    public function announce(string $eventName, array $payload): void
    {
        $this->alerts[] = ['name' => $eventName, 'payload' => $payload];
    }

    public function retryWebhooks(): void
    {
        $this->retries++;
    }

    /** @return array<int, string> */
    public function names(): array
    {
        return array_column($this->events, 'name');
    }

    public function clear(): void
    {
        $this->events = [];
    }
}
