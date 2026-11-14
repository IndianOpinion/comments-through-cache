<?php

declare(strict_types=1);

namespace IndianOpinion\CommentsThroughCache\Http;

use IndianOpinion\CommentsThroughCache\PurgeException;

/**
 * The one HTTP seam in the plugin, so purgers and the checker can be tested
 * with a fake.
 */
interface HttpClient
{
    /**
     * Sends one request. Redirects are not followed.
     *
     * @param array<string, string> $headers
     *
     * @throws PurgeException When the request could not be completed
     *                        (DNS, connect, timeout, TLS).
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 3): HttpResponse;
}
