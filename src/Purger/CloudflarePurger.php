<?php

declare(strict_types=1);

namespace IndianOpinion\CommentsThroughCache\Purger;

use IndianOpinion\CommentsThroughCache\ConfigurationException;
use IndianOpinion\CommentsThroughCache\Http\HttpClient;
use IndianOpinion\CommentsThroughCache\PurgeException;
use IndianOpinion\CommentsThroughCache\PurgeResult;

/**
 * Purges URLs from Cloudflare's cache through the purge-by-URL API.
 *
 * URLs go in batches (default 30 per request). Any answer that is not JSON
 * with "success": true counts as failure for that batch. The token is sent
 * only to the Cloudflare API over HTTPS and never logged.
 */
final class CloudflarePurger implements Purger, Configurable, DeadlineAware
{
    public const API = 'https://api.cloudflare.com/client/v4/zones/%s/purge_cache';
    public const BATCH_DEFAULT = 30;
    public const BATCH_MAX = 100;

    /** @var callable(): float */
    private $clock;

    private ?float $deadline = null;

    /**
     * @param callable(): float|null $clock
     */
    public function __construct(
        private HttpClient $client,
        private ?string $zoneId,
        #[\SensitiveParameter] private ?string $token,
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
        if ($this->zoneId === null || $this->zoneId === '') {
            $problems[] = 'The cloudflare purger needs COMMENTS_THROUGH_CACHE_CF_ZONE_ID.';
        } elseif (preg_match('/^[a-f0-9]{32}$/', $this->zoneId) !== 1) {
            $problems[] = 'COMMENTS_THROUGH_CACHE_CF_ZONE_ID must be a 32 character hexadecimal zone ID.';
        }
        if ($this->token === null || $this->token === '') {
            $problems[] = 'The cloudflare purger needs COMMENTS_THROUGH_CACHE_CF_API_TOKEN.';
        } elseif (preg_match('/^[A-Za-z0-9_\-.]+$/', $this->token) !== 1) {
            $problems[] = 'COMMENTS_THROUGH_CACHE_CF_API_TOKEN contains characters a token cannot have.';
        }
        return $problems;
    }

    /**
     * @return int<1, 100>
     */
    public function batchSize(): int
    {
        $size = apply_filters('comments_through_cache_cloudflare_batch_size', self::BATCH_DEFAULT);
        $size = is_numeric($size) ? (int) $size : self::BATCH_DEFAULT;
        return max(1, min(self::BATCH_MAX, $size));
    }

    public function purge(array $urls, string $reason = 'manual'): PurgeResult
    {
        $problems = $this->configurationProblems();
        if ($problems !== []) {
            throw new ConfigurationException(implode(' ', $problems));
        }
        $endpoint = sprintf(self::API, (string) $this->zoneId);
        $succeeded = [];
        $errors = [];
        $batches = array_chunk($urls, $this->batchSize());
        foreach ($batches as $i => $batch) {
            if ($this->deadline !== null && ($this->clock)() >= $this->deadline) {
                $left = 0;
                foreach (array_slice($batches, $i) as $rest) {
                    $left += count($rest);
                }
                $errors[] = sprintf('purge budget exceeded; %d URLs not sent', $left);
                break;
            }
            $body = (string) json_encode(['files' => $batch], JSON_UNESCAPED_SLASHES);
            try {
                $response = $this->client->request('POST', $endpoint, [
                    'Authorization' => 'Bearer ' . (string) $this->token,
                    'Content-Type' => 'application/json',
                ], $body, $this->timeout);
            } catch (PurgeException $e) {
                $errors[] = $e->getMessage();
                continue;
            }
            $decoded = json_decode($response->body(), true);
            if (is_array($decoded) && ($decoded['success'] ?? null) === true) {
                foreach ($batch as $url) {
                    $succeeded[] = $url;
                }
                continue;
            }
            $errors[] = sprintf('Cloudflare API answered HTTP %d without success%s', $response->status(), self::apiError($decoded));
        }
        return new PurgeResult($this->name(), $urls, $succeeded, $errors);
    }

    private static function apiError(mixed $decoded): string
    {
        if (!is_array($decoded) || !isset($decoded['errors'][0]) || !is_array($decoded['errors'][0])) {
            return '';
        }
        $first = $decoded['errors'][0];
        $code = isset($first['code']) && is_scalar($first['code']) ? (string) $first['code'] : '?';
        $message = isset($first['message']) && is_string($first['message']) ? substr($first['message'], 0, 120) : '';
        return sprintf(' (error %s: %s)', $code, $message);
    }

    public function layer(): string
    {
        return self::LAYER_EDGE;
    }

    public function name(): string
    {
        return 'cloudflare';
    }
}
