<?php

declare(strict_types=1);

namespace IndianOpinion\CommentsThroughCache\Tests\Unit;

use IndianOpinion\CommentsThroughCache\Checker;
use IndianOpinion\CommentsThroughCache\Cli\Command;
use IndianOpinion\CommentsThroughCache\Cli\Output;
use IndianOpinion\CommentsThroughCache\Config;
use IndianOpinion\CommentsThroughCache\CookieSetter;
use IndianOpinion\CommentsThroughCache\Http\HttpResponse;
use IndianOpinion\CommentsThroughCache\Purger\CloudflarePurger;
use IndianOpinion\CommentsThroughCache\PurgeQueue;
use IndianOpinion\CommentsThroughCache\PurgeUrlCollector;
use IndianOpinion\CommentsThroughCache\Tests\Fakes\FakeHttpClient;
use IndianOpinion\CommentsThroughCache\Tests\Fakes\FakeResponse;
use IndianOpinion\CommentsThroughCache\Tests\TestCase;

/**
 * T47: no log line or config output contains an email address, a cookie
 * value or a token, whatever happens.
 */
final class PrivacyTest extends TestCase
{
    private const EMAIL = 'private.reader@example.org';
    private const TOKEN = 'TokenValue_ABCDEFGHIJ0123456789';

    public function testT47NothingPersonalOrSecretIsLoggedOrPrinted(): void
    {
        $values = [
            'DEBUG' => true,
            'PURGERS' => 'cloudflare,bogus',
            'CF_ZONE_ID' => str_repeat('b', 32),
            'CF_API_TOKEN' => self::TOKEN,
            'COOKIE_FIELDS' => 'invalid',
        ];
        $config = Config::fromArray($values);
        $logger = $this->logger(true);

        // Cookie path with clamping, a bad fields value, and headers sent.
        $comment = \WpStub::addComment(1, ['comment_post_ID' => '3', 'comment_approved' => '0', 'comment_author_email' => self::EMAIL]);
        add_filter('comments_through_cache_cookie_lifetime', static fn (): int => 1);
        (new CookieSetter($config, $logger, new FakeResponse()))->onSetCommentCookies($comment, new \WP_User(0), false);
        $sent = new FakeResponse();
        $sent->sent = true;
        (new CookieSetter($config, $logger, $sent))->onSetCommentCookies($comment, new \WP_User(0), false);

        // Purge path: the API fails and echoes its request back.
        \WpStub::addPost(3);
        $client = new FakeHttpClient(static fn (): HttpResponse => new HttpResponse(
            403,
            [],
            '{"success":false,"errors":[{"code":10000,"message":"Authentication error"}]}'
        ));
        $queue = new PurgeQueue(new PurgeUrlCollector($logger), static fn (): array => [new CloudflarePurger($client, str_repeat('b', 32), self::TOKEN)], $logger);
        $queue->add(3, 'manual');
        $queue->flush();
        $this->assertSame('Bearer ' . self::TOKEN, $client->requests[0]['headers']['Authorization'], 'the token does reach the API');

        // Config command.
        $lines = [];
        $output = new class ($lines) implements Output {
            /** @param list<string> $lines */
            public function __construct(private array &$lines)
            {
            }

            public function line(string $text): void
            {
                $this->lines[] = $text;
            }

            public function error(string $text): void
            {
                $this->lines[] = $text;
            }
        };
        (new Command($config, $queue, new Checker($client, COOKIEHASH), $output, static function (): void {
        }))->config();

        $everything = implode("\n", [...$this->logLines, ...$lines]);
        $this->assertNotSame('', $everything);
        $this->assertStringNotContainsString(self::EMAIL, $everything);
        $this->assertStringNotContainsString(self::TOKEN, $everything);
        $this->assertDoesNotMatchRegularExpression('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[a-z]{2,}/', $everything);
    }

    public function testConfigDescriptionMasksCredentialsInTheEndpoint(): void
    {
        $config = Config::fromArray(['HTTP_ENDPOINT' => 'http://purger:' . self::TOKEN . '@127.0.0.1:6081{path}']);
        $endpoint = $config->describe()['http_endpoint'];
        $this->assertSame('http://***@127.0.0.1:6081{path}', $endpoint);
        $this->assertSame('http://127.0.0.1/{path}', Config::fromArray(['HTTP_ENDPOINT' => 'http://127.0.0.1/{path}'])->describe()['http_endpoint']);
    }
}
