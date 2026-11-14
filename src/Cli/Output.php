<?php

declare(strict_types=1);

namespace IndianOpinion\CommentsThroughCache\Cli;

/**
 * Where the commands write, so they can be tested without WP-CLI.
 */
interface Output
{
    public function line(string $text): void;

    public function error(string $text): void;
}
