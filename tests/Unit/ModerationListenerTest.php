<?php

declare(strict_types=1);

namespace IndianOpinion\CommentsThroughCache\Tests\Unit;

use IndianOpinion\CommentsThroughCache\ModerationListener;
use IndianOpinion\CommentsThroughCache\PurgeQueue;
use IndianOpinion\CommentsThroughCache\PurgeUrlCollector;
use IndianOpinion\CommentsThroughCache\Tests\TestCase;

final class ModerationListenerTest extends TestCase
{
    private PurgeQueue $queue;

    private ModerationListener $listener;

    protected function setUp(): void
    {
        parent::setUp();
        $this->queue = new PurgeQueue(new PurgeUrlCollector($this->logger()), static fn (): array => [], $this->logger());
        $this->listener = new ModerationListener($this->queue);
    }

    private function comment(string $status, int $post = 3, int $parent = 0): \WP_Comment
    {
        return \WpStub::addComment(11, ['comment_post_ID' => (string) $post, 'comment_approved' => $status, 'comment_parent' => (string) $parent]);
    }

    public function testT28PostedApprovedQueuesOnePurge(): void
    {
        $this->listener->onInsert(11, $this->comment('1'));
        $this->assertSame([3 => 'comment_approved_on_post'], $this->queue->pending());
    }

    public function testT29PostedHeldSpamTrashQueueNothing(): void
    {
        foreach (['0', 'spam', 'trash'] as $status) {
            $this->listener->onInsert(11, $this->comment($status));
        }
        $this->assertTrue($this->queue->isEmpty());
    }

    public function testT30ToApprovedQueues(): void
    {
        foreach (['unapproved', 'spam', 'trash'] as $old) {
            $queue = new PurgeQueue(new PurgeUrlCollector($this->logger()), static fn (): array => [], $this->logger());
            (new ModerationListener($queue))->onTransition('approved', $old, $this->comment('1'));
            $this->assertSame([3 => 'status_to_approved'], $queue->pending(), $old);
        }
    }

    public function testT31FromApprovedQueues(): void
    {
        foreach (['unapproved', 'spam', 'trash', 'delete'] as $new) {
            $queue = new PurgeQueue(new PurgeUrlCollector($this->logger()), static fn (): array => [], $this->logger());
            (new ModerationListener($queue))->onTransition($new, 'approved', $this->comment('0'));
            $this->assertSame([3 => 'status_from_approved'], $queue->pending(), $new);
        }
    }

    public function testRawStatusValuesAreUnderstood(): void
    {
        $this->listener->onTransition('delete', '1', $this->comment('1'));
        $this->assertSame([3 => 'status_from_approved'], $this->queue->pending());
    }

    public function testT32NoPurgeWhenNeitherSideIsApproved(): void
    {
        $this->listener->onTransition('approved', 'approved', $this->comment('1'));
        $this->listener->onTransition('spam', 'unapproved', $this->comment('spam'));
        $this->listener->onTransition('trash', 'spam', $this->comment('trash'));
        $this->listener->onTransition('unapproved', '0', $this->comment('0'));
        $this->assertTrue($this->queue->isEmpty());
    }

    public function testT33EditedApprovedPurgesEditedHeldDoesNot(): void
    {
        $this->comment('0');
        $this->listener->onEdit(11);
        $this->assertTrue($this->queue->isEmpty());

        $this->comment('1');
        $this->listener->onEdit(11);
        $this->assertSame([3 => 'approved_comment_edited'], $this->queue->pending());
    }

    public function testT34ApprovedReplyPurgesItsPost(): void
    {
        $this->listener->onTransition('approved', 'unapproved', $this->comment('1', 3, 4));
        $this->assertSame([3 => 'status_to_approved'], $this->queue->pending());
    }

    public function testShouldPurgeFilterOverrides(): void
    {
        $seen = [];
        add_filter('comments_through_cache_should_purge', static function (bool $purge, $c, string $new, string $old) use (&$seen): bool {
            $seen = [$purge, $new, $old];
            return false;
        }, 10, 4);
        $this->listener->onTransition('approved', 'unapproved', $this->comment('1'));
        $this->assertTrue($this->queue->isEmpty());
        $this->assertSame([true, 'approved', 'unapproved'], $seen);
    }

    public function testInvalidArgumentsAreIgnored(): void
    {
        $this->listener->onInsert(1, null);
        $this->listener->onTransition('approved', 'unapproved', null);
        $this->listener->onEdit(404);
        $this->assertTrue($this->queue->isEmpty());
    }
}
