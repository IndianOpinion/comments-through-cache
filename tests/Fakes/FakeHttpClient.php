<?php

declare(strict_types=1);

namespace IndianOpinion\CommentsThroughCache\Tests\Fakes;

use IndianOpinion\CommentsThroughCache\Http\HttpClient;
use IndianOpinion\CommentsThroughCache\Http\HttpResponse;

final class FakeHttpClient implements HttpClient
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string, timeout: int}> */
    public array $requests = [];

    /** @var callable(string, string, array<string, string>, ?string): HttpResponse */
    private $handler;

    /**
     * @param callable(string, string, array<string, string>, ?string): HttpResponse|null $handler
     */
    public function __construct(?callable $handler = null)
    {
        $this->handler = $handler ?? static fn (): HttpResponse => new HttpResponse(200);
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 3): HttpResponse
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body, 'timeout' => $timeout];
        return ($this->handler)($method, $url, $headers, $body);
    }
}
