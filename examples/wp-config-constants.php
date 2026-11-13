<?php

/**
 * Example settings for wp-config.php. Copy only the lines you need, above the
 * "That's all, stop editing!" line. Every value shown is the default unless
 * the comment says otherwise.
 */

// Cookie for readers who did not tick the consent box.
define('COMMENTS_THROUGH_CACHE_COOKIE_LIFETIME', 86400);   // seconds, 300 to 2592000
define('COMMENTS_THROUGH_CACHE_COOKIE_FIELDS', 'email');   // 'email' or 'all'
define('COMMENTS_THROUGH_CACHE_EARLY_EXPIRY', true);       // clear it once nothing is held
define('COMMENTS_THROUGH_CACHE_NOTICE', true);

// Purgers, origin first (order within a layer is kept). Not the default.
define('COMMENTS_THROUGH_CACHE_PURGERS', 'file,cloudflare');

// nginx FastCGI cache without a purge module. Must match fastcgi_cache_path
// and fastcgi_cache_key in nginx.
define('COMMENTS_THROUGH_CACHE_FILE_PATH', '/var/cache/nginx/wordpress');
define('COMMENTS_THROUGH_CACHE_FILE_LEVELS', '1:2');
define('COMMENTS_THROUGH_CACHE_FILE_KEY', '{scheme}{method}{host}{uri}');
define('COMMENTS_THROUGH_CACHE_FILE_METHODS', 'GET,HEAD');

// A purge endpoint (nginx purge module, Varnish). Keep it on a local address.
// define('COMMENTS_THROUGH_CACHE_HTTP_ENDPOINT', 'http://127.0.0.1:6081{path}{query}');
// define('COMMENTS_THROUGH_CACHE_HTTP_METHOD', 'PURGE');

// Cloudflare: a token with Zone > Cache Purge on this zone only.
define('COMMENTS_THROUGH_CACHE_CF_ZONE_ID', '<your-zone-id>');
define('COMMENTS_THROUGH_CACHE_CF_API_TOKEN', '<your-api-token>');

define('COMMENTS_THROUGH_CACHE_HTTP_TIMEOUT', 3);   // per request
define('COMMENTS_THROUGH_CACHE_PURGE_BUDGET', 5);   // whole flush
define('COMMENTS_THROUGH_CACHE_DEBUG', false);
