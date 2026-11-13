<?php

/**
 * A small stand-in for the parts of WordPress the plugin calls, so the tests
 * run without a WordPress install. State lives in WpStub and is reset before
 * every test.
 */

declare(strict_types=1);

namespace {
    const ABSPATH = '/tmp/wordpress/';
    const COOKIEHASH = 'c0ffee';
    const COOKIEPATH = '/';
    const COOKIE_DOMAIN = '';
    const YEAR_IN_SECONDS = 31536000;

    final class WpStub
    {
        /** @var array<string, array<int, list<array{0: callable, 1: int}>>> */
        public static array $hooks = [];
        /** @var list<array{0: string, 1: array<mixed>}> */
        public static array $fired = [];
        /** @var array<string, mixed> */
        public static array $options = [];
        /** @var array<int, WP_Post> */
        public static array $posts = [];
        /** @var array<int, WP_Comment> */
        public static array $comments = [];
        public static string $home = 'https://example.com';
        public static int $theId = 0;
        /** @var array{comment_author: string, comment_author_email: string, comment_author_url: string} */
        public static array $commenter = ['comment_author' => '', 'comment_author_email' => '', 'comment_author_url' => ''];
        public static string $unapprovedEmail = '';
        /** @var list<string> */
        public static array $dbWrites = [];
        /** @var list<array<string, mixed>> */
        public static array $commentQueries = [];
        /** @var array<string, string> Post types that are not public. */
        public static array $privateTypes = [];
        /** @var callable|null */
        public static $remote = null;

        public static function reset(): void
        {
            self::$hooks = [];
            self::$fired = [];
            self::$options = [
                'page_comments' => 0,
                'comments_per_page' => 50,
                'thread_comments' => 1,
                'permalink_structure' => '/%postname%/',
            ];
            self::$posts = [];
            self::$comments = [];
            self::$home = 'https://example.com';
            self::$theId = 0;
            self::$commenter = ['comment_author' => '', 'comment_author_email' => '', 'comment_author_url' => ''];
            self::$unapprovedEmail = '';
            self::$dbWrites = [];
            self::$commentQueries = [];
            self::$privateTypes = [];
            self::$remote = null;
            $_GET = [];
            $_COOKIE = [];
        }

        /**
         * @return list<array<mixed>> Arguments of every firing of an action.
         */
        public static function firings(string $action): array
        {
            $out = [];
            foreach (self::$fired as [$name, $args]) {
                if ($name === $action) {
                    $out[] = $args;
                }
            }
            return $out;
        }

        public static function addPost(int $id, array $fields = []): WP_Post
        {
            $post = new WP_Post();
            $post->ID = $id;
            $post->post_name = 'post-' . $id;
            foreach ($fields as $k => $v) {
                $post->$k = $v;
            }
            return self::$posts[$id] = $post;
        }

        public static function addComment(int $id, array $fields = []): WP_Comment
        {
            $c = new WP_Comment();
            $c->comment_ID = (string) $id;
            foreach ($fields as $k => $v) {
                $c->$k = $v;
            }
            return self::$comments[$id] = $c;
        }
    }

    class WP_Comment
    {
        public string $comment_ID = '0';
        public string $comment_post_ID = '0';
        public string $comment_author = 'A Reader';
        public string $comment_author_email = 'reader@example.org';
        public string $comment_author_url = '';
        public string $comment_approved = '0';
        public string $comment_parent = '0';
        public string $comment_date_gmt = '2026-11-12 10:00:00';
    }

    class WP_User
    {
        public int $ID = 0;

        public function __construct(int $id = 0)
        {
            $this->ID = $id;
        }

        public function exists(): bool
        {
            return $this->ID > 0;
        }
    }

    class WP_Post
    {
        public int $ID = 0;
        public string $post_status = 'publish';
        public string $post_type = 'post';
        public string $post_password = '';
        public string $post_name = '';
    }

    class WP_Error
    {
        public function __construct(private string $message = '')
        {
        }

        public function get_error_message(): string
        {
            return $this->message;
        }
    }

    class WP_CLI
    {
        /** @var list<string> */
        public static array $lines = [];
        /** @var array<string, object> */
        public static array $commands = [];

        public static function line(string $text): void
        {
            self::$lines[] = $text;
        }

        public static function warning(string $text): void
        {
            self::$lines[] = 'Warning: ' . $text;
        }

        public static function halt(int $code): void
        {
            self::$lines[] = 'halt ' . $code;
        }

        public static function add_command(string $name, object $command): void
        {
            self::$commands[$name] = $command;
        }
    }

    function add_filter(string $hook, callable $callback, int $priority = 10, int $args = 1): bool
    {
        WpStub::$hooks[$hook][$priority][] = [$callback, $args];
        ksort(WpStub::$hooks[$hook]);
        return true;
    }

    function add_action(string $hook, callable $callback, int $priority = 10, int $args = 1): bool
    {
        return add_filter($hook, $callback, $priority, $args);
    }

    function has_action(string $hook): bool
    {
        return !empty(WpStub::$hooks[$hook]);
    }

    function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
    {
        foreach (WpStub::$hooks[$hook] ?? [] as $callbacks) {
            foreach ($callbacks as [$callback, $count]) {
                $value = $callback(...array_slice([$value, ...$args], 0, $count));
            }
        }
        return $value;
    }

