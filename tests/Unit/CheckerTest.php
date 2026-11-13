<?php

declare(strict_types=1);

namespace IndianOpinion\CommentsThroughCache\Tests\Unit;

use IndianOpinion\CommentsThroughCache\Checker;
use IndianOpinion\CommentsThroughCache\CheckReport;
use IndianOpinion\CommentsThroughCache\Http\HttpResponse;
use IndianOpinion\CommentsThroughCache\PurgeException;
use IndianOpinion\CommentsThroughCache\Tests\Fakes\FakeCachedSite;
use IndianOpinion\CommentsThroughCache\Tests\Fakes\FakeHttpClient;
use IndianOpinion\CommentsThroughCache\Tests\TestCase;

final class CheckerTest extends TestCase
{
    private const URL = 'https://example.com/hello/';

    private function check(FakeCachedSite $site): CheckReport
    {
        return (new Checker($site->client(), COOKIEHASH))->check(self::URL);
    }

    /**
     * @return array<string, string> Check name to status.
     */
    private function statuses(CheckReport $report): array
    {
        $out = [];
        foreach ($report->lines() as $line) {
            $out[$line['check']] = $line['status'];
        }
        return $out;
    }

    public function testT37AllPass(): void
    {
        $report = $this->check(new FakeCachedSite());
        $this->assertSame(0, $report->exitCode());
        $this->assertSame(['cache' => 'info', 'cookie' => 'pass', 'redirect' => 'pass', 'set-cookie' => 'pass'], $this->statuses($report));
        $this->assertStringContainsString('cache hit', $report->lines()[0]['message']);
        $this->assertCount(4, $report->formatted());
        $this->assertStringStartsWith('PASS cookie', $report->formatted()[1]);
    }

    public function testT13CacheIgnoringTheCookieFails(): void
    {
        $site = new FakeCachedSite();
        $site->ignoreCookie = true;
        $report = $this->check($site);
        $this->assertSame(1, $report->exitCode());
        $this->assertSame(['cache' => 'info', 'cookie' => 'fail', 'redirect' => 'pass', 'set-cookie' => 'pass'], $this->statuses($report));
    }

    public function testT14CacheStoringTheRedirectFails(): void
    {
        $site = new FakeCachedSite();
        $site->storeRedirect = true;
        $report = $this->check($site);
        $this->assertSame(1, $report->exitCode());
        $this->assertSame('fail', $this->statuses($report)['redirect']);
        $this->assertStringContainsString('C14', $report->lines()[2]['message']);
    }

    public function testT14MissingNoStoreFails(): void
    {
        $site = new FakeCachedSite();
        $site->sendNoStore = false;
        $report = $this->check($site);
        $this->assertSame('fail', $this->statuses($report)['redirect']);
        $this->assertStringContainsString('C17', $report->lines()[2]['message']);
        $this->assertSame('pass', $this->statuses($report)['cookie']);
    }

    public function testT15CacheHitWithSetCookieIsReported(): void
    {
        $site = new FakeCachedSite();
        $site->setCookieOnPlain = true;
        $site->storeSetCookie = true;
        $report = $this->check($site);
        $this->assertSame(1, $report->exitCode());
        $this->assertSame('fail', $this->statuses($report)['set-cookie']);
    }

    public function testSetCookieOnLiveRendersIsFine(): void
    {
        $site = new FakeCachedSite();
        $site->setCookieOnPlain = true; // The cache refuses to store them.
        $this->assertSame(0, $this->check($site)->exitCode());
    }

    public function testT37DebugOffRefusesWithAClearMessage(): void
    {
        $site = new FakeCachedSite();
        $site->debug = false;
        $report = $this->check($site);
        $this->assertSame(1, $report->exitCode());
        $this->assertCount(1, $report->lines());
        $this->assertSame('debug', $report->lines()[0]['check']);
        $this->assertStringContainsString('COMMENTS_THROUGH_CACHE_DEBUG', $report->lines()[0]['message']);
    }

    public function testNoCacheAtAllPasses(): void
    {
        $n = 0;
        $client = new FakeHttpClient(static function (string $m, string $url) use (&$n): HttpResponse {
            $n++;
            $headers = ['X-Comments-Through-Cache-Render' => 'r' . $n];
            if (str_contains($url, 'unapproved=')) {
                $headers['Cache-Control'] = 'private, no-store, max-age=0';
            }
            return new HttpResponse(200, $headers);
        });
        $report = (new Checker($client, COOKIEHASH))->check(self::URL);
        $this->assertSame(0, $report->exitCode());
        $this->assertStringContainsString('live render', $report->lines()[0]['message']);
    }

    public function testDummyCookieIsAPlaceholderNotARealAddress(): void
    {
        $site = new FakeCachedSite();
        $client = $site->client();
        (new Checker($client, COOKIEHASH))->check(self::URL);
        $cookies = array_values(array_filter(array_map(static fn (array $r): string => $r['headers']['Cookie'] ?? '', $client->requests)));
        $this->assertSame('comment_author_email_' . COOKIEHASH . '=cache-check%40example.invalid', $cookies[0]);
        $redirects = array_filter($client->requests, static fn (array $r): bool => str_contains($r['url'], 'unapproved=0&moderation-hash=x'));
        $this->assertCount(2, $redirects);
    }

    public function testFetchErrorsAndBadUrlsFail(): void
    {
        $client = new FakeHttpClient(static function (): HttpResponse {
            throw new PurgeException('HTTP request failed: could not resolve host');
        });
        $report = (new Checker($client, COOKIEHASH))->check(self::URL);
        $this->assertSame(1, $report->exitCode());
        $this->assertSame('fetch', $report->lines()[0]['check']);

        $report = (new Checker(new FakeHttpClient(), COOKIEHASH))->check('example.com/hello');
        $this->assertSame('url', $report->lines()[0]['check']);
        $this->assertSame(1, $report->exitCode());
    }
}
