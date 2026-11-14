# comments-through-cache

A WordPress must-use plugin for sites served from a page cache. A reader whose
comment is held for moderation keeps seeing it on every refresh, and an
approved comment reaches every reader at once through a targeted purge of that
article's URLs.

Measured on a production news site: an uncached article render took about
1.3 s and a cache hit about 5 ms, so turning the cache off was never the
answer; before this plugin, approved comments took ten to twenty minutes to
appear and held comments vanished on refresh.

This project came out of the work on indianopinion.org and is written from
scratch; it is independent of and not affiliated with any third-party plugin
it integrates with.

## Contents

- [Install](#install)
- [Quick start](#quick-start)
- [Configuration](#configuration)
- [Cache rules you must add](#cache-rules-you-must-add)
- [How it works](#how-it-works)
- [The cookie, privacy and your cookie policy](#the-cookie-privacy-and-your-cookie-policy)
- [WP-CLI](#wp-cli)
- [Limitations](#limitations)
- [FAQ](#faq)
- [Licence](#licence)

## Install

Requirements: PHP 8.1 or later, WordPress 6.4 or later. No Composer needed at
runtime.

### Without Composer (recommended: as a must-use plugin)

1. Copy the plugin folder to `wp-content/mu-plugins/comments-through-cache/`.
2. Copy `examples/mu-plugin-loader.php` to
   `wp-content/mu-plugins/comments-through-cache.php`. It is one line:

   ```php
   require_once __DIR__ . '/comments-through-cache/comments-through-cache.php';
   ```

WordPress loads files at the top of `mu-plugins/` only, which is why the
loader is needed.

You can also install it as a normal plugin: copy the folder to
`wp-content/plugins/` and activate it.

### With Composer

```sh
composer require indianopinion/comments-through-cache
```

The package type is `wordpress-muplugin`, so `composer/installers` places it
in `mu-plugins/` if your project uses it. Add the loader above. If you load
Composer's autoloader yourself, the plugin uses it; otherwise it loads its own
classes.

## Quick start

With no configuration at all the plugin already:

- sets a short-lived email cookie for anonymous readers who did not tick the
  "save my name, email and website" box, so their held comment survives a
  refresh and their requests skip the page cache;
- sends `Cache-Control: private, no-store, max-age=0` and
  `X-Accel-Expires: 0` on the post-comment redirect URL;
- prints a notice above the comment form: "Thank you. Your comment has been
  received and will appear once it has been approved.";
- fires `comments_through_cache_purge` with the article's URLs whenever a
  comment's public visibility changes.

Then, for the purge to reach your caches:

1. Add the bypass rules for your cache ([below](#cache-rules-you-must-add)).
2. Pick purgers in `wp-config.php`, for example nginx cache files and then
   Cloudflare:

   ```php
   define('COMMENTS_THROUGH_CACHE_PURGERS', 'file,cloudflare');
   define('COMMENTS_THROUGH_CACHE_FILE_PATH', '/var/cache/nginx/wordpress');
   define('COMMENTS_THROUGH_CACHE_CF_ZONE_ID', '<your-zone-id>');
   define('COMMENTS_THROUGH_CACHE_CF_API_TOKEN', '<your-api-token>');
   ```

3. Turn on `COMMENTS_THROUGH_CACHE_DEBUG`, run
   `wp comments-through-cache check https://your-site/some-article/`, fix any
   FAIL line, then turn debug off.

## Configuration

Constants go in `wp-config.php`. Filters override constants. See
`examples/wp-config-constants.php`.

### Constants

| Constant | Type | Default | Notes |
|---|---|---|---|
| `COMMENTS_THROUGH_CACHE_COOKIE_LIFETIME` | int seconds | `86400` | Clamped to 300 to 2 592 000. |
| `COMMENTS_THROUGH_CACHE_COOKIE_FIELDS` | `email` or `all` | `email` | `all` also sets the name and URL cookies (form prefill). |
| `COMMENTS_THROUGH_CACHE_EARLY_EXPIRY` | bool | `true` | Clear the cookie once the reader has no held comments left. |
| `COMMENTS_THROUGH_CACHE_NOTICE` | bool | `true` | The held-comment notice. |
| `COMMENTS_THROUGH_CACHE_PURGERS` | comma list of `action`, `http`, `file`, `cloudflare` | `action` | Order within a layer is kept; origin always before edge. |
| `COMMENTS_THROUGH_CACHE_HTTP_ENDPOINT` | URL template | none | Required for `http`. Tokens `{scheme}`, `{host}`, `{path}`, `{query}`. |
| `COMMENTS_THROUGH_CACHE_HTTP_METHOD` | `PURGE` or `GET` | `PURGE` | |
| `COMMENTS_THROUGH_CACHE_FILE_PATH` | absolute directory | none | Required for `file`. Same as `fastcgi_cache_path`. |
| `COMMENTS_THROUGH_CACHE_FILE_LEVELS` | like `1:2` | `1:2` | Must match the cache's `levels`. |
| `COMMENTS_THROUGH_CACHE_FILE_KEY` | key template | `{scheme}{method}{host}{uri}` | Must match `fastcgi_cache_key`. |
| `COMMENTS_THROUGH_CACHE_FILE_METHODS` | comma list | `GET,HEAD` | One cache file per method is removed. |
| `COMMENTS_THROUGH_CACHE_CF_ZONE_ID` | string | none | Required for `cloudflare`. |
| `COMMENTS_THROUGH_CACHE_CF_API_TOKEN` | string | none | Required for `cloudflare`. Scope it to Cache Purge on one zone. |
| `COMMENTS_THROUGH_CACHE_HTTP_TIMEOUT` | int seconds | `3` | Per request, 1 to 30. |
| `COMMENTS_THROUGH_CACHE_PURGE_BUDGET` | int seconds | `5` | Whole flush, 1 to 60. |
| `COMMENTS_THROUGH_CACHE_DEBUG` | bool | `false` | Render ID header and debug log lines. |

### Filters

| Filter | Arguments | Default | Purpose |
|---|---|---|---|
| `comments_through_cache_should_set_cookie` | bool, comment, user, consent | `true` when C1 or C2 apply | Veto the cookie per comment. |
| `comments_through_cache_cookie_lifetime` | int, comment | constant | Lifetime, then clamped. |
| `comments_through_cache_cookie_fields` | `email` or `all`, comment | constant | Which cookies. |
| `comments_through_cache_early_expiry_grace` | int seconds | `300` | Minimum age before early expiry. |
| `comments_through_cache_no_store_args` | list of names | `unapproved`, `moderation-hash` | Query arguments that get no-store headers. |
| `comments_through_cache_show_held_notice` | bool, post ID, `redirect` or `cookie` | `true` | Turn the notice off per context. |
| `comments_through_cache_held_notice_text` | string, post ID | default text | Change the text; empty string hides it. |
| `comments_through_cache_purge_urls` | list, post ID, reason | permalink, comment pages, comments feed | Add twins or listings. |
| `comments_through_cache_allowed_hosts` | list of hosts | the home host | Allow a `www` twin in the purge set. |
| `comments_through_cache_max_comment_pages` | int | `10` | Cap on comment pages purged. |
| `comments_through_cache_should_purge` | bool, comment, new status, old status | per C22 to C28 | Override a purge. |
| `comments_through_cache_purgers` | list of `Purger` | from the constant | Add or replace purgers. |
| `comments_through_cache_purger_enabled` | bool, name, purger | `true` | Turn one purger off, for example when a cache plugin already purges. |
| `comments_through_cache_cloudflare_batch_size` | int | `30` | URLs per Cloudflare request, 1 to 100. |
| `comments_through_cache_finish_request` | bool | `true` | Send the response before purging on PHP-FPM. |

### Actions

| Action | Arguments | When |
|---|---|---|
| `comments_through_cache_purge` | list of URLs, reason | Fired by the `action` purger. |
| `comments_through_cache_purged` | list of `PurgeResult` | After every flush. |
| `comments_through_cache_cookie_set` | comment ID, fields set, lifetime | After the cookie is set. |
| `comments_through_cache_cookie_cleared` | fields cleared | After early expiry. |

Reasons: `comment_approved_on_post`, `status_to_approved`,
`status_from_approved`, `approved_comment_edited`, `manual`.

### Which setup needs what

| Setup | Cookie and notice | Bypass rules to add | Purgers |
|---|---|---|---|
| No page cache | works | none | none needed (action purger is harmless) |
| Cache plugin only | works if the plugin bypasses on `comment_author_` (most do) | check the plugin's cookie list | `action` if the plugin listens, or its own hook |
| Origin cache only (nginx, Varnish) | works once C13 to C16 are in the cache config | C13 to C16 at origin | `file` or `http` |
| CDN that does not cache HTML, origin cache behind it | works | C13 to C16 at origin only | `file` or `http` |
| CDN that caches HTML, origin cache behind it | works only if the CDN also honours C13 and C14 | C13 to C16 at origin and at the edge | origin purger, then `cloudflare` |
| CDN that caches HTML, no origin cache | works only if the CDN honours C13 and C14 | C13, C14 at the edge | `cloudflare` |
| CDN that caches HTML and cannot bypass on a cookie | the redirect page works; refresh shows the cached copy until edge TTL | C14 at the edge; keep edge HTML TTL short | `cloudflare` |

## Cache rules you must add

The plugin cannot make a cache do anything. Every cache in front of PHP must:

- **C13** neither serve nor store a request whose `Cookie` header has a name
  starting with `comment_author_` (also `wordpress_logged_in_` and
  `wp-postpass_`);
- **C14** neither serve nor store a request whose query has `unapproved=` or
  `moderation-hash=`. This must be a cache rule: many caches ignore upstream
  `Cache-Control`;
- **C15** never store a response that carries `Set-Cookie`;
- **C16** cache only `GET` and `HEAD`.

Ready-made rules:

- `examples/nginx-fastcgi-cache.conf`: maps for nginx FastCGI cache.
- `examples/varnish.vcl`: Varnish 6 or 7.
- `examples/cloudflare-cache-rule.txt`: a Cloudflare Cache Rule. Cache Rules
  exist on every plan. The expression is:

  ```
  (http.cookie contains "comment_author_") or (http.cookie contains "wordpress_logged_in_") or (http.cookie contains "wp-postpass_") or (http.request.uri.query contains "unapproved=") or (http.request.uri.query contains "moderation-hash=")
  ```

  with the action "Bypass cache", placed below any rule that makes HTML
  eligible for cache.

**nginx cache key.** The key must include `$request_method`:

```nginx
fastcgi_cache_key "$scheme$request_method$host$request_uri";
```

Without it a `HEAD` response can answer a `GET`, and the file purger cannot
know which key to remove. With it, the file purger removes the `GET` and the
`HEAD` file for each URL, which is right whether or not your cache converts
`HEAD` to `GET`.

**Warning about CDN TTL overrides.** A CDN rule that overrides the edge TTL
for HTML may ignore the origin's `Cache-Control`, including `no-store`. The
redirect URL then needs the explicit bypass rule (C14), not just the plugin's
header.

**Rate limits.** Anyone can send a `comment_author_` cookie and get an
uncached page. That is true of every WordPress cache setup. Rate-limit
uncached requests at the origin.

**Logs.** Do not put the `Cookie` header in access logs or CDN log exports:
the commenter cookie holds an email address.

## How it works

1. **After a comment is posted.** Core's comment handler fires
   `set_comment_cookies`. Without consent, core sends expiring cookies at
   priority 10. The plugin runs at priority 20 and sends the email cookie
   with a one day lifetime; browsers apply `Set-Cookie` headers in order, so
   the later one wins. Nothing is set for logged-in users, readers who
   consented (core already did), or comments saved as spam or trash. A small
   marker cookie (`comments_through_cache_<hash>`, HttpOnly, holding only a
   timestamp) records that the plugin, not the reader's consent, set it.
2. **On the next request.** The cookie makes the cache bypass, core finds the
   reader's held comments by email and lists them, and the plugin prints the
   notice above the form. Once the reader has no held comments left on the
   site (and at least five minutes have passed), the plugin expires its
   cookie and the reader is back on cached pages.
3. **On the redirect URL.** No-store headers, as a second line of defence
   behind the cache rule. Whether the hash is valid, and for how long, is
   core's decision (ten minutes after posting in every supported version).
4. **On moderation.** Any change that makes a comment enter or leave the
   approved state, or an edit of an approved comment, queues the post. At
   shutdown, after the response is sent where PHP-FPM allows, the queue
   builds each post's purge set (the permalink, the comment pages that
   exist plus one, capped at ten, the comments feed, and anything the
   filter adds), merges the sets of every queued post, removes duplicates,
   and gives each purger one call for the lot. Bulk moderation on twenty
   posts is one Cloudflare request, not twenty. Every origin purger
   finishes before any edge purger starts, so the CDN cannot refetch a
   stale origin copy. Failures are logged and never block
   moderation; the page then updates at its cache TTL.

Purgers:

- `action` fires `comments_through_cache_purge` for a cache plugin or your
  own code.
- `http` sends one request per URL to a purge endpoint (nginx purge module,
  Varnish) with the site's `Host` header. 2xx and 404 count as success.
  Keep the endpoint on a local or private address.
- `file` removes nginx cache files directly. It recomputes the file name from
  the key and levels, and refuses anything outside the cache directory,
  symbolic links, and anything that is not a regular file.
- `cloudflare` purges by URL through the API, 30 URLs per request.

## The cookie, privacy and your cookie policy

Core's commenter cookies hold the author's name, email address and website in
clear text. They are not HttpOnly, because themes read them to prefill the
form, so any script on the page can read them. They are sent with every
request to the site.

This plugin sets the email cookie for readers who did **not** tick the
consent box. It keeps that to a minimum: only the email cookie by default,
one day instead of core's year, nothing for logged-in users or spam, and it
clears the cookie as soon as nothing is held.

The case for setting it is that the cookie delivers something the reader has
just asked for: seeing the comment they submitted. Whether that meets the
"strictly necessary" exemption where you operate (for example PECR and UK
GDPR in the UK, or the ePrivacy rules in the EU) is a legal question. **Confirm
the wording below, and the decision to run the plugin, with your own
counsel.** This project makes no legal ruling.

Text you can adapt for a cookie policy:

> **comment_author_email_&lt;hash&gt;** and
> **comments_through_cache_&lt;hash&gt;**. Set when you post a comment that
> waits for moderation, so that you can see your own comment while it waits.
> The first holds the email address you entered; the second holds only the
> time it was set. Both last at most one day (adjust if you change the
> lifetime) and are removed once none of your comments are waiting.

To turn the cookie off entirely:

```php
add_filter('comments_through_cache_should_set_cookie', '__return_false');
```

The rest of the plugin (no-store headers, notice on the redirect URL, purges)
works without it.

The plugin stores nothing in the database and never logs cookie values, email
addresses, comment content or tokens.

## WP-CLI

```sh
wp comments-through-cache check <url>      # test the cache in front of <url>
wp comments-through-cache purge <post-id>  # purge one post now
wp comments-through-cache config           # effective settings, token masked
```

`check` needs `COMMENTS_THROUGH_CACHE_DEBUG` on, so that each render carries a
unique `X-Comments-Through-Cache-Render` ID. It fetches the URL plainly, with
a placeholder `comment_author_email_` cookie, and with
`?unapproved=0&moderation-hash=x`, and prints one line per check. Exit code 0
means all passed. Point it at the public URL to test the CDN path, or at the
origin to test the origin cache.

Site Health (Tools > Site Health) shows the active purgers, any configuration
problem as critical, and a recommendation when only the action purger runs
and nothing listens to it.

## Limitations

- Themes must submit comments through core's `wp-comments-post.php`. A form
  that posts through its own AJAX endpoint and never fires
  `set_comment_cookies` gets no cookie and no preview.
- A theme that does not call `comment_form()` gets no automatic notice. Call
  `comments_through_cache_held_notice()` in the template where you want it.
- A theme whose JavaScript prefills the form from the name and URL cookies
  needs `COMMENTS_THROUGH_CACHE_COOKIE_FIELDS` set to `all`.
- Listing pages (home page, archives, "latest comments" widgets) are not
  purged. They follow their own TTL. Add them with the
  `comments_through_cache_purge_urls` filter
  (`examples/purge-listing-pages.php`).
- A comment posted without an email address cannot be shown through the
  cookie, because core looks held comments up by email.
- The flush budget is checked between purgers and between requests, and each
  request's timeout is cut to what is left of the budget (never below one
  second), so a flush can overrun the budget by at most about a second.
- No settings screen in 0.1.0.

## FAQ

**Will this double-purge with my cache plugin?** If your cache plugin
already purges a post on comment approval, turn off the matching purger with
`comments_through_cache_purger_enabled` (`examples/disable-purger.php`).

**Why only the email cookie?** It is the only one core needs to show a held
comment, and its name matches the bypass rule. The name and URL cookies only
prefill the form, which the reader did not ask for.

**Does the notice say how long the redirect link works?** No. Core decides
that window, and the cookie keeps the comment visible after it closes.

**Why 30 URLs per Cloudflare request?** Cloudflare's current limit is higher
on every plan; 30 is a safe default that keeps each request small. Raise it
with `comments_through_cache_cloudflare_batch_size` (up to 100).

**A reader says their comment disappeared.** Run `check` against the public
URL. A FAIL on the cookie line means a cache layer ignores the cookie.

## Licence

MIT. See [LICENSE](LICENSE). Copyright (c) 2026 Indian Opinion.
