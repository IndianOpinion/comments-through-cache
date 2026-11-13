<?php

declare(strict_types=1);

namespace IndianOpinion\CommentsThroughCache;

/**
 * Sends no-store headers on the post-comment redirect URL (C17), nothing on
 * other requests (C18), and the debug headers the checker relies on.
 */
final class NoStoreHeaders
{
    public const RENDER_HEADER = 'X-Comments-Through-Cache-Render';
    public const BYPASS_HEADER = 'X-Comments-Through-Cache';

    /** @var callable(): string */
    private $idSource;

    /**
     * @param callable(): string|null $idSource Produces the render ID.
     */
    public function __construct(
        private Config $config,
        private ResponseHeaders $response,
        ?callable $idSource = null
    ) {
        $this->idSource = $idSource ?? static fn (): string => bin2hex(random_bytes(8));
    }

    /**
     * Handler for send_headers.
     */
    public function onSendHeaders(): void
    {
        if ($this->response->headersSent()) {
            return;
        }
        $redirect = self::isRedirectRequest();
        if ($redirect) {
            $this->response->header('Cache-Control', 'private, no-store, max-age=0');
            $this->response->header('X-Accel-Expires', '0');
        }
        if ($this->config->debug()) {
            $this->response->header(self::RENDER_HEADER, ($this->idSource)());
            if ($redirect) {
                $this->response->header(self::BYPASS_HEADER, 'redirect');
            } elseif (self::hasBypassCookie()) {
                $this->response->header(self::BYPASS_HEADER, 'cookie');
            }
        }
    }

    /**
     * The query argument names that mark a redirect URL.
     *
     * @return list<string>
     */
    public static function noStoreArgs(): array
    {
        $args = apply_filters('comments_through_cache_no_store_args', ['unapproved', 'moderation-hash']);
        $out = [];
        if (is_array($args)) {
            foreach ($args as $arg) {
                if (is_string($arg) && $arg !== '') {
                    $out[] = $arg;
                }
            }
        }
        return $out;
    }

    /**
     * True when the current request carries any no-store query argument.
     */
    public static function isRedirectRequest(): bool
    {
        foreach (self::noStoreArgs() as $arg) {
            if (array_key_exists($arg, $_GET)) {
                return true;
            }
        }
        return false;
    }

    /**
     * True when the request carries any cookie whose name starts with
     * comment_author_, which is what a cache bypass rule matches.
     */
    public static function hasBypassCookie(): bool
    {
        foreach (array_keys($_COOKIE) as $name) {
            if (str_starts_with((string) $name, 'comment_author_')) {
                return true;
            }
        }
        return false;
    }
}
