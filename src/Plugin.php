<?php

declare(strict_types=1);

namespace IndianOpinion\CommentsThroughCache;

use IndianOpinion\CommentsThroughCache\Cli\Command;
use IndianOpinion\CommentsThroughCache\Http\HttpClient;
use IndianOpinion\CommentsThroughCache\Http\WpHttpClient;
use IndianOpinion\CommentsThroughCache\Purger\ActionPurger;
use IndianOpinion\CommentsThroughCache\Purger\CloudflarePurger;
use IndianOpinion\CommentsThroughCache\Purger\Configurable;
use IndianOpinion\CommentsThroughCache\Purger\FileCachePurger;
use IndianOpinion\CommentsThroughCache\Purger\HttpPurger;
use IndianOpinion\CommentsThroughCache\Purger\Purger;

/**
 * Wires the plugin into WordPress.
 */
final class Plugin
{
    public const VERSION = '0.1.0';

    private static ?self $instance = null;

    private bool $registered = false;

    private CookieSetter $cookies;

    private NoStoreHeaders $headers;

    private HeldNotice $notice;

    private PurgeQueue $queue;

    private ModerationListener $listener;

    private SiteHealth $health;

    /** @var list<Purger>|null */
    private ?array $purgers = null;

    public function __construct(
        private Config $config,
        private Logger $logger,
        private ResponseHeaders $response,
        private HttpClient $http
    ) {
        $this->cookies = new CookieSetter($config, $logger, $response);
        $this->headers = new NoStoreHeaders($config, $response);
        $this->notice = new HeldNotice($config);
        $this->queue = new PurgeQueue(
            new PurgeUrlCollector($logger),
            fn (): array => $this->purgers(),
            $logger,
            $config->purgeBudget()
        );
        $this->listener = new ModerationListener($this->queue);
        $this->health = new SiteHealth($config, fn (): array => $this->queue->activePurgers());
    }

    /**
     * Builds the plugin from constants and registers its hooks. Safe to call
     * more than once.
     */
    public static function boot(): self
    {
        if (self::$instance === null) {
            $config = Config::fromConstants();
            self::$instance = new self(
                $config,
                new Logger($config->debug()),
                new NativeResponseHeaders(),
                new WpHttpClient()
            );
        }
        self::$instance->register();
        return self::$instance;
    }

    public static function instance(): ?self
    {
        return self::$instance;
    }

    /**
     * Adds every hook once.
     */
    public function register(): void
    {
        if ($this->registered) {
            return;
        }
        $this->registered = true;
        $this->notice->activate();

        add_action('set_comment_cookies', [$this->cookies, 'onSetCommentCookies'], CookieSetter::PRIORITY, 3);
        add_action('send_headers', [$this->headers, 'onSendHeaders'], 10, 0);
        add_action('send_headers', [$this->cookies, 'maybeExpireEarly'], 10, 0);
        add_action('comment_form_before', [$this->notice, 'onCommentFormBefore'], 10, 0);
        add_action('wp_insert_comment', [$this->listener, 'onInsert'], 10, 2);
        add_action('transition_comment_status', [$this->listener, 'onTransition'], 10, 3);
        add_action('edit_comment', [$this->listener, 'onEdit'], 10, 1);
        add_action('shutdown', [$this, 'onShutdown'], 100, 0);
        add_filter('site_status_tests', [$this->health, 'register']);

        if (defined('WP_CLI') && constant('WP_CLI') && class_exists('\WP_CLI')) {
            $command = new Command(
                $this->config,
                $this->queue,
                new Checker($this->http, defined('COOKIEHASH') ? (string) constant('COOKIEHASH') : ''),
                null,
                null,
                fn (): array => $this->problems()
            );
            \WP_CLI::add_command('comments-through-cache', $command);
        }
    }

    /**
     * Flushes the purge queue at shutdown, after the response is sent when
     * PHP-FPM allows it (C29).
     */
    public function onShutdown(): void
    {
        if ($this->queue->isEmpty()) {
            return;
        }
        $cli = defined('WP_CLI') && constant('WP_CLI');
        if (!$cli && function_exists('fastcgi_finish_request') && (bool) apply_filters('comments_through_cache_finish_request', true)) {
            fastcgi_finish_request();
        }
        $this->queue->flush();
    }

    /**
     * The configured purgers after the purgers filter, built once per request.
     *
     * @return list<Purger>
     */
    public function purgers(): array
    {
        if ($this->purgers !== null) {
            return $this->purgers;
        }
        $built = [];
        foreach ($this->config->purgerNames() as $name) {
            $built[] = match ($name) {
                'http' => new HttpPurger($this->http, $this->config->httpEndpoint(), $this->config->httpMethod(), $this->config->httpTimeout()),
                'file' => new FileCachePurger($this->config->filePath(), $this->config->fileLevels(), $this->config->fileKey(), $this->config->fileMethods()),
                'cloudflare' => new CloudflarePurger($this->http, $this->config->cloudflareZoneId(), $this->config->cloudflareApiToken(), $this->config->httpTimeout()),
                default => new ActionPurger(),
            };
        }
        $filtered = apply_filters('comments_through_cache_purgers', $built);
        $out = [];
        if (is_array($filtered)) {
            foreach ($filtered as $purger) {
                if ($purger instanceof Purger) {
                    $out[] = $purger;
                }
            }
        }
        return $this->purgers = $out;
    }

    /**
     * Every configuration problem: general ones and each purger's.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $problems = $this->config->problems();
        foreach ($this->purgers() as $purger) {
            if ($purger instanceof Configurable) {
                foreach ($purger->configurationProblems() as $problem) {
                    $problems[] = $problem;
                }
            }
        }
        return $problems;
    }

    public function queue(): PurgeQueue
    {
        return $this->queue;
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function logger(): Logger
    {
        return $this->logger;
    }

    public function response(): ResponseHeaders
    {
        return $this->response;
    }
}
