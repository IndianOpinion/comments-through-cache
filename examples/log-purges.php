<?php

/**
 * Plugin Name: Log purge results (example)
 *
 * Writes one line per purger per post to the PHP error log.
 */

use IndianOpinion\CommentsThroughCache\PurgeResult;

add_action('comments_through_cache_purged', static function (array $results): void {
    foreach ($results as $result) {
        if ($result instanceof PurgeResult) {
            error_log('[purge] ' . ($result->isSuccess() ? 'ok ' : 'FAIL ') . $result->summary());
        }
    }
});
