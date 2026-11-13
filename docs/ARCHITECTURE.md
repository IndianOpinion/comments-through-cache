# Architecture

This document describes how comments-through-cache is built and records every
design decision, including the answers to the open questions in
[PRD.md](PRD.md) section 13.

## Modules

| File | Role | PRD cases |
|---|---|---|
| `comments-through-cache.php` | Plugin header, loads `src/autoload.php` when Composer has not, calls `Plugin::boot()` | 5.1 |
| `src/Plugin.php` | Builds every object, registers hooks once, builds purgers from constants, shutdown flush | 5.2, C29 |
| `src/Config.php` | Reads constants once, typed getters, clamping, problems list, masked description | 7 |
| `src/CookieSetter.php` | Commenter cookie after posting; early expiry on later requests | C1 to C12, Q3 |
| `src/NoStoreHeaders.php` | No-store headers on the redirect URL; debug render and bypass headers | C17, C18, 11 |
| `src/HeldNotice.php`, `src/functions.php` | Notice above the form; template function | C19a to C19g |
| `src/ModerationListener.php` | Comment events to queued purges | C22 to C28 |
| `src/PurgeUrlCollector.php` | Purge set for a post | C20, C21 |
| `src/PurgeQueue.php` | Per-post queue, layer ordering, error isolation, time budget | C29, C30, F1 to F4, F8, F9 |
| `src/Purger/*` | `Purger` interface; `ActionPurger`, `HttpPurger`, `FileCachePurger`, `CloudflarePurger`; `Configurable` and `DeadlineAware` | 5.2 |
| `src/PurgeResult.php` | Value object per purger per reason in a flush | 5.2 |
| `src/Checker.php`, `src/CheckReport.php` | Cache checker logic, testable with a fake HTTP client | C31 |
| `src/Cli/*` | WP-CLI `check`, `purge`, `config` | C31, C32, 5.6 |
| `src/SiteHealth.php` | One Site Health test | 11, F3, F4 |
| `src/Logger.php` | Prefixed error log lines; once-per-request keys | 11, P4 |
| `src/Http/*`, `src/ResponseHeaders.php` | Seams over the WordPress HTTP API and PHP's header functions | testing |
| `uninstall.php` | Removes nothing; says why | 6 |

## Data flow

```
reader posts comment
  core saves it ─ wp_insert_comment ─ ModerationListener ─ (approved?) PurgeQueue.add
  core set_comment_cookies @10 (expiring cookies, no consent)
  CookieSetter @20 ─ email cookie + marker ─ redirect to ?unapproved=&moderation-hash=

redirect request
  send_headers ─ NoStoreHeaders (no-store, X-Accel-Expires: 0)
  comment_form_before ─ HeldNotice (core validates the hash)

later request with the cookie (bypasses cache)
  send_headers ─ CookieSetter.maybeExpireEarly (no held comments left? expire)
  comment_form_before ─ HeldNotice (one count query on this post)

moderator acts (wp-admin, REST, XML-RPC, email link, cron)
  transition_comment_status / edit_comment ─ ModerationListener ─ PurgeQueue.add
  shutdown ─ fastcgi_finish_request ─ PurgeQueue.flush
    for each queued post: PurgeUrlCollector.forPost, merged per reason, deduped
    origin purgers (config order) then edge purgers, one call per reason
      each call: try/catch, deadline, PurgeResult
    do_action comments_through_cache_purged
```

## Decisions

### Rulings on the PRD's open questions

1. **Lawful basis wording (Q1).** The README states that the cookie serves a
   function the reader asked for (seeing their own comment), describes both
   cookies for a cookie policy, shows the one-line filter that turns the
   cookie off, and tells operators to confirm the wording with their own
   counsel. The code makes no legal ruling and has no locale switch: an
   operator who wants it off sets the filter. Reason: the legal question
   belongs to each operator, and a hidden locale default would be a legal
   ruling in code.

