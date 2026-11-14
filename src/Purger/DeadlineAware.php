<?php

declare(strict_types=1);

namespace IndianOpinion\CommentsThroughCache\Purger;

/**
 * A purger that sends several requests and can stop when the flush budget
 * runs out (F9).
 */
interface DeadlineAware
{
    /**
     * @param float|null             $deadline Clock value after which no new request starts.
     * @param callable(): float|null $clock    The clock the deadline is measured on.
     */
    public function setDeadline(?float $deadline, ?callable $clock = null): void;
}
