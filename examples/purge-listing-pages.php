<?php

/**
 * Plugin Name: Purge listing pages on comment approval (example)
 *
 * The plugin purges only the article. This adds the home page and the post's
 * category archives. Every purge rebuilds those pages, so think twice on a
 * busy site.
 */

add_filter('comments_through_cache_purge_urls', static function (array $urls, int $postId, string $reason): array {
    $urls[] = home_url('/');
    foreach (get_the_category($postId) as $category) {
        $link = get_category_link($category);
        if (is_string($link) && $link !== '') {
            $urls[] = $link;
        }
    }
    return $urls;
}, 10, 3);
