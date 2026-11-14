<?php

declare(strict_types=1);

namespace IndianOpinion\CommentsThroughCache;

use IndianOpinion\CommentsThroughCache\Purger\DeadlineAware;
use IndianOpinion\CommentsThroughCache\Purger\Purger;

/**
 * Collects purge requests during a request, keyed by post, and sends the
 * merged purge sets once at shutdown (C29). Origin purgers run before edge
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
     * The sets of all queued posts are merged per reason and deduplicated,
     * so each purger gets one call per reason however many posts were
     * moderated: bulk approving comments on twenty posts sends one batched
     * Cloudflare request, not twenty. Every origin purger finishes every
     * set before any edge purger starts (C30).
     *
     * @return list<PurgeResult> One result per purger per reason.
     */
    public function flush(): array
    {
        if ($this->queue === []) {
            return [];
        }
        $queue = $this->queue;
        $this->queue = [];

        $deadline = ($this->clock)() + $this->budgetSeconds;
        $groups = [];
        foreach ($queue as $postId => $reason) {
            foreach ($this->collector->forPost($postId, $reason) as $url) {
                $groups[$reason][$url] = true;
            }
        }
        $sets = [];
        foreach ($groups as $reason => $urls) {
            $sets[$reason] = array_map('strval', array_keys($urls));
        }
        $results = $this->runPurgers($sets, $this->activePurgers(), $deadline);
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
        $results = $this->runPurgers(
            $urls === [] ? [] : [$reason => $urls],
            $this->activePurgers(),
            ($this->clock)() + $this->budgetSeconds
        );
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
     * Runs each purger, in order, over each URL set. Purgers are the outer
     * loop so that every origin call ends before the first edge call.
     *
     * @param array<string, list<string>> $sets    Reason to URL list.
     * @param list<Purger>                $purgers
     *
     * @return list<PurgeResult>
     */
    private function runPurgers(array $sets, array $purgers, float $deadline): array
    {
        $results = [];
        foreach ($purgers as $purger) {
            foreach ($sets as $reason => $urls) {
                if ($urls === []) {
                    continue; // F8: nothing public changed.
                }
                $results[] = $this->runOne($purger, $urls, (string) $reason, $deadline);
            }
        }
        return $results;
    }

    /**
     * @param list<string> $urls
     */
    private function runOne(Purger $purger, array $urls, string $reason, float $deadline): PurgeResult
    {
        $name = $purger->name();
        if (($this->clock)() >= $deadline) {
            $this->logger->error(sprintf('Purge budget exceeded; %d URLs not sent to the %s purger.', count($urls), $name));
            return PurgeResult::failure($name, $urls, 'purge budget exceeded');
        }
        if ($purger instanceof DeadlineAware) {
            $purger->setDeadline($deadline, $this->clock);
        }
        try {
            $result = $purger->purge($urls, $reason);
        } catch (ConfigurationException $e) {
            $this->logger->errorOnce('config-' . $name, sprintf('The %s purger is misconfigured: %s', $name, $e->getMessage()));
            return PurgeResult::failure($name, $urls, 'misconfigured: ' . $e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('The %s purger failed: %s: %s', $name, get_class($e), $e->getMessage()));
            return PurgeResult::failure($name, $urls, get_class($e) . ': ' . $e->getMessage());
        }
        if (!$result->isSuccess()) {
            $this->logger->error('Purge incomplete. ' . $result->summary());
        } else {
            $this->logger->debug('Purged. ' . $result->summary());
        }
        return $result;
    }
}
