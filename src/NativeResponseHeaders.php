<?php

declare(strict_types=1);

namespace IndianOpinion\CommentsThroughCache;

/**
 * ResponseHeaders backed by PHP's header() and setcookie().
 */
final class NativeResponseHeaders implements ResponseHeaders
{
    public function headersSent(): bool
    {
        return headers_sent();
    }

    public function header(string $name, string $value): void
    {
        header($name . ': ' . $value, true);
    }

    public function setCookie(string $name, string $value, array $options): void
    {
        setcookie($name, $value, $options);
    }
}
