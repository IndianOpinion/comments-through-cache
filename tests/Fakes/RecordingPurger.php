<?php

declare(strict_types=1);

namespace IndianOpinion\CommentsThroughCache\Tests\Fakes;

use IndianOpinion\CommentsThroughCache\Purger\Purger;
use IndianOpinion\CommentsThroughCache\PurgeResult;

/**
 * Records every call into a shared log. Can fail, throw or advance a clock.
 */
final class RecordingPurger implements Purger
{
    /** @var list<array{purger: string, urls: list<string>, reason: string}> */
    public static array $log = [];

    /** @var callable(): void|null */
    public $onPurge = null;

    public function __construct(
        private string $name,
        private string $layer = Purger::LAYER_ORIGIN,
        private string $mode = 'ok'
    ) {
    }

    public function purge(array $urls, string $reason = 'manual'): PurgeResult
    {
        self::$log[] = ['purger' => $this->name, 'urls' => $urls, 'reason' => $reason];
        if ($this->onPurge !== null) {
            ($this->onPurge)();
        }
        if ($this->mode === 'throw') {
            throw new \RuntimeException('boom');
        }
        if ($this->mode === 'fail') {
            return new PurgeResult($this->name, $urls, [], ['HTTP 500 from the purge endpoint']);
        }
        return new PurgeResult($this->name, $urls, $urls);
    }

    public function layer(): string
    {
        return $this->layer;
    }

    public function name(): string
    {
        return $this->name;
    }
}
