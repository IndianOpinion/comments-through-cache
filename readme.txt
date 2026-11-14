=== Comments Through Cache ===
Contributors: indianopinion
Tags: comments, moderation, cache, purge, cloudflare
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Keeps a held comment visible to its author behind a page cache, and purges exactly the article's URLs when a comment is approved or removed.

== Description ==

On a site served from a page cache, a reader who posts a comment that is held for moderation often sees it vanish on refresh, and an approved comment appears to other readers only when the cache expires.

This plugin fixes both:

* It sets a short-lived commenter email cookie for anonymous readers who did not tick the consent box, so core shows them their held comment and the page cache lets them through.
* It sends no-store headers on the post-comment redirect URL.
* It prints a plain "your comment is held" notice above the comment form.
* When a comment enters or leaves the approved state, it purges the article, its comment pages and its comments feed: origin cache first, then CDN.

Purgers: an action hook (default), an HTTP purge endpoint (nginx purge module, Varnish), nginx cache files, and Cloudflare purge by URL.

There is no settings screen. Configure it with constants in wp-config.php and filters. See the README on GitHub for the full list and the cache rules you must add.

This project came out of the work on indianopinion.org and is written from scratch; it is independent of and not affiliated with any third-party plugin it integrates with.

== Installation ==

1. Copy the plugin folder to wp-content/mu-plugins/comments-through-cache/.
2. Copy examples/mu-plugin-loader.php to wp-content/mu-plugins/comments-through-cache.php.
3. Add the bypass rules for your cache (examples/ has nginx, Varnish and Cloudflare rules).
4. Choose purgers in wp-config.php, for example: define('COMMENTS_THROUGH_CACHE_PURGERS', 'file,cloudflare');

It also works as a normal plugin in wp-content/plugins/.

== Frequently Asked Questions ==

= Is the cookie allowed without consent? =

The cookie delivers what the reader just asked for: seeing their own comment. Whether that meets the strictly necessary exemption where you operate is a legal question for you and your counsel. The README has text for a cookie policy and the one-line filter that turns the cookie off.

= How do I check my cache honours the rules? =

Turn on COMMENTS_THROUGH_CACHE_DEBUG and run: wp comments-through-cache check https://your-site/an-article/

= My cache plugin already purges on approval. =

Turn off the matching purger with the comments_through_cache_purger_enabled filter.

== Changelog ==

= 0.1.0 =
* First release.
