<?php

declare(strict_types=1);

namespace IndianOpinion\CommentsThroughCache\Tests\Unit;

use IndianOpinion\CommentsThroughCache\Http\HttpResponse;
use IndianOpinion\CommentsThroughCache\ModerationListener;
use IndianOpinion\CommentsThroughCache\Plugin;
use IndianOpinion\CommentsThroughCache\Purger\ActionPurger;
use IndianOpinion\CommentsThroughCache\Purger\CloudflarePurger;
use IndianOpinion\CommentsThroughCache\Purger\HttpPurger;
use IndianOpinion\CommentsThroughCache\Purger\Purger;
use IndianOpinion\CommentsThroughCache\PurgeException;
use IndianOpinion\CommentsThroughCache\PurgeQueue;
use IndianOpinion\CommentsThroughCache\PurgeResult;
use IndianOpinion\CommentsThroughCache\PurgeUrlCollector;
use IndianOpinion\CommentsThroughCache\Tests\Fakes\FakeHttpClient;
use IndianOpinion\CommentsThroughCache\Tests\Fakes\FakeResponse;
use IndianOpinion\CommentsThroughCache\Tests\Fakes\RecordingPurger;
use IndianOpinion\CommentsThroughCache\Tests\TestCase;

final class PurgeQueueTest extends TestCase
{
    private float $now = 1000.0;

    protected function setUp(): void
    {
        parent::setUp();
        \WpStub::addPost(1);
        \WpStub::addPost(2);
        \WpStub::addPost(3);
    }

    /**
     * @param list<Purger> $purgers
     */
    private function queue(array $purgers, int $budget = 5): PurgeQueue
    {
        return new PurgeQueue(
            new PurgeUrlCollector($this->logger()),
            static fn (): array => $purgers,
            $this->logger(),
            $budget,
            fn (): float => $this->now
        );
    }

    public function testT35FiftyApprovalsOnThreePostsSendThreeSetsOnceAtShutdown(): void
    {
        $queue = $this->queue([new RecordingPurger('origin')]);
        $listener = new ModerationListener($queue);
        for ($i = 1; $i <= 50; $i++) {
            $comment = \WpStub::addComment($i, ['comment_post_ID' => (string) (($i % 3) + 1), 'comment_approved' => '1']);
            $listener->onTransition('approved', 'unapproved', $comment);
        }
        $this->assertSame([], RecordingPurger::$log, 'nothing is sent before the flush');

        $results = $queue->flush();
        $this->assertCount(3, RecordingPurger::$log);
        $this->assertCount(3, $results);
        $posts = array_map(static fn (array $e): string => $e['urls'][0], RecordingPurger::$log);
        sort($posts);
        $this->assertSame(['https://example.com/post-1/', 'https://example.com/post-2/', 'https://example.com/post-3/'], $posts);
        $this->assertCount(1, \WpStub::firings('comments_through_cache_purged'));

        $this->assertSame([], $queue->flush(), 'a second flush sends nothing');
        $this->assertCount(3, RecordingPurger::$log);
    }

    public function testShutdownHandlerFlushes(): void
    {
        $plugin = new Plugin($this->config(), $this->logger(), new FakeResponse(), new FakeHttpClient());
        add_filter('comments_through_cache_purgers', static fn (): array => [new RecordingPurger('origin')]);
        $plugin->queue()->add(1, 'manual');
        $plugin->onShutdown();
        $this->assertCount(1, RecordingPurger::$log);
        $plugin->onShutdown();
        $this->assertCount(1, RecordingPurger::$log);
    }

    public function testT36OriginRunsBeforeEdgeWhateverTheConfiguredOrder(): void
    {
        $queue = $this->queue([
            new RecordingPurger('cloudflare', Purger::LAYER_EDGE),
            new RecordingPurger('file', Purger::LAYER_ORIGIN),
            new RecordingPurger('http', Purger::LAYER_ORIGIN),
        ]);
        $queue->add(1, 'manual');
        $queue->flush();
        $this->assertSame(['file', 'http', 'cloudflare'], array_column(RecordingPurger::$log, 'purger'));
    }

    public function testT36ConfigConstantOrderIsSortedByLayer(): void
    {
        $plugin = new Plugin(
            $this->config(['PURGERS' => 'cloudflare, file', 'FILE_PATH' => sys_get_temp_dir(), 'CF_ZONE_ID' => str_repeat('a', 32), 'CF_API_TOKEN' => 't']),
            $this->logger(),
            new FakeResponse(),
            new FakeHttpClient()
        );
        $names = array_map(static fn (Purger $p): string => $p->name(), $plugin->queue()->activePurgers());
        $this->assertSame(['file', 'cloudflare'], $names);
    }

    public function testT39HttpErrorAndTimeoutAreLoggedAndNextPurgerRuns(): void
    {
        $calls = 0;
        $client = new FakeHttpClient(static function () use (&$calls): HttpResponse {
            $calls++;
            if ($calls === 1) {
                return new HttpResponse(500);
            }
            throw new PurgeException('HTTP request failed: Operation timed out');
        });
        $queue = $this->queue([
            new HttpPurger($client, 'http://127.0.0.1:8080/purge{path}{query}'),
            new RecordingPurger('next'),
        ]);
        $queue->add(1, 'manual');
        $results = $queue->flush();

        $this->assertFalse($results[0]->isSuccess());
        $this->assertSame(['HTTP 500 from the purge endpoint', 'HTTP request failed: Operation timed out'], $results[0]->errors());
        $this->assertTrue($results[1]->isSuccess());
        $this->assertSame(['next'], array_column(RecordingPurger::$log, 'purger'));
        $this->assertCount(1, $this->logLines);
        $this->assertStringStartsWith('[comments-through-cache] Purge incomplete. http: 0 of 2', $this->logLines[0]);
    }

