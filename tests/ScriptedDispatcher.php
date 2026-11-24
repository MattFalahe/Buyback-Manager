<?php

namespace BuybackManager\Tests;

use BuybackManager\Services\WebhookDispatcher;

/**
 * The real dispatcher with Discord replaced by a script of answers: each post
 * takes the next one, and a plain 204 once the script runs out.
 */
final class ScriptedDispatcher extends WebhookDispatcher
{
    /** @var array<int, int|\Throwable> */
    public array $answers = [];

    /** @var array<int, array{url: string, body: array}> */
    public array $posts = [];

    protected function post(string $url, array $body): array
    {
        $this->posts[] = ['url' => $url, 'body' => $body];

        $answer = array_shift($this->answers) ?? 204;
        if ($answer instanceof \Throwable) {
            throw $answer;
        }

        return ['status' => $answer, 'body' => $answer >= 300 ? '{"message": "Unknown Webhook", "code": 10015}' : ''];
    }

    /** @return array<int, string> */
    public function urls(): array
    {
        return array_column($this->posts, 'url');
    }
}
