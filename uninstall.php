<?php

/**
 * Uninstall handler.
 *
 * This plugin stores nothing: no options, tables, meta or transients, and it
 * writes no files. There is nothing to remove. Commenter cookies it set in
 * readers' browsers expire on their own after their lifetime (one day by
 * default). This file exists so the convention is visible.
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}
