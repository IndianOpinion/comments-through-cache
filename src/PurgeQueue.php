<?php

declare(strict_types=1);

namespace IndianOpinion\CommentsThroughCache;

use IndianOpinion\CommentsThroughCache\Purger\DeadlineAware;
use IndianOpinion\CommentsThroughCache\Purger\Purger;

/**
 * Collects purge requests during a request, keyed by post, and sends each
 * post's purge set once at shutdown (C29). Origin purgers run before edge
 * purgers (C30). Nothing thrown by a purger escapes (F1, F2, F4), and the
 * whole flush is time-boxed (F9).
 */
final class PurgeQueue
{
    /** @var array<int, string> Post ID to the first reason queued for it. */
    private array $queue = [];

    /** @var callable(): list<Purger> */
    private $purgers;

    /** @var callable(): float */
    private $clock;

    /**
     * @param callable(): list<Purger> $purgers Returns the active purgers.
     * @param callable(): float|null   $clock
     */
    public function __construct(
        private PurgeUrlCollector $collector,
        callable $purgers,
        private Logger $logger,
        private int $budgetSeconds = 5,
        ?callable $clock = null
    ) {
        $this->purgers = $purgers;
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    public function add(int $postId, string $reason): void
    {
        if ($postId <= 0) {
            return;
        }
        if (!isset($this->queue[$postId])) {
            $this->queue[$postId] = $reason;
        }
    }

    public function isEmpty(): bool
    {
        return $this->queue === [];
    }

    /**
     * @return array<int, string> Queued post IDs and reasons.
     */
    public function pending(): array
    {
        return $this->queue;
    }

    /**
     * Sends every queued purge set once and empties the queue.
     *
     * @return list<PurgeResult>
     */
    public function flush(): array
    {
        if ($this->queue === []) {
            return [];
        }
        $queue = $this->queue;
        $this->queue = [];

        $deadline = ($this->clock)() + $this->budgetSeconds;
        $purgers = $this->activePurgers();
        $results = [];
        foreach ($queue as $postId => $reason) {
            $urls = $this->collector->forPost($postId, $reason);
            foreach ($this->purgeSet($urls, $reason, $purgers, $deadline) as $result) {
                $results[] = $result;
            }
        }
        do_action('comments_through_cache_purged', $results);
        return $results;
    }

    /**
     * Purges one post now, outside the queue (the purge command, C32).
     *
     * @return list<PurgeResult>
     */
    public function purgePost(int $postId, string $reason = 'manual'): array
    {
        $urls = $this->collector->forPost($postId, $reason);
        $results = $this->purgeSet($urls, $reason, $this->activePurgers(), ($this->clock)() + $this->budgetSeconds);
        do_action('comments_through_cache_purged', $results);
        return $results;
    }

    /**
     * The purgers that will run, origin layer first, configured order kept
     * within a layer, and any purger a site disabled through the filter
     * removed.
     *
     * @return list<Purger>
     */
    public function activePurgers(): array
    {
        $origin = [];
        $edge = [];
        foreach (($this->purgers)() as $purger) {
            if (!$purger instanceof Purger) {
                continue;
            }
            if (!(bool) apply_filters('comments_through_cache_purger_enabled', true, $purger->name(), $purger)) {
                continue;
            }
            if ($purger->layer() === Purger::LAYER_EDGE) {
                $edge[] = $purger;
            } else {
                $origin[] = $purger;
            }
        }
        return array_merge($origin, $edge);
    }

    /**
     * @param list<string> $urls
     * @param list<Purger> $purgers
     *
     * @return list<PurgeResult>
     */
    private function purgeSet(array $urls, string $reason, array $purgers, float $deadline): array
    {
        if ($urls === []) {
            return []; // F8: nothing public changed.
        }
        $results = [];
        foreach ($purgers as $purger) {
            $name = $purger->name();
            if (($this->clock)() >= $deadline) {
                $this->logger->error(sprintf('Purge budget exceeded; %d URLs not sent to the %s purger.', count($urls), $name));
                $results[] = PurgeResult::failure($name, $urls, 'purge budget exceeded');
                continue;
            }
            if ($purger instanceof DeadlineAware) {
                $purger->setDeadline($deadline, $this->clock);
            }
            try {
                $result = $purger->purge($urls, $reason);
            } catch (ConfigurationException $e) {
                $this->logger->errorOnce('config-' . $name, sprintf('The %s purger is misconfigured: %s', $name, $e->getMessage()));
                $results[] = PurgeResult::failure($name, $urls, 'misconfigured: ' . $e->getMessage());
                continue;
            } catch (\Throwable $e) {
                $this->logger->error(sprintf('The %s purger failed: %s: %s', $name, get_class($e), $e->getMessage()));
                $results[] = PurgeResult::failure($name, $urls, get_class($e) . ': ' . $e->getMessage());
                continue;
            }
            if (!$result->isSuccess()) {
                $this->logger->error('Purge incomplete. ' . $result->summary());
            } else {
                $this->logger->debug('Purged. ' . $result->summary());
            }
            $results[] = $result;
        }
        return $results;
    }
}
