<?php

declare(strict_types=1);

namespace IndianOpinion\CommentsThroughCache;

/**
 * Builds the purge set for one post: the URLs that show its comments
 * (C20, C21).
 */
final class PurgeUrlCollector
{
    public const MAX_COMMENT_PAGES_DEFAULT = 10;

    public function __construct(private Logger $logger)
    {
    }

    /**
     * @return list<string> Absolute URLs on the site's own host. Empty when
     *                      the post is not public.
     */
    public function forPost(int $postId, string $reason = 'manual'): array
    {
        $post = get_post($postId);
        if (!$post instanceof \WP_Post || !$this->isPublic($post)) {
            return []; // C21.
        }
        $permalink = get_permalink($post);
        if (!is_string($permalink) || $permalink === '') {
            return [];
        }

        $urls = [$permalink];
        foreach ($this->commentPageUrls($post, $permalink) as $url) {
            $urls[] = $url;
        }
        $feed = get_post_comments_feed_link($post->ID);
        if (is_string($feed) && $feed !== '') {
            $urls[] = $feed;
        }

        $filtered = apply_filters('comments_through_cache_purge_urls', $urls, $post->ID, $reason);
        return $this->normalise(is_array($filtered) ? $filtered : $urls);
    }

    private function isPublic(\WP_Post $post): bool
    {
        if ($post->post_status !== 'publish') {
            return false;
        }
        if ((string) $post->post_password !== '') {
            return false;
        }
        return (bool) is_post_publicly_viewable($post);
    }

    /**
     * One URL per comment page that exists, plus one more in case a removal
     * just emptied the last page, capped by the max-pages filter.
     *
     * @return list<string>
     */
    private function commentPageUrls(\WP_Post $post, string $permalink): array
    {
        if (!get_option('page_comments')) {
            return [];
        }
        $cap = apply_filters('comments_through_cache_max_comment_pages', self::MAX_COMMENT_PAGES_DEFAULT);
        $cap = is_numeric($cap) ? max(0, (int) $cap) : self::MAX_COMMENT_PAGES_DEFAULT;
        if ($cap === 0) {
            return [];
        }
        $perPage = (int) get_option('comments_per_page');
        if ($perPage <= 0) {
            return [];
        }
        $query = [
            'post_id' => $post->ID,
            'status' => 'approve',
            'count' => true,
        ];
        if (get_option('thread_comments')) {
            $query['parent'] = 0; // Core pages by top-level comments when threaded.
        }
        $count = get_comments($query);
        $count = is_numeric($count) ? (int) $count : 0;
        $pages = min($cap, (int) ceil($count / $perPage) + 1);

        $pretty = (string) get_option('permalink_structure') !== '' && !str_contains($permalink, '?');
        $base = 'comment-page';
        if (isset($GLOBALS['wp_rewrite']) && is_object($GLOBALS['wp_rewrite']) && isset($GLOBALS['wp_rewrite']->comments_pagination_base)) {
            $base = (string) $GLOBALS['wp_rewrite']->comments_pagination_base;
        }

        $urls = [];
        for ($page = 1; $page <= $pages; $page++) {
            if ($pretty) {
                $urls[] = user_trailingslashit(trailingslashit($permalink) . $base . '-' . $page, 'commentpaged');
            } else {
                $urls[] = add_query_arg('cpage', (string) $page, $permalink);
            }
        }
        return $urls;
    }

    /**
     * Strips fragments, makes relative URLs absolute, drops hosts other than
     * the site's own (and any added through the allowed-hosts filter, for
     * example a www twin) and removes duplicates.
     *
     * @param array<mixed> $urls
     *
     * @return list<string>
     */
    public function normalise(array $urls): array
    {
        $home = parse_url((string) home_url('/'));
        $homeHost = strtolower((string) ($home['host'] ?? ''));
        $homeScheme = strtolower((string) ($home['scheme'] ?? 'https'));
        $homePort = isset($home['port']) ? ':' . $home['port'] : '';
        $allowed = [$homeHost];
        $extra = apply_filters('comments_through_cache_allowed_hosts', [$homeHost]);
        if (is_array($extra)) {
            foreach ($extra as $h) {
                if (is_string($h) && $h !== '') {
                    $allowed[] = strtolower($h);
                }
            }
        }

        $out = [];
        foreach ($urls as $url) {
            if (!is_string($url) || trim($url) === '') {
                continue;
            }
            $url = trim($url);
            $hash = strpos($url, '#');
            if ($hash !== false) {
                $url = substr($url, 0, $hash);
            }
            if (str_starts_with($url, '//')) {
                $url = $homeScheme . ':' . $url;
            } elseif (str_starts_with($url, '/')) {
                $url = $homeScheme . '://' . $homeHost . $homePort . $url;
            }
            $parts = parse_url($url);
            $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
            $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
            if ($host === '' || !in_array($host, $allowed, true) || !in_array($scheme, ['http', 'https'], true)) {
                $this->logger->debug('Dropped a purge URL that is not on the site host.');
                continue;
            }
            if (!in_array($url, $out, true)) {
                $out[] = $url;
            }
        }
        return $out;
    }
}
