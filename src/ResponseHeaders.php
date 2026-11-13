<?php

declare(strict_types=1);

namespace IndianOpinion\CommentsThroughCache;

/**
 * The seam between the plugin and PHP's header and cookie functions.
 */
interface ResponseHeaders
{
    public function headersSent(): bool;

    /**
     * Sends one header line, replacing an earlier header of the same name.
     */
    public function header(string $name, string $value): void;

    /**
     * Sends one Set-Cookie header.
     *
     * @param array{expires: int, path: string, domain: string, secure: bool, httponly: bool, samesite?: 'Lax'|'Strict'|'None'} $options
     */
    public function setCookie(string $name, string $value, array $options): void;
}
