<?php

declare(strict_types=1);

namespace IndianOpinion\CommentsThroughCache\Tests\Unit;

use IndianOpinion\CommentsThroughCache\NoStoreHeaders;
use IndianOpinion\CommentsThroughCache\Tests\Fakes\FakeResponse;
use IndianOpinion\CommentsThroughCache\Tests\TestCase;

final class NoStoreHeadersTest extends TestCase
{
    private FakeResponse $response;

    protected function setUp(): void
    {
        parent::setUp();
        $this->response = new FakeResponse();
    }

    /**
     * @param array<string, mixed> $config
     */
    private function send(array $config = []): void
    {
        (new NoStoreHeaders($this->config($config), $this->response, static fn (): string => 'render-1'))->onSendHeaders();
    }

    public function testT17UnapprovedArgumentSendsBothHeaders(): void
    {
        $_GET['unapproved'] = '12';
        $this->send();
        $this->assertSame('private, no-store, max-age=0', $this->response->headers['Cache-Control']);
        $this->assertSame('0', $this->response->headers['X-Accel-Expires']);
    }

    public function testT17ModerationHashAloneSendsBothHeaders(): void
    {
        $_GET['moderation-hash'] = 'abc';
        $this->send();
        $this->assertArrayHasKey('Cache-Control', $this->response->headers);
        $this->assertArrayHasKey('X-Accel-Expires', $this->response->headers);
    }

    public function testT17InvalidValuesStillBypass(): void
    {
        // Ruling 4: bypass on the argument regardless of the hash's validity.
        $_GET['unapproved'] = 'not-a-number';
        $_GET['moderation-hash'] = '';
        $this->send();
        $this->assertArrayHasKey('Cache-Control', $this->response->headers);
    }

    public function testT17FilterAddsAName(): void
    {
        add_filter('comments_through_cache_no_store_args', static fn (array $a): array => [...$a, 'preview-comment']);
        $_GET['preview-comment'] = '1';
        $this->send();
        $this->assertSame('private, no-store, max-age=0', $this->response->headers['Cache-Control']);
    }

    public function testT18PlainRequestSendsNoCacheHeaders(): void
    {
        $_GET['p'] = '3';
        $_COOKIE['comment_author_email_' . COOKIEHASH] = 'reader@example.org';
        $this->send();
        $this->assertSame([], $this->response->headers);
    }

    public function testDebugSendsRenderIdOnEveryRender(): void
    {
        $this->send(['DEBUG' => true]);
        $this->assertSame(['X-Comments-Through-Cache-Render' => 'render-1'], $this->response->headers);
    }

    public function testDebugMarksBypassReason(): void
    {
        $_COOKIE['comment_author_email_' . COOKIEHASH] = 'x';
        $this->send(['DEBUG' => true]);
        $this->assertSame('cookie', $this->response->headers['X-Comments-Through-Cache']);

        $this->response = new FakeResponse();
        $_GET['unapproved'] = '1';
        $this->send(['DEBUG' => true]);
        $this->assertSame('redirect', $this->response->headers['X-Comments-Through-Cache']);
    }

    public function testDefaultRenderIdsAreUnique(): void
    {
        $a = new FakeResponse();
        $b = new FakeResponse();
        (new NoStoreHeaders($this->config(['DEBUG' => '1']), $a))->onSendHeaders();
        (new NoStoreHeaders($this->config(['DEBUG' => '1']), $b))->onSendHeaders();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{16}$/', $a->headers['X-Comments-Through-Cache-Render']);
        $this->assertNotSame($a->headers['X-Comments-Through-Cache-Render'], $b->headers['X-Comments-Through-Cache-Render']);
    }

    public function testHeadersAlreadySentDoesNothing(): void
    {
        $_GET['unapproved'] = '1';
        $this->response->sent = true;
        $this->send(['DEBUG' => true]);
        $this->assertSame([], $this->response->headers);
    }
}
