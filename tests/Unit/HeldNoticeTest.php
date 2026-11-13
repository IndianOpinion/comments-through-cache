<?php

declare(strict_types=1);

namespace IndianOpinion\CommentsThroughCache\Tests\Unit;

use IndianOpinion\CommentsThroughCache\HeldNotice;
use IndianOpinion\CommentsThroughCache\NoStoreHeaders;
use IndianOpinion\CommentsThroughCache\Tests\TestCase;

final class HeldNoticeTest extends TestCase
{
    private const DEFAULT = 'Thank you. Your comment has been received and will appear once it has been approved.';

    protected function setUp(): void
    {
        parent::setUp();
        \WpStub::$theId = 3;
        \WpStub::addPost(3);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function notice(array $config = []): HeldNotice
    {
        $notice = new HeldNotice($this->config($config));
        $notice->activate();
        return $notice;
    }

    private function capture(callable $fn): string
    {
        ob_start();
        $fn();
        return (string) ob_get_clean();
    }

    private function redirectTo(int $commentId, string $status = '0', int $post = 3): void
    {
        \WpStub::addComment($commentId, ['comment_post_ID' => (string) $post, 'comment_approved' => $status]);
        $_GET['unapproved'] = (string) $commentId;
        $_GET['moderation-hash'] = 'valid';
        \WpStub::$unapprovedEmail = 'reader@example.org';
    }

    public function testT19ValidRedirectPrintsNoticeOnce(): void
    {
        $this->redirectTo(9);
        $notice = $this->notice();
        $html = $this->capture(static function () use ($notice): void {
            $notice->onCommentFormBefore();
            $notice->onCommentFormBefore();
        });
        $this->assertSame('<p class="comments-through-cache-held-notice" role="status">' . self::DEFAULT . '</p>', $html);
    }

    public function testT20CookieOnlyHeldCommentPrintsNotice(): void
    {
        \WpStub::addComment(9, ['comment_post_ID' => '3', 'comment_approved' => '0']);
        \WpStub::$commenter['comment_author_email'] = 'reader@example.org';
        $this->assertStringContainsString('role="status"', $this->notice()->html());
        $this->assertCount(1, \WpStub::$commentQueries, 'one query');
    }

    public function testT20FilterTurnsCookieNoticeOff(): void
    {
        \WpStub::addComment(9, ['comment_post_ID' => '3', 'comment_approved' => '0']);
        \WpStub::$commenter['comment_author_email'] = 'reader@example.org';
        $contexts = [];
        add_filter('comments_through_cache_show_held_notice', static function (bool $show, int $post, string $context) use (&$contexts): bool {
            $contexts[] = $context;
            return $context !== 'cookie';
        }, 10, 3);
        $this->assertSame('', $this->notice()->html());
        $this->assertSame(['cookie'], $contexts);
    }

    public function testNoticeConstantTurnsItOff(): void
    {
        $this->redirectTo(9);
        $this->assertSame('', $this->notice(['NOTICE' => false])->html());
    }

    public function testT21NoNoticeForApprovedSpamMissingOrOtherPost(): void
    {
        foreach (['1', 'spam', 'trash'] as $status) {
            \WpStub::reset();
            \WpStub::$theId = 3;
            $this->redirectTo(9, $status);
            $this->assertSame('', $this->notice()->html(), $status);
        }
        \WpStub::reset();
        \WpStub::$theId = 3;
        $_GET = ['unapproved' => '404', 'moderation-hash' => 'x'];
        $this->assertSame('', $this->notice()->html(), 'missing');

        \WpStub::reset();
        \WpStub::$theId = 3;
        $this->redirectTo(9, '0', 8);
        $this->assertSame('', $this->notice()->html(), 'other post');
    }

    public function testT22BadHashWithoutCookieShowsNothing(): void
    {
        $this->redirectTo(9);
        \WpStub::$unapprovedEmail = ''; // Core rejected the hash or the window passed.
        $this->assertSame('', $this->notice()->html());
    }

    public function testT22HashForAnotherAuthorShowsNothing(): void
    {
        $this->redirectTo(9);
        \WpStub::$unapprovedEmail = 'someone-else@example.org';
        $this->assertSame('', $this->notice()->html());
    }

    public function testT23TextFilterIsEscapedAndEmptySuppresses(): void
    {
        $this->redirectTo(9);
        add_filter('comments_through_cache_held_notice_text', static fn (): string => 'Held <b>for</b> review & checks');
        $this->assertSame(
            '<p class="comments-through-cache-held-notice" role="status">Held &lt;b&gt;for&lt;/b&gt; review &amp; checks</p>',
            $this->notice()->html()
        );

        \WpStub::reset();
        \WpStub::$theId = 3;
        $this->redirectTo(9);
        add_filter('comments_through_cache_held_notice_text', static fn (): string => '');
        $this->assertSame('', $this->notice()->html());
    }

    public function testT24TemplateFunctionPrintsAndFormActionThenStaysQuiet(): void
    {
        $this->redirectTo(9);
        $notice = $this->notice();
        $html = $this->capture(static function () use ($notice): void {
            comments_through_cache_held_notice();
            $notice->onCommentFormBefore();
        });
        $this->assertSame(1, substr_count($html, 'role="status"'));
    }

    public function testT24TemplateCalledWithNothingToShowStillSuppressesFormAction(): void
    {
        $notice = $this->notice();
        $this->assertSame('', $this->capture(static fn () => comments_through_cache_held_notice()));
        $this->redirectTo(9);
        $this->assertSame('', $this->capture(static fn () => $notice->onCommentFormBefore()));
    }

    public function testT25NoticeOnlyOnBypassRequests(): void
    {
        // Held comment exists, but the request has neither the redirect
        // arguments nor the cookie: a cacheable request gets no notice.
        \WpStub::addComment(9, ['comment_post_ID' => '3', 'comment_approved' => '0']);
        $notice = $this->notice();
        $this->assertSame('', $notice->html());
        $this->assertFalse(NoStoreHeaders::isRedirectRequest());
        $this->assertFalse(NoStoreHeaders::hasBypassCookie());

        // Each trigger is itself a bypass condition.
        $_COOKIE['comment_author_email_' . COOKIEHASH] = 'reader@example.org';
        \WpStub::$commenter['comment_author_email'] = 'reader@example.org';
        $this->assertNotSame('', $notice->html());
        $this->assertTrue(NoStoreHeaders::hasBypassCookie());

        \WpStub::$commenter['comment_author_email'] = '';
        $_COOKIE = [];
        $this->redirectTo(9);
        $this->assertNotSame('', $notice->html());
        $this->assertTrue(NoStoreHeaders::isRedirectRequest());
    }

    public function testNoPostContextShowsNothing(): void
    {
        \WpStub::$theId = 0;
        $this->redirectTo(9);
        $this->assertSame('', $this->notice()->html());
    }
}
