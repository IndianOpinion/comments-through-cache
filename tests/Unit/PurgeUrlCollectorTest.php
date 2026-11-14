<?php

declare(strict_types=1);

namespace IndianOpinion\CommentsThroughCache\Tests\Unit;

use IndianOpinion\CommentsThroughCache\PurgeUrlCollector;
use IndianOpinion\CommentsThroughCache\Tests\TestCase;

final class PurgeUrlCollectorTest extends TestCase
{
    private function collector(bool $debug = false): PurgeUrlCollector
    {
        return new PurgeUrlCollector($this->logger($debug));
    }

    public function testT26PermalinkAndCommentsFeed(): void
    {
        \WpStub::addPost(3);
        $this->assertSame(
            ['https://example.com/post-3/', 'https://example.com/post-3/feed/'],
            $this->collector()->forPost(3)
        );
    }

    public function testT26CommentPagesUpToCountPlusOne(): void
    {
        \WpStub::addPost(3);
        \WpStub::$options['page_comments'] = 1;
        \WpStub::$options['comments_per_page'] = 2;
        for ($i = 1; $i <= 3; $i++) {
            \WpStub::addComment($i, ['comment_post_ID' => '3', 'comment_approved' => '1']);
        }
        \WpStub::addComment(4, ['comment_post_ID' => '3', 'comment_approved' => '1', 'comment_parent' => '1']);
        $urls = $this->collector()->forPost(3);
        $this->assertSame([
            'https://example.com/post-3/',
            'https://example.com/post-3/comment-page-1/',
            'https://example.com/post-3/comment-page-2/',
            'https://example.com/post-3/comment-page-3/',
            'https://example.com/post-3/feed/',
        ], $urls, 'three top-level comments at two per page: two pages, plus one');
        $this->assertSame(0, \WpStub::$commentQueries[0]['parent']);
    }

    public function testT26CommentPagesCappedByFilter(): void
    {
        \WpStub::addPost(3);
        \WpStub::$options['page_comments'] = 1;
        \WpStub::$options['comments_per_page'] = 1;
        for ($i = 1; $i <= 30; $i++) {
            \WpStub::addComment($i, ['comment_post_ID' => '3', 'comment_approved' => '1']);
        }
        $this->assertCount(1 + 10 + 1, $this->collector()->forPost(3), 'default cap of 10');
        add_filter('comments_through_cache_max_comment_pages', static fn (): int => 2);
        $this->assertCount(1 + 2 + 1, $this->collector()->forPost(3));
    }

    public function testT26PlainPermalinksUseCpage(): void
    {
        \WpStub::$options['permalink_structure'] = '';
        \WpStub::$options['page_comments'] = 1;
        \WpStub::addPost(3);
        $this->assertSame([
            'https://example.com/?p=3',
            'https://example.com/?p=3&cpage=1',
            'https://example.com/?feed=comments-rss2&p=3',
        ], $this->collector()->forPost(3));
    }

    public function testT26FilterAdditionsDedupedFragmentsStrippedForeignDropped(): void
    {
        \WpStub::addPost(3);
        $args = [];
        add_filter('comments_through_cache_purge_urls', static function (array $urls, int $post, string $reason) use (&$args): array {
            $args = [$post, $reason];
            return [...$urls,
                '/post-3/amp/',
                'https://example.com/post-3/#comments',
                'https://EXAMPLE.com/post-3/text/',
                'https://other.example.net/post-3/',
                '//example.com/post-3/print/',
                'javascript:alert(1)',
                42,
            ];
        }, 10, 3);
        $urls = $this->collector(true)->forPost(3, 'status_to_approved');
        $this->assertSame([
            'https://example.com/post-3/',
            'https://example.com/post-3/feed/',
            'https://example.com/post-3/amp/',
            'https://EXAMPLE.com/post-3/text/',
            'https://example.com/post-3/print/',
        ], $urls);
        $this->assertSame([3, 'status_to_approved'], $args);
        $this->assertCount(2, $this->logLines, 'foreign host and bad scheme logged in debug');
    }

    public function testAllowedHostsFilterAddsATwin(): void
    {
        \WpStub::addPost(3);
        add_filter('comments_through_cache_allowed_hosts', static fn (array $h): array => [...$h, 'www.example.com']);
        add_filter('comments_through_cache_purge_urls', static fn (array $u): array => [...$u, 'https://www.example.com/post-3/']);
        $this->assertContains('https://www.example.com/post-3/', $this->collector()->forPost(3));
    }

    public function testT27NonPublicPostsHaveEmptySets(): void
    {
        \WpStub::addPost(1, ['post_status' => 'draft']);
        \WpStub::addPost(2, ['post_status' => 'private']);
        \WpStub::addPost(3, ['post_status' => 'future']);
        \WpStub::addPost(4, ['post_password' => 'secret']);
        \WpStub::addPost(5, ['post_type' => 'internal']);
        \WpStub::addPost(6, ['post_status' => 'trash']);
        \WpStub::$privateTypes['internal'] = 'internal';
        foreach ([1, 2, 3, 4, 5, 6, 999] as $id) {
            $this->assertSame([], $this->collector()->forPost($id), (string) $id);
        }
    }
}
