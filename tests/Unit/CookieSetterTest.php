<?php

declare(strict_types=1);

namespace IndianOpinion\CommentsThroughCache\Tests\Unit;

use IndianOpinion\CommentsThroughCache\CookieSetter;
use IndianOpinion\CommentsThroughCache\ModerationListener;
use IndianOpinion\CommentsThroughCache\PurgeQueue;
use IndianOpinion\CommentsThroughCache\PurgeUrlCollector;
use IndianOpinion\CommentsThroughCache\Tests\Fakes\FakeResponse;
use IndianOpinion\CommentsThroughCache\Tests\TestCase;

final class CookieSetterTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private FakeResponse $response;

    protected function setUp(): void
    {
        parent::setUp();
        $this->response = new FakeResponse();
    }

    /**
     * @param array<string, mixed> $config
     */
    private function setter(array $config = [], bool $debug = false): CookieSetter
    {
        return new CookieSetter($this->config($config), $this->logger($debug), $this->response, static fn (): int => self::NOW);
    }

    private function comment(string $approved = '0'): \WP_Comment
    {
        return \WpStub::addComment(7, [
            'comment_post_ID' => '3',
            'comment_approved' => $approved,
            'comment_author_url' => 'https://reader.example.org/',
        ]);
    }

    /**
     * Mimics core: without consent it sends three expiring cookies at
     * priority 10. Ours must follow.
     */
    private function coreExpiringCookies(): void
    {
        add_action('set_comment_cookies', function (): void {
            foreach (['comment_author_', 'comment_author_email_', 'comment_author_url_'] as $prefix) {
                $this->response->setCookie($prefix . COOKIEHASH, ' ', [
                    'expires' => self::NOW - YEAR_IN_SECONDS,
                    'path' => COOKIEPATH,
                    'domain' => COOKIE_DOMAIN,
                    'secure' => true,
                    'httponly' => false,
                ]);
            }
        }, 10, 3);
    }

    private function register(CookieSetter $setter): void
    {
        add_action('set_comment_cookies', [$setter, 'onSetCommentCookies'], CookieSetter::PRIORITY, 3);
    }

    public function testT1HeldCommentWithoutConsentSetsEmailCookieAfterCore(): void
    {
        $this->coreExpiringCookies();
        $this->register($this->setter());
        do_action('set_comment_cookies', $this->comment('0'), new \WP_User(0), false);

        $emailName = 'comment_author_email_' . COOKIEHASH;
        $positions = array_keys(array_filter($this->response->cookieNames(), static fn (string $n): bool => $n === $emailName));
        $this->assertCount(2, $positions, 'core expiry then ours');
        $ours = $this->response->cookies[$positions[1]];
        $this->assertSame('reader@example.org', $ours['value']);
        $this->assertSame(self::NOW + 86400, $ours['options']['expires']);
        $this->assertSame(COOKIEPATH, $ours['options']['path']);
        $this->assertSame(COOKIE_DOMAIN, $ours['options']['domain']);
        $this->assertGreaterThan($positions[0], $positions[1]);
        $this->assertSame([[7, ['email'], 86400]], \WpStub::firings('comments_through_cache_cookie_set'));
    }

    public function testT2ApprovedOnArrivalSetsCookieAndQueuesPurge(): void
    {
        $queue = new PurgeQueue(new PurgeUrlCollector($this->logger()), static fn (): array => [], $this->logger());
        $listener = new ModerationListener($queue);
        $comment = $this->comment('1');
        $listener->onInsert(7, $comment);
        $this->setter()->onSetCommentCookies($comment, new \WP_User(0), false);

        $this->assertContains('comment_author_email_' . COOKIEHASH, $this->response->cookieNames());
        $this->assertSame([3 => 'comment_approved_on_post'], $queue->pending());
    }

    public function testT3ConsentGivenSetsNothing(): void
    {
        $this->setter()->onSetCommentCookies($this->comment(), new \WP_User(0), true);
        $this->assertSame([], $this->response->cookies);
    }

    public function testConsentGivenExpiresAnEarlierMarker(): void
    {
        $_COOKIE[CookieSetter::markerName()] = (self::NOW - 50) . '.e';
        $this->setter()->onSetCommentCookies($this->comment(), new \WP_User(0), true);
        $this->assertSame([CookieSetter::markerName()], $this->response->cookieNames());
        $this->assertLessThan(self::NOW, $this->response->cookies[0]['options']['expires']);
    }

    public function testT4LoggedInUserGetsNothing(): void
    {
        $this->setter()->onSetCommentCookies($this->comment(), new \WP_User(5), false);
        $this->assertSame([], $this->response->cookies);
    }

    public function testT5SpamAndTrashGetNoCookie(): void
    {
        foreach (['spam', 'trash', 'post-trashed'] as $status) {
            $this->setter()->onSetCommentCookies($this->comment($status), new \WP_User(0), false);
        }
        $this->assertSame([], $this->response->cookies);
    }

    public function testT6LifetimeIsClampedAndLoggedInDebug(): void
    {
        add_filter('comments_through_cache_cookie_lifetime', static fn (): int => 10);
        $this->setter([], true)->onSetCommentCookies($this->comment(), new \WP_User(0), false);
        $this->assertSame(self::NOW + 300, $this->response->cookies[0]['options']['expires']);
        $this->assertCount(1, $this->logLines);
        $this->assertStringContainsString('clamped to 300', $this->logLines[0]);

        \WpStub::reset();
        $this->response = new FakeResponse();
        $this->logLines = [];
        add_filter('comments_through_cache_cookie_lifetime', static fn (): int => 99_999_999);
        $this->setter([], true)->onSetCommentCookies($this->comment(), new \WP_User(0), false);
        $this->assertSame(self::NOW + 2_592_000, $this->response->cookies[0]['options']['expires']);
        $this->assertStringContainsString('clamped to 2592000', $this->logLines[0]);
    }

    public function testLifetimeFromConstantIsUsed(): void
    {
        $this->setter(['COOKIE_LIFETIME' => 3600])->onSetCommentCookies($this->comment(), new \WP_User(0), false);
        $this->assertSame(self::NOW + 3600, $this->response->cookies[0]['options']['expires']);
    }

    public function testT7EmailSetsOneCookieAllSetsThree(): void
    {
        $this->setter()->onSetCommentCookies($this->comment(), new \WP_User(0), false);
        $this->assertSame(['comment_author_email_' . COOKIEHASH, CookieSetter::markerName()], $this->response->cookieNames());

        $this->response = new FakeResponse();
        $this->setter(['COOKIE_FIELDS' => 'all'])->onSetCommentCookies($this->comment(), new \WP_User(0), false);
        $this->assertSame([
            'comment_author_' . COOKIEHASH,
            'comment_author_email_' . COOKIEHASH,
            'comment_author_url_' . COOKIEHASH,
            CookieSetter::markerName(),
        ], $this->response->cookieNames());
    }

    public function testFieldsFilterOverridesConstantAndInvalidFallsBackToEmail(): void
    {
        add_filter('comments_through_cache_cookie_fields', static fn (): string => 'everything');
        $this->setter(['COOKIE_FIELDS' => 'all'])->onSetCommentCookies($this->comment(), new \WP_User(0), false);
        $this->assertSame(['comment_author_email_' . COOKIEHASH, CookieSetter::markerName()], $this->response->cookieNames());
        $this->assertCount(1, $this->logLines);
    }

    public function testT8MissingCommentDoesNothing(): void
    {
        $this->setter()->onSetCommentCookies(null, new \WP_User(0), false);
        $this->setter()->onSetCommentCookies('7', null, false);
        $this->assertSame([], $this->response->cookies);
    }

    public function testT9HeadersSentLogsOnceAndSetsNothing(): void
    {
        $this->response->sent = true;
        $setter = $this->setter([], true);
        $setter->onSetCommentCookies($this->comment(), new \WP_User(0), false);
        $setter->onSetCommentCookies($this->comment(), new \WP_User(0), false);
        $this->assertSame([], $this->response->cookies);
        $this->assertCount(1, $this->logLines);
    }

    public function testT10RejectedSubmissionNeverFiresSoNothingHappens(): void
    {
        $this->register($this->setter());
        // Core stops before set_comment_cookies on duplicate, flood or closed
        // comments, so the action is never fired here.
        $this->assertSame([], $this->response->cookies);
        $this->assertSame([], \WpStub::$fired);
    }

    public function testT11FilterCanVeto(): void
    {
        $seen = [];
        add_filter('comments_through_cache_should_set_cookie', static function (bool $set, $comment, $user, $consent) use (&$seen): bool {
            $seen = [$set, $comment instanceof \WP_Comment, $user instanceof \WP_User, $consent];
            return false;
        }, 10, 4);
        $this->setter()->onSetCommentCookies($this->comment(), new \WP_User(0), false);
        $this->assertSame([], $this->response->cookies);
        $this->assertSame([true, true, true, false], $seen);
    }

    public function testT12SecureFlagFollowsHomeScheme(): void
    {
        $this->setter()->onSetCommentCookies($this->comment(), new \WP_User(0), false);
        $this->assertTrue($this->response->cookies[0]['options']['secure']);

        $this->response = new FakeResponse();
        \WpStub::$home = 'http://example.com';
        $this->setter()->onSetCommentCookies($this->comment(), new \WP_User(0), false);
        $this->assertFalse($this->response->cookies[0]['options']['secure']);
    }

    public function testCommentWithoutEmailSetsNoCookie(): void
    {
        $comment = $this->comment();
        $comment->comment_author_email = '';
        $this->setter()->onSetCommentCookies($comment, new \WP_User(0), false);
        $this->assertSame([], $this->response->cookies);
        $this->assertSame([], \WpStub::firings('comments_through_cache_cookie_set'));
    }

    public function testMarkerIsHttpOnlyAndCarriesNoPersonalData(): void
    {
        $this->setter()->onSetCommentCookies($this->comment(), new \WP_User(0), false);
        $marker = $this->response->cookies[1];
        $this->assertSame(CookieSetter::markerName(), $marker['name']);
        $this->assertSame(self::NOW . '.e', $marker['value']);
        $this->assertTrue($marker['options']['httponly']);
        $this->assertFalse($this->response->cookies[0]['options']['httponly'], 'core cookies stay readable by themes');
    }

    // Open question 3: early expiry.

    private function withMarker(int $age, string $fields = 'e'): void
    {
        $_COOKIE[CookieSetter::markerName()] = (self::NOW - $age) . '.' . $fields;
        $_COOKIE['comment_author_email_' . COOKIEHASH] = 'reader@example.org';
        \WpStub::$commenter['comment_author_email'] = 'reader@example.org';
    }

    public function testEarlyExpiryClearsCookieWhenNoHeldCommentsLeft(): void
    {
        $this->withMarker(3600);
        $this->comment('1');
        $this->setter()->maybeExpireEarly();
        $this->assertSame(['comment_author_email_' . COOKIEHASH, CookieSetter::markerName()], $this->response->cookieNames());
        foreach ($this->response->cookies as $cookie) {
            $this->assertLessThan(self::NOW, $cookie['options']['expires']);
        }
        $this->assertSame([[['email']]], \WpStub::firings('comments_through_cache_cookie_cleared'));
        $this->assertSame('private, no-store, max-age=0', $this->response->headers['Cache-Control'] ?? null, 'a response that expires cookies is never stored');
        $this->assertSame('0', $this->response->headers['X-Accel-Expires'] ?? null);
    }

    public function testEarlyExpiryThatKeepsTheCookieSendsNoCacheHeaders(): void
    {
        $this->withMarker(3600);
        $this->comment('0');
        $this->setter()->maybeExpireEarly();
        $this->assertSame([], $this->response->headers);
    }

    public function testEarlyExpiryClearsAllThreeWhenTheyWereSet(): void
    {
        $this->withMarker(3600, 'a');
        $this->setter()->maybeExpireEarly();
        $this->assertCount(4, $this->response->cookies);
    }

    public function testEarlyExpiryKeepsCookieWhileACommentIsHeld(): void
    {
        $this->withMarker(3600);
        $this->comment('0');
        $this->setter()->maybeExpireEarly();
        $this->assertSame([], $this->response->cookies);
    }

    public function testEarlyExpiryWaitsForTheGracePeriod(): void
    {
        $this->withMarker(60);
        $this->setter()->maybeExpireEarly();
        $this->assertSame([], $this->response->cookies);
        $this->assertSame([], \WpStub::$commentQueries, 'no query inside the grace period');
    }

    public function testEarlyExpiryNeverTouchesConsentedCookies(): void
    {
        // No marker: the cookies are core's, set by the reader's consent.
        $_COOKIE['comment_author_email_' . COOKIEHASH] = 'reader@example.org';
        \WpStub::$commenter['comment_author_email'] = 'reader@example.org';
        $this->setter()->maybeExpireEarly();
        $this->assertSame([], $this->response->cookies);
        $this->assertSame([], \WpStub::$commentQueries);
    }

    public function testEarlyExpiryCanBeTurnedOff(): void
    {
        $this->withMarker(3600);
        $this->setter(['EARLY_EXPIRY' => false])->maybeExpireEarly();
        $this->assertSame([], $this->response->cookies);
    }

    public function testEarlyExpiryIgnoresMalformedMarker(): void
    {
        $this->withMarker(3600);
        $_COOKIE[CookieSetter::markerName()] = 'reader@example.org';
        $this->setter()->maybeExpireEarly();
        $this->assertSame([], $this->response->cookies);
    }
}
