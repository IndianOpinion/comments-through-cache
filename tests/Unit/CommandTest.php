<?php

declare(strict_types=1);

namespace IndianOpinion\CommentsThroughCache\Tests\Unit;

use IndianOpinion\CommentsThroughCache\Checker;
use IndianOpinion\CommentsThroughCache\Cli\Command;
use IndianOpinion\CommentsThroughCache\Cli\Output;
use IndianOpinion\CommentsThroughCache\Purger\Purger;
use IndianOpinion\CommentsThroughCache\PurgeQueue;
use IndianOpinion\CommentsThroughCache\PurgeUrlCollector;
use IndianOpinion\CommentsThroughCache\Tests\Fakes\FakeCachedSite;
use IndianOpinion\CommentsThroughCache\Tests\Fakes\FakeHttpClient;
use IndianOpinion\CommentsThroughCache\Tests\Fakes\RecordingPurger;
use IndianOpinion\CommentsThroughCache\Tests\TestCase;

final class CommandTest extends TestCase
{
    /** @var list<string> */
    private array $out = [];

    private ?int $exit = null;

    /**
     * @param list<Purger>         $purgers
     * @param array<string, mixed> $config
     */
    private function command(array $purgers = [], array $config = [], ?FakeHttpClient $client = null): Command
    {
        $output = new class ($this->out) implements Output {
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
                $this->lines[] = 'Warning: ' . $text;
            }
        };
        $queue = new PurgeQueue(new PurgeUrlCollector($this->logger()), static fn (): array => $purgers, $this->logger());
        return new Command(
            $this->config($config),
            $queue,
            new Checker($client ?? (new FakeCachedSite())->client(), COOKIEHASH),
            $output,
            function (int $code): void {
                $this->exit = $code;
            }
        );
    }

    public function testT38PurgeCallsEveryPurgerAndPrintsEachResult(): void
    {
        \WpStub::addPost(5);
        $this->command([
            new RecordingPurger('cloudflare', Purger::LAYER_EDGE),
            new RecordingPurger('file'),
            new RecordingPurger('http', Purger::LAYER_ORIGIN, 'fail'),
        ])->purge(['5']);

        $this->assertSame(['file', 'http', 'cloudflare'], array_column(RecordingPurger::$log, 'purger'));
        $this->assertSame(['manual', 'manual', 'manual'], array_column(RecordingPurger::$log, 'reason'));
        $this->assertSame([
            'OK   file: 2 of 2 URLs purged',
            'FAIL http: 0 of 2 URLs purged; HTTP 500 from the purge endpoint',
            'OK   cloudflare: 2 of 2 URLs purged',
        ], $this->out);
        $this->assertSame(1, $this->exit);
    }

    public function testPurgeRejectsBadIdsAndReportsNonPublicPosts(): void
    {
        $this->command()->purge(['abc']);
        $this->assertSame(1, $this->exit);
        $this->command()->purge(['77']);
        $this->assertSame(0, $this->exit);
        $this->assertSame('Nothing to purge: the post is missing or not public.', end($this->out));
    }

    public function testCheckPrintsLinesAndExitCode(): void
    {
        $this->command()->check(['https://example.com/hello/']);
        $this->assertCount(4, $this->out);
        $this->assertSame(0, $this->exit);
    }

    public function testConfigMasksTheToken(): void
    {
        $this->command([], ['CF_API_TOKEN' => 'super-secret-token-123', 'PURGERS' => 'cloudflare,nonsense'])->config();
        $joined = implode("\n", $this->out);
        $this->assertStringNotContainsString('super-secret-token-123', $joined);
        $this->assertStringContainsString('cf_api_token     set', $joined);
        $this->assertStringContainsString('purgers          cloudflare', $joined);
        $this->assertStringContainsString('Warning: Unknown purger "nonsense"', $joined);
    }
}
