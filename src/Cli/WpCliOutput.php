<?php

declare(strict_types=1);

namespace IndianOpinion\CommentsThroughCache\Cli;

/**
 * Output through WP-CLI.
 */
final class WpCliOutput implements Output
{
    public function line(string $text): void
    {
        \WP_CLI::line($text);
    }

    public function error(string $text): void
    {
        \WP_CLI::warning($text);
    }
}
