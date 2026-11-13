<?php

declare(strict_types=1);

namespace IndianOpinion\CommentsThroughCache\Purger;

use IndianOpinion\CommentsThroughCache\ConfigurationException;
use IndianOpinion\CommentsThroughCache\Http\HttpClient;
use IndianOpinion\CommentsThroughCache\PurgeException;
use IndianOpinion\CommentsThroughCache\PurgeResult;

/**
 * Sends one request per URL to a purge endpoint: nginx with a cache purge
 * module, Varnish, or anything that purges by request.
 *
 * The endpoint template takes {scheme}, {host}, {path} and {query}. {query}
 * expands to "?a=b" when the URL has a query and to nothing otherwise. The
 * site's host is sent in the Host header. A 2xx answer is success; so is 404,
 * which purge modules send when the URL was not cached.
 */
final class HttpPurger implements Purger, Configurable, DeadlineAware
{
    public const METHODS = ['PURGE', 'GET'];

    /** @var callable(): float */
    private $clock;

    private ?float $deadline = null;

    /**
     * @param callable(): float|null $clock
     */
    public function __construct(
        private HttpClient $client,
        private ?string $endpoint,
        private string $method = 'PURGE',
        private int $timeout = 3,
        ?callable $clock = null
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    public function setDeadline(?float $deadline, ?callable $clock = null): void
    {
        $this->deadline = $deadline;
        if ($clock !== null) {
            $this->clock = $clock;
        }
    }

    public function configurationProblems(): array
    {
        $problems = [];
        if ($this->endpoint === null || $this->endpoint === '') {
            $problems[] = 'The http purger needs COMMENTS_THROUGH_CACHE_HTTP_ENDPOINT.';
        } elseif (preg_match('#^https?://#i', $this->endpoint) !== 1) {
            $problems[] = 'COMMENTS_THROUGH_CACHE_HTTP_ENDPOINT must start with http:// or https://.';
        }
        if (!in_array($this->method, self::METHODS, true)) {
            $problems[] = 'COMMENTS_THROUGH_CACHE_HTTP_METHOD must be PURGE or GET.';
        }
        return $problems;
    }

    public function purge(array $urls, string $reason = 'manual'): PurgeResult
    {
        $problems = $this->configurationProblems();
        if ($problems !== []) {
            throw new ConfigurationException(implode(' ', $problems));
        }
        $succeeded = [];
        $errors = [];
        foreach ($urls as $i => $url) {
            if ($this->deadline !== null && ($this->clock)() >= $this->deadline) {
                $errors[] = sprintf('purge budget exceeded; %d URLs not sent', count($urls) - $i);
                break;
            }
            $parts = parse_url($url);
            if (!is_array($parts) || !isset($parts['host'])) {
                $errors[] = 'skipped an unparsable URL';
                continue;
            }
            $target = self::expand((string) $this->endpoint, $parts);
            try {
                $response = $this->client->request($this->method, $target, ['Host' => $parts['host']], null, $this->timeoutLeft());
            } catch (PurgeException $e) {
                $errors[] = $e->getMessage();
                continue;
            }
            if ($response->isSuccess() || $response->status() === 404) {
                $succeeded[] = $url;
            } else {
                $errors[] = sprintf('HTTP %d from the purge endpoint', $response->status());
            }
        }
        return new PurgeResult($this->name(), $urls, $succeeded, $errors);
    }

    /**
     * @param array<string, int|string> $parts parse_url() output.
     */
    public static function expand(string $template, array $parts): string
    {
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';
        return strtr($template, [
            '{scheme}' => (string) ($parts['scheme'] ?? 'http'),
            '{host}' => (string) $parts['host'],
            '{path}' => isset($parts['path']) && $parts['path'] !== '' ? (string) $parts['path'] : '/',
            '{query}' => $query,
        ]);
    }

    /**
     * The per-request timeout, cut to what is left of the flush budget so a
     * hanging endpoint cannot hold the request long past the budget (F9).
     */
    private function timeoutLeft(): int
    {
        if ($this->deadline === null) {
            return $this->timeout;
        }
        $left = (int) ceil($this->deadline - ($this->clock)());
        return max(1, min($this->timeout, $left));
    }

    public function layer(): string
    {
        return self::LAYER_ORIGIN;
    }

    public function name(): string
    {
        return 'http';
    }
}
