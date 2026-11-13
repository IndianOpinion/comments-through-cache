<?php

declare(strict_types=1);

namespace IndianOpinion\CommentsThroughCache\Purger;

use IndianOpinion\CommentsThroughCache\PurgeResult;

/**
 * Removes the given URLs from one cache layer.
 *
 * Implementations should report failure in the result rather than throw.
 * The queue catches anything thrown (F2), but a result says more.
 */
interface Purger
{
    public const LAYER_ORIGIN = 'origin';
    public const LAYER_EDGE = 'edge';

    /**
     * @param list<string> $urls   Absolute URLs on the site's host.
     * @param string       $reason One of the documented reason strings.
     */
    public function purge(array $urls, string $reason = 'manual'): PurgeResult;

    /**
     * "origin" or "edge". Origin purgers always run first (C30).
     */
    public function layer(): string;

    /**
     * A short stable name, such as "file" or "cloudflare".
     */
    public function name(): string;
}
