<?php

declare(strict_types=1);

namespace IndianOpinion\CommentsThroughCache\Tests\Unit;

use IndianOpinion\CommentsThroughCache\Config;
use IndianOpinion\CommentsThroughCache\ConfigurationException;
use IndianOpinion\CommentsThroughCache\CookieSetter;
use IndianOpinion\CommentsThroughCache\Plugin;
use IndianOpinion\CommentsThroughCache\Purger\CloudflarePurger;
use IndianOpinion\CommentsThroughCache\Purger\FileCachePurger;
use IndianOpinion\CommentsThroughCache\Purger\HttpPurger;
use IndianOpinion\CommentsThroughCache\SiteHealth;
use IndianOpinion\CommentsThroughCache\Tests\Fakes\FakeHttpClient;
use IndianOpinion\CommentsThroughCache\Tests\Fakes\FakeResponse;
use IndianOpinion\CommentsThroughCache\Tests\TestCase;

final class PluginTest extends TestCase
{
    /**
     * @param array<string, mixed> $config
     */
    private function plugin(array $config = []): Plugin
    {
        return new Plugin($this->config($config), $this->logger(), new FakeResponse(), new FakeHttpClient());
    }

    public function testRegisterAddsEveryHookOnceAndIsSafeTwice(): void
    {
        $plugin = $this->plugin();
        $plugin->register();
        $plugin->register();
        $expected = [
            'set_comment_cookies' => CookieSetter::PRIORITY,
            'send_headers' => 10,
            'comment_form_before' => 10,
            'wp_insert_comment' => 10,
            'transition_comment_status' => 10,
            'edit_comment' => 10,
            'shutdown' => 100,
            'site_status_tests' => 10,
        ];
        foreach ($expected as $hook => $priority) {
            $this->assertArrayHasKey($priority, \WpStub::$hooks[$hook], $hook);
        }
        $this->assertCount(2, \WpStub::$hooks['send_headers'][10]);
        $this->assertCount(1, \WpStub::$hooks['set_comment_cookies'][CookieSetter::PRIORITY]);
    }

    public function testEndToEndHeldCommentCookieThenApprovalPurge(): void
    {
        $response = new FakeResponse();
        $plugin = new Plugin($this->config(), $this->logger(), $response, new FakeHttpClient());
        $plugin->register();
        \WpStub::addPost(3);
        $comment = \WpStub::addComment(1, ['comment_post_ID' => '3', 'comment_approved' => '0']);

        do_action('wp_insert_comment', 1, $comment);
        do_action('set_comment_cookies', $comment, new \WP_User(0), false);
        $this->assertContains('comment_author_email_' . COOKIEHASH, $response->cookieNames());
        $this->assertTrue($plugin->queue()->isEmpty(), 'held: no purge');

        $comment->comment_approved = '1';
        do_action('transition_comment_status', 'approved', 'unapproved', $comment);
        do_action('shutdown');
        $this->assertCount(1, \WpStub::firings('comments_through_cache_purge'));
    }

    public function testPurgersAreBuiltFromConstants(): void
    {
        $plugin = $this->plugin(['PURGERS' => 'http,file,cloudflare,action']);
        $classes = array_map(static fn ($p): string => get_class($p), $plugin->purgers());
        $this->assertSame([HttpPurger::class, FileCachePurger::class, CloudflarePurger::class, \IndianOpinion\CommentsThroughCache\Purger\ActionPurger::class], $classes);
        $this->assertCount(4, $plugin->problems(), 'endpoint, path, zone and token missing');
    }

    public function testF3RecommendationWhenOnlyTheActionPurgerRunsUnheard(): void
    {
        $plugin = $this->plugin();
        $health = new SiteHealth($plugin->config(), static fn (): array => $plugin->queue()->activePurgers());
        $this->assertSame('recommended', $health->run()['status']);
        add_action('comments_through_cache_purge', static function (): void {
        });
        $this->assertSame('good', $health->run()['status']);
    }

    public function testT42SiteHealthCriticalWithoutToken(): void
    {
        $plugin = $this->plugin(['PURGERS' => 'cloudflare', 'CF_ZONE_ID' => str_repeat('a', 32), 'CF_API_TOKEN' => '']);
        $health = new SiteHealth($plugin->config(), static fn (): array => $plugin->queue()->activePurgers());
        $result = $health->run();
        $this->assertSame('critical', $result['status']);
        $this->assertStringContainsString('COMMENTS_THROUGH_CACHE_CF_API_TOKEN', $result['description']);
        $this->assertSame('comments_through_cache', $result['test']);

        $tests = $health->register(['direct' => []]);
        $this->assertIsCallable($tests['direct']['comments_through_cache']['test']);
    }

    public function testConfigDefaultsAndParsing(): void
    {
        $config = Config::fromArray([]);
        $this->assertSame(86400, $config->cookieLifetime());
        $this->assertSame('email', $config->cookieFields());
        $this->assertTrue($config->noticeEnabled());
        $this->assertSame(['action'], $config->purgerNames());
        $this->assertSame('PURGE', $config->httpMethod());
        $this->assertSame(['GET', 'HEAD'], $config->fileMethods());
        $this->assertSame(3, $config->httpTimeout());
        $this->assertSame(5, $config->purgeBudget());
        $this->assertFalse($config->debug());
        $this->assertSame([], $config->problems());

        $config = Config::fromArray(['DEBUG' => 'yes', 'NOTICE' => 'off', 'HTTP_TIMEOUT' => 999, 'PURGERS' => ' File , file,CLOUDFLARE ', 'FILE_METHODS' => 'get, head,GET']);
        $this->assertTrue($config->debug());
        $this->assertFalse($config->noticeEnabled());
        $this->assertSame(30, $config->httpTimeout());
        $this->assertSame(['file', 'cloudflare'], $config->purgerNames());
        $this->assertSame(['GET', 'HEAD'], $config->fileMethods());
    }

    public function testConfigRejectsWhatCannotBeClamped(): void
    {
        $config = Config::fromArray(['COOKIE_FIELDS' => 'name', 'COOKIE_LIFETIME' => 'a day']);
        $this->assertCount(2, $config->problems());
        $this->expectException(ConfigurationException::class);
        $config->cookieFields();
    }

    public function testUninstallRemovesNothing(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/uninstall.php');
        $this->assertStringContainsString("defined('WP_UNINSTALL_PLUGIN')", $source);
        $this->assertDoesNotMatchRegularExpression('/delete_option|\$wpdb|unlink\(/', $source);
    }

    public function testPluginHeader(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/comments-through-cache.php');
        $this->assertStringContainsString('Requires at least: 6.4', $source);
        $this->assertStringContainsString('Requires PHP:      8.1', $source);
        $this->assertStringContainsString('Version:           ' . Plugin::VERSION, $source);
    }
}
