<?php

namespace BuybackManager\Tests;

use BuybackManager\Models\BuybackSetting;
use BuybackManager\Services\Pricing\PriceProviderService;

/**
 * The real price chain with each provider's answer scripted, Manager Core
 * stood in for, and alerts kept instead of sent. The cache and the status row
 * are the real ones, in the test's database.
 */
class ScriptedPrices extends PriceProviderService
{
    /** @var array<string, array<string, array{prices: array, unanswered: int[], error: ?string, throws: ?string}>> */
    private array $scripts = [];

    /** @var array<int, array{0: string, 1: string, 2: int[]}> every request, in order */
    public array $asked = [];

    /** @var string[] requests nothing was scripted for */
    public array $unscripted = [];

    /** @var array<int, array{0: string, 1: array}> every alert, in order */
    public array $alerts = [];

    /** @var array<int, array{0: int[], 1: string, 2: bool}> */
    public array $subscribed = [];

    public bool $managerCore = false;

    public ?array $preference = null;

    /**
     * The provider answers every item asked at this market: with the price
     * listed, with no orders when there is none, or not at all for the ones
     * left unanswered. An error says a request failed along the way.
     */
    public function answers(string $provider, string $market, array $prices, array $unanswered = [], ?string $error = null): self
    {
        $this->scripts[$provider][$market] = ['prices' => $prices, 'unanswered' => $unanswered, 'error' => $error, 'throws' => null];

        return $this;
    }

    /** Asking the provider at this market fails outright. */
    public function fails(string $provider, string $market, string $error): self
    {
        $this->scripts[$provider][$market] = ['prices' => [], 'unanswered' => [], 'error' => null, 'throws' => $error];

        return $this;
    }

    /** The note a quote with this outcome carries. */
    public function noteFor(array $result): ?string
    {
        return $this->describe($result);
    }

    protected function fetchFrom(string $provider, string $market, array $typeIds, BuybackSetting $setting): array
    {
        $this->asked[] = [$provider, $market, array_values($typeIds)];

        $script = $this->scripts[$provider][$market] ?? null;
        if ($script === null) {
            $this->unscripted[] = "$provider at $market";

            throw new \RuntimeException("$provider at $market was not scripted");
        }

        if ($script['throws'] !== null) {
            throw new \RuntimeException($script['throws']);
        }

        $answer = ['prices' => [], 'answered' => [], 'unanswered' => [], 'errors' => 0, 'error' => null];
        foreach ($typeIds as $typeId) {
            if (in_array($typeId, $script['unanswered'], true)) {
                $answer['unanswered'][] = $typeId;
                continue;
            }

            $answer['answered'][] = $typeId;
            if (isset($script['prices'][$typeId])) {
                $answer['prices'][$typeId] = $script['prices'][$typeId];
            }
        }

        if ($script['error'] !== null) {
            $answer['errors'] = 1;
            $answer['error'] = $script['error'];
        }

        return $answer;
    }

    protected function announce(string $eventName, array $payload): void
    {
        $this->alerts[] = [$eventName, $payload];
    }

    protected function managerCoreInstalled(): bool
    {
        return $this->managerCore;
    }

    public function managerCorePreference(): ?array
    {
        return $this->preference;
    }

    public function subscribeToManagerCore(array $typeIds, string $market = 'jita', bool $immediateRefresh = false): int
    {
        $this->subscribed[] = [array_values($typeIds), $market, $immediateRefresh];

        return count($typeIds);
    }
}
