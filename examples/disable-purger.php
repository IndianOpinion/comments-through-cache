<?php

/**
 * Plugin Name: Let the cache plugin purge (example)
 *
 * When a cache plugin already purges a post on comment approval, turn off
 * the matching purger here so the same URLs are not purged twice. Each
 * purger is asked by name: action, http, file, cloudflare, or a custom one.
 */

add_filter('comments_through_cache_purger_enabled', static function (bool $enabled, string $name): bool {
    return $name === 'cloudflare' ? false : $enabled;
}, 10, 2);
