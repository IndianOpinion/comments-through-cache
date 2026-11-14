<?php

declare(strict_types=1);

namespace IndianOpinion\CommentsThroughCache\Tests;

use IndianOpinion\CommentsThroughCache\Config;
use IndianOpinion\CommentsThroughCache\Logger;
use IndianOpinion\CommentsThroughCache\Tests\Fakes\RecordingPurger;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    /** @var list<string> */
    protected array $logLines = [];

    protected function setUp(): void
    {
        parent::setUp();
        \WpStub::reset();
        \WP_CLI::$lines = [];
        \WP_CLI::$commands = [];
        RecordingPurger::$log = [];
        $this->logLines = [];
    }

    protected function tearDown(): void
    {
        // T48: no test may cause a database write.
        $this->assertSame([], \WpStub::$dbWrites, 'The plugin wrote to the database.');
        parent::tearDown();
    }

    protected function logger(bool $debug = false): Logger
    {
        return new Logger($debug, function (string $line): void {
            $this->logLines[] = $line;
        });
    }

    /**
     * @param array<string, mixed> $values
     */
    protected function config(array $values = []): Config
    {
        return Config::fromArray($values);
    }
}
