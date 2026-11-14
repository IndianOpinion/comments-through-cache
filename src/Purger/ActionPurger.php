<?php

declare(strict_types=1);

namespace IndianOpinion\CommentsThroughCache\Purger;

use IndianOpinion\CommentsThroughCache\PurgeResult;

/**
 * Fires the comments_through_cache_purge action with the URL list, so a cache
 * plugin or site code can do the purge. The default purger.
 */
final class ActionPurger implements Purger
{
    public const ACTION = 'comments_through_cache_purge';

    public function purge(array $urls, string $reason = 'manual'): PurgeResult
    {
        do_action(self::ACTION, $urls, $reason);
        return new PurgeResult($this->name(), $urls, $urls);
    }

    public function layer(): string
    {
        return self::LAYER_ORIGIN;
    }

    public function name(): string
    {
        return 'action';
    }

    /**
     * True when something listens to the purge action.
     */
    public static function hasListener(): bool
    {
        return (bool) has_action(self::ACTION);
    }
}
