<?php

/**
 * Plugin Name: Custom purger (example)
 *
 * Adds a purger for a cache the plugin does not know. Layer "edge" makes it
 * run after every origin purger.
 */

use IndianOpinion\CommentsThroughCache\Purger\Purger;
use IndianOpinion\CommentsThroughCache\PurgeResult;

add_filter('comments_through_cache_purgers', static function (array $purgers): array {
    $purgers[] = new class implements Purger {
        public function purge(array $urls, string $reason = 'manual'): PurgeResult
        {
            $done = [];
            $errors = [];
            foreach ($urls as $url) {
                $response = wp_remote_post('https://cdn-api.example.net/purge', [
                    'timeout' => 3,
                    'body' => ['url' => $url],
                ]);
                if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
                    $errors[] = 'purge failed';
                    continue;
                }
                $done[] = $url;
            }
            return new PurgeResult($this->name(), $urls, $done, $errors);
        }

        public function layer(): string
        {
            return Purger::LAYER_EDGE;
        }

        public function name(): string
        {
            return 'example-cdn';
        }
    };
    return $purgers;
});