2. **Cloudflare bypass (Q2).** We ship a Cache Rule
   (`examples/cloudflare-cache-rule.txt`) with the expression
   `(http.cookie contains "comment_author_") or (http.cookie contains "wordpress_logged_in_") or (http.cookie contains "wp-postpass_") or (http.request.uri.query contains "unapproved=") or (http.request.uri.query contains "moderation-hash=")`
   and the setting "Bypass cache". Cache Rules are available on Free, Pro,
   Business and Enterprise (checked against Cloudflare's Cache Rules
   documentation, 12 November 2026); `http.cookie` is a standard string field
   with no plan restriction listed in the fields reference. Reason: one rule
   that works on every plan with Cache Rules. The rule must sit below any
   rule that makes HTML cacheable, because later matching Cache Rules
   override earlier ones.

3. **Early expiry (Q3).** Yes. When the plugin sets the cookie it also sets a
   marker cookie `comments_through_cache_<COOKIEHASH>` (HttpOnly, SameSite
   Lax, same lifetime) whose value is `<unix time>.<e|a>`: no personal data,
   just when it was set and whether only the email (`e`) or all three
   cookies (`a`) were set. On `send_headers`, if the marker is present, at
   least the grace period (default 300 s, filter
   `comments_through_cache_early_expiry_grace`) has passed, and a count query
   finds no held comment by that email on the site, the plugin expires its
   cookies and the marker. Without the marker the plugin never touches the
   cookies, so a reader who consented keeps core's long-lived cookies; when a
   reader consents on a later comment, the plugin removes its marker. The
   grace period keeps C2 working: an author whose comment was approved on
   arrival still gets live pages while the purge runs. Constant
   `COMMENTS_THROUGH_CACHE_EARLY_EXPIRY` turns it off. The query runs only on
   requests that already bypass the cache.

4. **Moderation-hash window (Q4).** The plugin does not reimplement the hash
   check. For C19a it calls core's `wp_get_unapproved_comment_author_email()`
   and compares the result with the referenced comment's email, so the
   window is whatever the installed core says. Verified against core source
   (`wp-includes/comment.php`): the function exists since 5.1, and since 5.7
   it honours the hash only within ten minutes of `comment_date_gmt`
   (`strtotime( $comment->comment_date_gmt . '+10 minutes' )`), so the window
   is the same in every supported version (6.4 and later). The notice does
   not state the window: the cookie keeps the comment visible after the link
   expires, so the number would only confuse. The no-store headers are sent
   whenever an `unapproved` or `moderation-hash` argument is present,
   regardless of whether the values are valid.

5. **nginx and HEAD (Q5).** The README and `examples/nginx-fastcgi-cache.conf`
   state that the cache key must include `$request_method` and give the
   snippet `fastcgi_cache_key "$scheme$request_method$host$request_uri";`.
   The file purger's default key template matches it. With the method in the
   key, `HEAD` and `GET` are stored under different keys (for proxy cache
   with `proxy_cache_convert_head on`, `$request_method` still reads `HEAD`),
   and the purger removes both files for each URL. Default `GET,HEAD` is
   therefore correct either way, and costs one extra `stat` when no `HEAD`
   entry exists.

6. **Cloudflare batch size (Q6).** Batches of 30, filterable through
   `comments_through_cache_cloudflare_batch_size`, clamped to 1 to 100.
   Cloudflare's purge documentation (checked 12 November 2026) lists 100
   operations per request on Free, Pro and Business and 500 on Enterprise,
   so 30 is well inside every plan's limit, and a typical purge set (five to
   fifteen URLs) is one request. The clamp stops at 100 so a filter cannot
   exceed the lowest plan's limit.

7. **Listing pages (Q7).** A filter only, no helper.
   `comments_through_cache_purge_urls` receives the list, the post ID and the
   reason; `examples/purge-listing-pages.php` shows home page and category
   archives. Reason: which listings show comment counts is theme-specific,
   and purging them on every comment is the cost lesson 10 warns about.

8. **Double purges (Q8).** A filter per purger:
   `comments_through_cache_purger_enabled` receives `true`, the purger name
   and the purger object. A site whose cache plugin already purges on
   comment approval returns `false` for the overlapping purger. Reason: the
   plugin cannot reliably detect every cache plugin, and the operator knows
   which one does what.