    function do_action(string $hook, mixed ...$args): void
    {
        WpStub::$fired[] = [$hook, $args];
        foreach (WpStub::$hooks[$hook] ?? [] as $callbacks) {
            foreach ($callbacks as [$callback, $count]) {
                $callback(...array_slice($args, 0, $count));
            }
        }
    }

    function home_url(string $path = ''): string
    {
        return rtrim(WpStub::$home, '/') . '/' . ltrim($path, '/');
    }

    function get_option(string $name, mixed $default = false): mixed
    {
        return WpStub::$options[$name] ?? $default;
    }

    function update_option(string $name, mixed $value): bool
    {
        WpStub::$dbWrites[] = 'update_option:' . $name;
        return true;
    }

    function add_option(string $name, mixed $value = ''): bool
    {
        WpStub::$dbWrites[] = 'add_option:' . $name;
        return true;
    }

    function set_transient(string $name, mixed $value, int $ttl = 0): bool
    {
        WpStub::$dbWrites[] = 'set_transient:' . $name;
        return true;
    }

    function update_comment_meta(int $id, string $key, mixed $value): bool
    {
        WpStub::$dbWrites[] = 'update_comment_meta:' . $key;
        return true;
    }

    function update_post_meta(int $id, string $key, mixed $value): bool
    {
        WpStub::$dbWrites[] = 'update_post_meta:' . $key;
        return true;
    }

    function get_comment(mixed $id): ?WP_Comment
    {
        return WpStub::$comments[(int) $id] ?? null;
    }

    /**
     * @param array<string, mixed> $args
     */
    function get_comments(array $args = []): int|array
    {
        WpStub::$commentQueries[] = $args;
        $status = $args['status'] ?? 'all';
        $out = [];
        foreach (WpStub::$comments as $c) {
            if (isset($args['post_id']) && (int) $c->comment_post_ID !== (int) $args['post_id']) {
                continue;
            }
            if (isset($args['author_email']) && strtolower($c->comment_author_email) !== strtolower((string) $args['author_email'])) {
                continue;
            }
            if ($status === 'hold' && $c->comment_approved !== '0') {
                continue;
            }
            if ($status === 'approve' && $c->comment_approved !== '1') {
                continue;
            }
            if (isset($args['parent']) && (int) $c->comment_parent !== (int) $args['parent']) {
                continue;
            }
            $out[] = $c;
        }
        return !empty($args['count']) ? count($out) : $out;
    }

    function get_post(mixed $post): ?WP_Post
    {
        return WpStub::$posts[(int) $post] ?? null;
    }

    function get_permalink(mixed $post): string|false
    {
        $post = $post instanceof WP_Post ? $post : get_post($post);
        if ($post === null) {
            return false;
        }
        if ((string) get_option('permalink_structure') === '') {
            return home_url('/?p=' . $post->ID);
        }
        return home_url('/' . $post->post_name . '/');
    }

    function get_post_comments_feed_link(int $postId): string
    {
        $permalink = (string) get_permalink($postId);
        if ((string) get_option('permalink_structure') === '') {
            return home_url('/?feed=comments-rss2&p=' . $postId);
        }
        return $permalink . 'feed/';
    }

    function is_post_publicly_viewable(WP_Post $post): bool
    {
        return !isset(WpStub::$privateTypes[$post->post_type]) && $post->post_status === 'publish';
    }

    function trailingslashit(string $value): string
    {
        return rtrim($value, '/\\') . '/';
    }

    function user_trailingslashit(string $value, string $type = ''): string
    {
        return trailingslashit($value);
    }

    function add_query_arg(string $key, string $value, string $url): string
    {
        return $url . (str_contains($url, '?') ? '&' : '?') . rawurlencode($key) . '=' . rawurlencode($value);
    }

    function esc_url(string $url): string
    {
        return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    }

    function esc_html(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }

    function esc_attr(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }

    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }

    function esc_html__(string $text, string $domain = 'default'): string
    {
        return esc_html($text);
    }

    function get_the_ID(): int|false
    {
        return WpStub::$theId > 0 ? WpStub::$theId : false;
    }

    /**
     * @return array{comment_author: string, comment_author_email: string, comment_author_url: string}
     */
    function wp_get_current_commenter(): array
    {
        return WpStub::$commenter;
    }

    function wp_get_unapproved_comment_author_email(): string
    {
        return WpStub::$unapprovedEmail !== '' ? WpStub::$unapprovedEmail : WpStub::$commenter['comment_author_email'];
    }

    /**
     * @param array<string, mixed> $args
     */
    function wp_remote_request(string $url, array $args = []): array|WP_Error
    {
        $handler = WpStub::$remote;
        return $handler === null ? new WP_Error('no handler') : $handler($url, $args);
    }

    function is_wp_error(mixed $thing): bool
    {
        return $thing instanceof WP_Error;
    }

    /**
     * @param array<string, mixed> $response
     */
    function wp_remote_retrieve_response_code(array $response): int
    {
        return (int) ($response['response']['code'] ?? 0);
    }

    /**
     * @param array<string, mixed> $response
     *
     * @return array<string, mixed>
     */
    function wp_remote_retrieve_headers(array $response): array
    {
        return $response['headers'] ?? [];
    }

    /**
     * @param array<string, mixed> $response
     */
    function wp_remote_retrieve_body(array $response): string
    {
        return (string) ($response['body'] ?? '');
    }
}
