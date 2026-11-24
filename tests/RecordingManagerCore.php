<?php

namespace BuybackManager\Tests;

use BuybackManager\Services\Pricing\PriceProviderService;

/**
 * The real subscription upkeep, ledger included, with Manager Core installed
 * and every bridge call kept. Each capability answers what it was given, or
 * throws when it was given an exception, or answers what a closure it was
 * given returns for the call's arguments.
 */
class RecordingManagerCore extends PriceProviderService
{
    /** @var array<int, array{0: string, 1: array}> every bridge call, in order */
    public array $calls = [];

    /** @var array<string, mixed> */
    public array $answers = [];

    protected function managerCoreInstalled(): bool
    {
        return true;
    }

    protected function mcBridge(): ?object
    {
        $mc = $this;

        return new class ($mc) {
            public function __construct(private RecordingManagerCore $mc)
            {
            }

            public function call(string $plugin, string $capability, mixed ...$args): mixed
            {
                $this->mc->calls[] = [$capability, $args];

                $answer = $this->mc->answers[$capability] ?? null;
                if ($answer instanceof \Throwable) {
                    throw $answer;
                }

                return $answer instanceof \Closure ? $answer(...$args) : $answer;
            }
        };
    }

    /** @return array<int, array> the arguments of each call to this capability */
    public function callsTo(string $capability): array
    {
        return array_values(array_map(
            fn (array $call) => $call[1],
            array_filter($this->calls, fn (array $call) => $call[0] === $capability)
        ));
    }
}
