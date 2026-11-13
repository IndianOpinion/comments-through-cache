<?php

/**
 * Plugin Name:       Comments Through Cache
 * Plugin URI:        https://github.com/IndianOpinion/comments-through-cache
 * Description:       Keeps a held comment visible to the reader who wrote it behind a page cache, and purges exactly the article's URLs when a comment is approved or removed.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Indian Opinion
 * Author URI:        https://indianopinion.org
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       comments-through-cache
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists(\IndianOpinion\CommentsThroughCache\Plugin::class)) {
    require_once __DIR__ . '/src/autoload.php';
}

\IndianOpinion\CommentsThroughCache\Plugin::boot();