    public function testT40ThrowingPurgerIsCaughtAndNextRuns(): void
    {
        $queue = $this->queue([new RecordingPurger('bad', Purger::LAYER_ORIGIN, 'throw'), new RecordingPurger('good')]);
        $queue->add(1, 'manual');
        $results = $queue->flush();
        $this->assertSame(['bad', 'good'], array_column(RecordingPurger::$log, 'purger'));
        $this->assertFalse($results[0]->isSuccess());
        $this->assertTrue($results[1]->isSuccess());
        $this->assertStringContainsString('The bad purger failed: RuntimeException: boom', $this->logLines[0]);
    }

    public function testT41NoConfigurationFiresThePurgeActionWithTheUrlList(): void
    {
        $plugin = new Plugin($this->config(), $this->logger(), new FakeResponse(), new FakeHttpClient());
        $purgers = $plugin->queue()->activePurgers();
        $this->assertCount(1, $purgers);
        $this->assertInstanceOf(ActionPurger::class, $purgers[0]);

        $plugin->queue()->add(2, 'status_to_approved');
        $plugin->queue()->flush();
        $this->assertSame(
            [[['https://example.com/post-2/', 'https://example.com/post-2/feed/'], 'status_to_approved']],
            \WpStub::firings('comments_through_cache_purge')
        );
    }

    public function testT42MissingTokenIsCaughtLoggedOnceAndOthersRun(): void
    {
        $cloudflare = new CloudflarePurger(new FakeHttpClient(), str_repeat('a', 32), null);
        $queue = $this->queue([$cloudflare, new RecordingPurger('file')]);
        $queue->add(1, 'manual');
        $queue->add(2, 'manual');
        $results = $queue->flush();

        $this->assertSame(['file', 'file'], array_column(RecordingPurger::$log, 'purger'));
        $this->assertCount(4, $results);
        $this->assertFalse($results[1]->isSuccess());
        $this->assertStringContainsString('misconfigured', $results[1]->errors()[0]);
        $this->assertCount(1, $this->logLines, 'logged once per request');
        $this->assertStringContainsString('The cloudflare purger is misconfigured', $this->logLines[0]);
    }

    public function testT43FlushStopsAtBudgetAndLogsTheRest(): void
    {
        $slow = new RecordingPurger('slow');
        $slow->onPurge = function (): void {
            $this->now += 6.0;
        };
        $queue = $this->queue([$slow, new RecordingPurger('after')], 5);
        $queue->add(1, 'manual');
        $queue->add(2, 'manual');
        $results = $queue->flush();

        $this->assertSame(['slow'], array_column(RecordingPurger::$log, 'purger'));
        $this->assertCount(4, $results);
        $this->assertSame(['purge budget exceeded'], $results[1]->errors());
        $this->assertSame(3, count(array_filter($this->logLines, static fn (string $l): bool => str_contains($l, 'Purge budget exceeded'))));
    }

    public function testT43DeadlineAwarePurgerStopsMidList(): void
    {
        $client = new FakeHttpClient(function (): HttpResponse {
            $this->now += 2.0;
            return new HttpResponse(200);
        });
        $http = new HttpPurger($client, 'http://127.0.0.1/purge{path}');
        \WpStub::$options['page_comments'] = 1;
        \WpStub::$options['comments_per_page'] = 1;
        for ($i = 1; $i <= 5; $i++) {
            \WpStub::addComment($i, ['comment_post_ID' => '1', 'comment_approved' => '1']);
        }
        $queue = $this->queue([$http], 5);
        $queue->add(1, 'manual');
        $results = $queue->flush();
        $this->assertCount(3, $client->requests);
        $this->assertStringContainsString('purge budget exceeded; 5 URLs not sent', $results[0]->errors()[0]);
    }

    public function testEmptyPurgeSetSendsNothing(): void
    {
        \WpStub::addPost(9, ['post_status' => 'draft']);
        $queue = $this->queue([new RecordingPurger('origin')]);
        $queue->add(9, 'manual');
        $this->assertSame([], $queue->flush());
        $this->assertSame([], RecordingPurger::$log);
    }

    public function testRulingEightAFilterDisablesOnePurger(): void
    {
        add_filter('comments_through_cache_purger_enabled', static fn (bool $on, string $name): bool => $name !== 'action', 10, 2);
        $queue = $this->queue([new ActionPurger(), new RecordingPurger('file')]);
        $queue->add(1, 'manual');
        $queue->flush();
        $this->assertSame([], \WpStub::firings('comments_through_cache_purge'));
        $this->assertSame(['file'], array_column(RecordingPurger::$log, 'purger'));
    }

    public function testPurgedActionCarriesEveryResult(): void
    {
        $queue = $this->queue([new RecordingPurger('a'), new RecordingPurger('b', Purger::LAYER_EDGE, 'fail')]);
        $queue->add(1, 'manual');
        $queue->flush();
        $fired = \WpStub::firings('comments_through_cache_purged');
        $this->assertCount(1, $fired);
        $this->assertContainsOnlyInstancesOf(PurgeResult::class, $fired[0][0]);
        $this->assertCount(2, $fired[0][0]);
    }

    public function testFirstReasonIsKeptPerPost(): void
    {
        $queue = $this->queue([]);
        $queue->add(1, 'status_to_approved');
        $queue->add(1, 'approved_comment_edited');
        $queue->add(0, 'manual');
        $this->assertSame([1 => 'status_to_approved'], $queue->pending());
    }
}
