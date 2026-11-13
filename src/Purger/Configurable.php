<?php

declare(strict_types=1);

namespace IndianOpinion\CommentsThroughCache\Purger;

/**
 * A purger that can report missing or invalid settings before it runs, for
 * Site Health and the config command.
 */
interface Configurable
{
    /**
     * @return list<string> Plain messages without secret values.
     */
    public function configurationProblems(): array;
}
