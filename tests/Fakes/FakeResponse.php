<?php

declare(strict_types=1);

namespace IndianOpinion\CommentsThroughCache\Tests\Fakes;

use IndianOpinion\CommentsThroughCache\ResponseHeaders;

final class FakeResponse implements ResponseHeaders
{
    public bool $sent = false;

    /** @var list<string> Every header line and Set-Cookie, in order. */
    public array $lines = [];

    /** @var array<string, string> */
    public array $headers = [];

    /** @var list<array{name: string, value: string, options: array<string, mixed>}> */
    public array $cookies = [];

    public function headersSent(): bool
    {
        return $this->sent;
    }

    public function header(string $name, string $value): void
    {
        $this->headers[$name] = $value;
        $this->lines[] = $name . ': ' . $value;
    }

    public function setCookie(string $name, string $value, array $options): void
    {
        $this->cookies[] = ['name' => $name, 'value' => $value, 'options' => $options];
        $this->lines[] = 'Set-Cookie: ' . $name . '=' . $value;
    }

    /**
     * @return list<string>
     */
    public function cookieNames(): array
    {
        return array_map(static fn (array $c): string => $c['name'], $this->cookies);
    }
}