### Other decisions

- **Purger interface takes a reason.** `purge(array $urls, string $reason =
  'manual')`. The PRD signature has only the URLs, but `ActionPurger` must
  pass the reason to the `comments_through_cache_purge` action (5.5). The
  parameter is optional for callers.
- **Extra interfaces.** `Configurable` (purgers report missing settings for
  Site Health and `config`) and `DeadlineAware` (purgers that send several
  requests stop at the flush deadline; the queue passes its own clock).
- **Configuration errors at first use.** Purgers are built with whatever the
  constants hold and throw `ConfigurationException` from `purge()` when a
  required value is missing (F4). The queue catches it, logs once per
  request per purger, records a failed `PurgeResult` and runs the rest.
- **Batching across posts.** The queue is keyed by post (first reason
  wins), but a flush merges the purge sets of every queued post per reason,
  removes duplicates, and calls each purger once per reason. Purgers are the
  outer loop, so every origin call ends before the first edge call (C30)
  across the whole flush, not only within one post. Reason: a bulk action on
  many posts would otherwise send one Cloudflare request per post, run into
  the flush budget and the purge rate limit, and leave later posts stale.
  T35 still holds: each post's set is sent once. A `PurgeResult` therefore
  describes one purger and one reason, not one post.
- **Budget.** Checked before each purger call and, inside the HTTP and
  Cloudflare purgers, before each request, whose timeout is cut to the time
  left (at least one second). Dropped URLs are logged as a count, never a list.
- **Comment pages.** Pages are counted from approved top-level comments when
  threading is on (as core does), else all approved comments, and one extra
  page is purged in case a removal just emptied the last page. Capped at 10
  by default. Pretty permalinks use `comment-page-N/` (the rewrite base from
  `$wp_rewrite` when set); plain permalinks use `cpage=N`.
- **Allowed hosts.** The PRD both drops foreign hosts and suggests adding a
  `www` twin through the URL filter. Both hold through a second filter,
  `comments_through_cache_allowed_hosts`, which defaults to the home host.
- **HTTP purger success.** 2xx and 404 count as success: purge modules answer
  404 when the URL was not cached, which is the goal state.
- **Empty email.** A comment with no email gets no cookie (core cannot find
  held comments without one, and an empty `setcookie` value deletes the
  cookie). With `all`, the name and URL cookies are set only when non-empty.
- **Cookie attributes.** Core's names, path, domain and secure rule
  (`https` home URL). Core cookies stay readable by scripts, as core sets
  them; only the marker is HttpOnly.
- **Invalid settings.** An invalid `COOKIE_FIELDS` or `COOKIE_LIFETIME` is
  reported as a problem and the cookie code falls back to the default,
  because a broken constant must not stop readers seeing their comments.
- **Shutdown and PHP-FPM.** `fastcgi_finish_request()` is called at
  `shutdown` priority 100 only when the queue is not empty and not in
  WP-CLI. Filter `comments_through_cache_finish_request` turns it off for
  sites whose other shutdown handlers still print.
- **Checker hit detection.** A response is a cache hit when its render ID
  repeats one already seen. A missing render ID on the first fetch means
  debug mode is off, and the checker refuses with a clear message. Redirects
  are not followed, so the checker tests exactly the URL given.
- **Dependencies.** No runtime dependencies. Dev only: PHPUnit 10, PHPStan
  (level 8, with WordPress and WP-CLI stubs), PHP_CodeSniffer (PSR-12).
  `composer.lock` is not committed (reviewed for 0.1.0). The plugin has no
  runtime dependencies, so a lock would pin only dev tools, and it would be
  resolved on one PHP version while CI runs 8.1 to 8.3; letting each CI job
  resolve its own versions catches breakage from new tool releases early.
  Sites install the plugin by copying it or through their own project's
  lock, never through this one. This matches the programme's libraries.
- **Tests.** A stub layer in `tests/stubs/wordpress.php` implements the hook
  system and the few core functions used, records database writes, and
  every test asserts in its tear-down that none happened (T48). Each of the
  PRD acceptance tests T1 to T47 maps to at least one test method named
  after it.
