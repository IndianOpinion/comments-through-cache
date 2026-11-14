<?php

declare(strict_types=1);

use IndianOpinion\CommentsThroughCache\HeldNotice;

if (!function_exists('comments_through_cache_held_notice')) {
    /**
     * Prints the held-comment notice where a theme wants it (C19f). Prints
     * nothing when no held comment applies. Once called, the plugin does not
     * also print the notice above the comment form in this request.
     */
    function comments_through_cache_held_notice(): void
    {
        echo HeldNotice::render(); // Markup is escaped by HeldNotice::html().
    }
}
