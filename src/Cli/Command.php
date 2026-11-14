<?php

declare(strict_types=1);

namespace IndianOpinion\CommentsThroughCache\Cli;

use IndianOpinion\CommentsThroughCache\Checker;
use IndianOpinion\CommentsThroughCache\Config;
use IndianOpinion\CommentsThroughCache\PurgeQueue;

/**
 * Checks the cache setup and purges posts on demand.
 */
final class Command
{
    /** @var callable(int): void */
    private $exit;

    /** @var callable(): list<string> */
    private $problems;

    /**
     * @param callable(int): void|null       $exit     Ends the command with a code.
     * @param callable(): list<string>|null $problems Every configuration problem.
     */
    public function __construct(
        private Config $config,
        private PurgeQueue $queue,
        private Checker $checker,
        private ?Output $output = null,
        ?callable $exit = null,
        ?callable $problems = null
    ) {
        $this->problems = $problems ?? static fn (): array => $config->problems();
        $this->exit = $exit ?? static function (int $code): void {
            \WP_CLI::halt($code);
        };
    }

    /**
     * Checks that the cache in front of a URL honours the bypass rules.
     *
     * Needs COMMENTS_THROUGH_CACHE_DEBUG on the site. Point it at the public
     * URL to test the CDN path, or at the origin to test the origin cache.
     *
     * ## OPTIONS
     *
     * <url>
     * : Absolute URL of a published article.
     *
     * ## EXAMPLES
     *
     *     wp comments-through-cache check https://example.com/2026/11/hello-world/
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function check(array $args, array $assoc = []): void
    {
        $url = $args[0] ?? '';
        $report = $this->checker->check($url);
        foreach ($report->formatted() as $line) {
            $this->out()->line($line);
        }
        ($this->exit)($report->exitCode());
    }

    /**
     * Purges one post's purge set through every active purger.
     *
     * ## OPTIONS
     *
     * <post-id>
     * : The post ID.
     *
     * ## EXAMPLES
     *
     *     wp comments-through-cache purge 123
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function purge(array $args, array $assoc = []): void
    {
        $id = $args[0] ?? '';
        if (!ctype_digit($id) || (int) $id <= 0) {
            $this->out()->error('Give a numeric post ID.');
            ($this->exit)(1);
            return;
        }
        $results = $this->queue->purgePost((int) $id, 'manual');
        if ($results === []) {
            $this->out()->line('Nothing to purge: the post is missing or not public.');
            ($this->exit)(0);
            return;
        }
        $failed = false;
        foreach ($results as $result) {
            $this->out()->line(($result->isSuccess() ? 'OK   ' : 'FAIL ') . $result->summary());
            $failed = $failed || !$result->isSuccess();
        }
        ($this->exit)($failed ? 1 : 0);
    }

    /**
     * Prints the effective configuration. The API token is shown as set or
     * not set, never its value.
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function config(array $args = [], array $assoc = []): void
    {
        foreach ($this->config->describe() as $key => $value) {
            $this->out()->line(sprintf('%-16s %s', $key, $value));
        }
        foreach (($this->problems)() as $problem) {
            $this->out()->error($problem);
        }
    }

    private function out(): Output
    {
        if ($this->output === null) {
            $this->output = new WpCliOutput();
        }
        return $this->output;
    }
}
