<?php

declare(strict_types=1);

namespace IndianOpinion\CommentsThroughCache;

use IndianOpinion\CommentsThroughCache\Http\HttpClient;
use IndianOpinion\CommentsThroughCache\Http\HttpResponse;

/**
 * Checks that the cache layers in front of a URL honour the bypass rules
 * (C31). Needs debug mode on the site, so that every PHP render carries a
 * unique render ID: a cached response repeats an ID, a live render does not.
 */
final class Checker
{
    /** A fixed placeholder, never a real address. */
    public const DUMMY_EMAIL = 'cache-check@example.invalid';

    public function __construct(private HttpClient $client, private string $cookieHash, private int $timeout = 10)
    {
    }

    public function check(string $url): CheckReport
    {
        $report = new CheckReport();
        if (preg_match('#^https?://#i', $url) !== 1) {
            $report->add(CheckReport::FAIL, 'url', 'Give an absolute http:// or https:// URL.');
            return $report;
        }

        try {
            $first = $this->get($url);
        } catch (PurgeException $e) {
            $report->add(CheckReport::FAIL, 'fetch', 'Could not fetch the URL: ' . $e->getMessage());
            return $report;
        }
        if (self::renderId($first) === null) {
            $report->add(
                CheckReport::FAIL,
                'debug',
                'No ' . NoStoreHeaders::RENDER_HEADER . ' header. Turn on COMMENTS_THROUGH_CACHE_DEBUG on the site, purge the URL, and run the check again.'
            );
            return $report;
        }

        try {
            $seen = [self::renderId($first)];
            $this->checkPlain($report, $url, $seen);
            $this->checkCookie($report, $url);
            $this->checkRedirect($report, $url);
            $this->checkSetCookie($report, $url, $seen);
        } catch (PurgeException $e) {
            $report->add(CheckReport::FAIL, 'fetch', 'A request failed: ' . $e->getMessage());
        }
        return $report;
    }

    /**
     * @param list<string|null> $seen
     */
    private function checkPlain(CheckReport $report, string $url, array &$seen): void
    {
        $second = $this->get($url);
        $id = self::renderId($second);
        $hit = $id !== null && in_array($id, $seen, true);
        $seen[] = $id;
        $report->add(
            CheckReport::INFO,
            'cache',
            $hit ? 'The second plain request was a cache hit.' : 'The second plain request was a live render (no cache, or not cached yet).'
        );
    }

    private function checkCookie(CheckReport $report, string $url): void
    {
        $headers = ['Cookie' => 'comment_author_email_' . $this->cookieHash . '=' . rawurlencode(self::DUMMY_EMAIL)];
        $a = self::renderId($this->get($url, $headers));
        $b = self::renderId($this->get($url, $headers));
        if ($a === null || $b === null) {
            $report->add(CheckReport::FAIL, 'cookie', 'A request with the commenter cookie came back without a render ID, so it was served from a cache.');
        } elseif ($a === $b) {
            $report->add(CheckReport::FAIL, 'cookie', 'Requests with the commenter cookie repeat a render ID: the cache does not bypass on comment_author_ cookies (C13).');
        } else {
            $report->add(CheckReport::PASS, 'cookie', 'Requests with the commenter cookie bypass the cache.');
        }
    }

    private function checkRedirect(CheckReport $report, string $url): void
    {
        $target = $url . (str_contains($url, '?') ? '&' : '?') . 'unapproved=0&moderation-hash=x';
        $r1 = $this->get($target);
        $r2 = $this->get($target);
        $a = self::renderId($r1);
        $b = self::renderId($r2);
        $problems = [];
        if ($a === null || $b === null || $a === $b) {
            $problems[] = 'the redirect URL was served from a cache (C14)';
        }
        if (!self::hasNoStore($r1) || !self::hasNoStore($r2)) {
            $problems[] = 'the response lacks Cache-Control: no-store (C17)';
        }
        if ($problems === []) {
            $report->add(CheckReport::PASS, 'redirect', 'The redirect URL bypasses the cache and is marked no-store.');
        } else {
            $report->add(CheckReport::FAIL, 'redirect', ucfirst(implode('; ', $problems)) . '.');
        }
    }

    /**
     * @param list<string|null> $seen
     */
    private function checkSetCookie(CheckReport $report, string $url, array &$seen): void
    {
        $leaks = 0;
        for ($i = 0; $i < 2; $i++) {
            $response = $this->get($url);
            $id = self::renderId($response);
            $hit = $id !== null && in_array($id, $seen, true);
            $seen[] = $id;
            if ($hit && $response->headerValues('set-cookie') !== []) {
                $leaks++;
            }
        }
        if ($leaks > 0) {
            $report->add(CheckReport::FAIL, 'set-cookie', 'A cache hit carried Set-Cookie: the cache stores responses that set cookies (C15).');
        } else {
            $report->add(CheckReport::PASS, 'set-cookie', 'No cache hit carried Set-Cookie.');
        }
    }

    /**
     * @param array<string, string> $headers
     */
    private function get(string $url, array $headers = []): HttpResponse
    {
        return $this->client->request('GET', $url, $headers, null, $this->timeout);
    }

    private static function renderId(HttpResponse $response): ?string
    {
        $id = $response->header(NoStoreHeaders::RENDER_HEADER);
        return $id === null || $id === '' ? null : $id;
    }

    private static function hasNoStore(HttpResponse $response): bool
    {
        foreach ($response->headerValues('cache-control') as $value) {
            if (stripos($value, 'no-store') !== false) {
                return true;
            }
        }
        return false;
    }
}
