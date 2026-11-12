# comments-through-cache: product requirements

Status: draft for build 0.1.0. Audience: the engineer who builds it without
ever seeing the site it came from.

A WordPress must-use plugin. It does two things on a site whose HTML is served
from a page cache (nginx FastCGI cache, Varnish, a cache plugin, or a CDN such
as Cloudflare):

- A reader who posts a comment that is held for moderation keeps seeing it,
  marked as held, on every refresh, and is told plainly that it is held.
- When a moderator approves a comment (or removes an approved one), the
  article page changes for every reader straight away, through a targeted
  purge of that article's URLs, not a full cache purge and not a wait for the
  cache TTL.

## 1. Problem

WordPress shows a reader their own held comment in one of two ways only:

1. Through the commenter cookies (`comment_author_*`, set by core after a
   comment is posted), which carry the author's email address. Core then
   includes held comments by that email in the comment query.
2. Through the redirect URL core sends after posting, which carries
   `unapproved=<comment id>` and `moderation-hash=<hash>` query arguments.
   Core honours that URL only for a short time after posting.

Core sets the cookies only when the reader ticks the "save my name, email and
website" consent box. When the box is not posted, core actively expires the
cookies. Many themes replace the comment form's field set and drop that box
without meaning to. On such a site no anonymous commenter ever gets the
cookies.

The result, observed on a production news site with moderation on for every
comment: the reader posts, lands on the redirect URL, sees "awaiting
moderation", refreshes the clean article URL, and their comment is gone. They
assume it was lost and post it again, or leave.

A page cache makes both halves worse:

- The clean article URL is served from cache, so even a reader who does have
  the cookies sees the cached copy unless the cache bypasses on that cookie.
- An approved comment appears to other readers only when the cached copy
  expires. With a ten minute origin TTL and a CDN TTL on top, that is often
  ten to twenty minutes after the moderator clicked Approve. Moderators
  think approval failed and approve again, or edit the comment.
- The redirect URL carries one reader's held comment. If a cache stores it,
  any visitor with the same URL sees that comment.

Turning the cache off is not an answer. Measured on the same site: an
uncached article render took about 1.3 s; a cache hit took about 5 ms.

## 2. Goals and non-goals

### Goals

- G1. An anonymous commenter whose comment is held sees it on every refresh
  of the article for a bounded time (default one day), on any theme that
  uses the core comment submission handler, whether or not the theme shows
  the consent box.
- G2. That reader gets a live (uncached) page while the cookie lasts, because
  the cookie name matches the bypass rule the page cache already honours.
- G3. The redirect URL that carries a held comment is never stored by any
  cache layer the site controls.
- G4. Approving, unapproving, spamming, trashing, restoring, deleting or
  editing a published comment purges exactly the URLs that show that comment,
  origin cache first, then CDN.
- G5. A plain "your comment is held" notice above the comment form.
- G6. Purge failure never blocks moderation and never breaks the page.
- G7. Store as little personal data in the browser as the feature needs.
- G8. Work as a must-use plugin with no admin screen and no database writes.

### Non-goals

- N1. Not a page cache. It does not cache anything.
- N2. Not a full cache purge tool. It never purges everything.
- N3. Does not purge listing pages (home page, archives, widgets) that show
  comment counts or "latest comments". Those follow their cache TTL. A filter
  lets a site add them.
- N4. Does not change who is moderated or how. Moderation rules stay core's.
- N5. Does not replace the theme's comment form, its markup or its styling.
- N6. Does not support custom comment submission handlers that bypass core's
  submission endpoint (for example an AJAX form that writes comments through
  its own endpoint and never fires core's cookie action). See section 10.
- N7. No settings screen in 0.1.0. Configuration is constants and filters.
- N8. Does not manage CDN or nginx configuration. It documents what they must
  honour and ships example snippets.

## 3. Users and setting

- Installed by a site operator or developer, usually as a must-use plugin
  (a single loader file in `wp-content/mu-plugins/` that requires the plugin
  file), or as a normal plugin.
- Runs on WordPress sites with comments open and, typically, moderation on
  ("Comment must be manually approved" or "comment author must have a
  previously approved comment").
- Sits behind one or more page cache layers:
  - origin cache: nginx FastCGI cache, nginx proxy cache, Varnish, or a
    WordPress page cache plugin;
  - edge cache: a CDN that caches HTML (for example a Cloudflare cache rule
    that caches HTML, or a CDN that honours `s-maxage`).
- Also useful with no page cache at all: the cookie fix alone keeps the held
  comment on refresh.
- Readers: anonymous commenters. Logged-in users are already handled by core
  and by every sane cache bypass, so the plugin leaves them alone.
- Moderators: approve and reject in wp-admin, through the REST API, the
  mobile apps, XML-RPC, email moderation links, or anti-spam rechecks run by
  cron. All of these go through core's comment status transition, which is
  what the plugin listens to.

## 4. Behaviour

Terms used below:

- "Held" means core status `0` (unapproved). "Approved" means `1`. "Spam" and
  "trash" are core's statuses of those names.
- "Commenter cookies" means core's cookies whose names start with
  `comment_author_` followed by core's per-site cookie hash: the name cookie,
  the email cookie (`comment_author_email_<hash>`) and the URL cookie
  (`comment_author_url_<hash>`). Path, domain and secure flag are core's.
- "Bypass cookie" means any commenter cookie. The page cache treats the
  presence of any cookie whose name starts with `comment_author_` as "do not
  serve from cache, do not store".
- "Purge set" means the URLs the plugin purges for one post (case C20).
- "Redirect URL" means the post-comment URL that carries `unapproved` and
  `moderation-hash`.

### 4.1 Setting the cookie after a comment is posted

The plugin listens to core's "set comment cookies" action, which core fires
after a comment is saved by its submission endpoint. Core's own handler runs
at the default priority and, with no consent, sends expiring cookies. The
plugin's handler runs at a later priority so that its `Set-Cookie` headers
come after core's. Browsers apply `Set-Cookie` headers in order, so the later
one wins.

- C1. Anonymous reader, consent box absent or unticked, comment held: the
  plugin sets the commenter cookies it is configured to set (C7) with the
  short lifetime (C6). The reader now sees their held comment on refresh and
  bypasses the page cache.
- C2. Anonymous reader, consent box absent or unticked, comment approved on
  arrival (for example a returning author whose earlier comment was approved):
  the plugin still sets the cookies. The author then gets a live page at once,
  even if the purge is slow or fails.
- C3. Anonymous reader, consent ticked: core has already set its long-lived
  cookies by the reader's choice. The plugin does nothing.
- C4. Logged-in user: the plugin does nothing. Core shows a logged-in user
  their own held comments by user ID, and the logged-in cookie already
  bypasses the cache.
- C5. Comment saved as spam or trash (by an anti-spam plugin or a blocklist):
  the plugin sets no cookie. A spammer must not be handed a cache bypass. The
  comment is not shown to them either way.
- C6. Lifetime: default 86 400 seconds (one day). Filterable. The value is
  clamped to between 300 seconds and 30 days; a value outside the range is
  clamped and, in debug mode, logged. The lifetime applies only to cookies
  the plugin sets. It does not touch core's lifetime for readers who
  consented.
- C7. Fields: by default the plugin sets only the email cookie. That is the
  only cookie core needs to show a held comment, and its name matches the
  bypass prefix. The name and URL cookies exist to prefill the form, which a
  reader who did not tick the box did not ask for. A site can choose `all` to
  set all three (for example when the theme has no consent box and the owner
  wants prefill). See section 8.
- C8. The comment object is missing or invalid in the action arguments: do
  nothing.
- C9. Headers already sent when the action fires (a plugin printed output
  early): do nothing, log once in debug mode. The comment is saved; only the
  preview is lost.
- C10. The submission is rejected before saving (duplicate, flood, missing
  required field, closed comments): core stops before the cookie action. The
  plugin does nothing.
- C11. A site disables the behaviour per comment through a filter that
  receives the comment, the user and the consent flag and returns a boolean.
- C12. The site is served over HTTPS: cookies carry the secure flag, as core
  does when the home URL is HTTPS. Over HTTP they do not.

### 4.2 What the page cache must honour

The plugin cannot make a cache honour anything. It documents these rules,
ships examples for nginx FastCGI cache, Varnish and Cloudflare cache rules,
and its checker (C31) tests them.

- C13. Bypass on cookie: a request whose `Cookie` header contains a cookie
  name starting with `comment_author_` is neither served from cache nor
  stored. The same rule normally also covers `wordpress_logged_in_` and
  `wp-postpass_`.
- C14. Bypass on redirect URL: a request whose query string contains
  `unapproved=` or `moderation-hash=` is neither served from cache nor
  stored. This must be a cache rule, not only a response header, because
  many origin caches are configured to ignore upstream `Cache-Control`.
- C15. Never store a response that carries `Set-Cookie`. Otherwise the
  cache replays one visitor's cookie to every later visitor.
- C16. Only `GET` and `HEAD` are cacheable. The comment `POST` and its
  redirect response are never stored.

### 4.3 Response headers on the redirect URL

- C17. When the current front-end request has an `unapproved` or
  `moderation-hash` query argument, the plugin sends
  `Cache-Control: private, no-store, max-age=0` and `X-Accel-Expires: 0`.
  The second header is honoured by nginx unless it is listed in the cache's
  ignored headers. Both are a second line of defence behind C14. The list of
  query argument names is filterable.
- C18. On any other request the plugin sends no cache headers. It does not
  compete with the site's cache header policy.

### 4.4 The held-comment preview and notice

The comment list itself is core's: core includes the reader's held comments
in the query when it can resolve their email from the cookie or from a valid
redirect URL, and the theme labels a held comment however it does. The plugin
adds a notice, because a label inside a long list is easy to miss.

- C19a. Redirect URL, valid hash, the referenced comment exists, belongs to
  the post being shown and is still held: print the notice immediately before
  the comment form, through core's "before comment form" action.
- C19b. Clean article URL, reader has the email cookie, and that email has at
  least one held comment on this post: print the same notice. This costs one
  indexed query, and only on requests that already bypass the cache. On by
  default; a filter turns it off.
- C19c. The referenced comment is approved already, spam, trash, missing, or
  on another post: no notice.
- C19d. Invalid or expired hash with no cookie: no notice. Core shows nothing
  either.
- C19e. The notice text is translatable and filterable. Default: "Thank you.
  Your comment has been received and will appear once it has been approved."
  It is printed as a paragraph with `role="status"` and a stable class name
  the plugin documents, escaped on output. The filter may return an empty
  string to suppress it.
- C19f. The theme does not call core's comment form function, so the "before
  comment form" action never fires: no notice. The plugin exposes a template
  function a theme can call where it wants the notice (section 5).
- C19g. The notice never appears on a cacheable response, because both
  triggers (redirect URL, bypass cookie) are bypass conditions. If the cache is
  misconfigured and stores it anyway, that is the failure C31 detects.

### 4.5 Purge on approval and other transitions

- C20. Purge set for a post. Built from the post's public permalink, made
  absolute with the home URL's scheme and host:
  1. the permalink itself;
  2. the comment pagination URLs of the post, when comment paging is on: one
     URL per comment page that exists, capped at 10 pages by default
     (filterable);
  3. the post's comments feed URL;
  4. anything a site adds through the purge URL filter (for example a plain
     text or AMP twin of the article, or the apex and `www` variants when the
     cache key includes the host and both hosts are served).
  Duplicates are removed. Fragments are stripped. Relative URLs from the
  filter are made absolute; anything not on the site's own host is dropped
  and logged in debug mode.
- C21. The post is not public (draft, pending, private, scheduled, trashed,
  a post type that is not publicly queryable, or password protected): the
  purge set is empty and nothing is purged. A password protected post is
  served past the cache by its own cookie, and its anonymous cached copy does
  not show comments.
- C22. A comment is posted and approved on arrival: queue the purge set of
  its post.
- C23. A comment is posted and held, spam or trash: no purge. The public page
  has not changed.
- C24. Status transition from anything to approved (held to approved, spam to
  approved, trash restored to approved): queue a purge.
- C25. Status transition from approved to anything (held, spam, trash, or
  permanent delete, which core reports as a transition to `delete`): queue a
  purge. A comment removed for abuse must vanish from the public page now.
- C26. Transition where old and new status are equal, or where neither is
  approved (held to spam, spam to trash): no purge.
- C27. An approved comment is edited by a moderator: queue a purge.
- C28. A reply is approved: purge its post. Threading does not change the
  purge set.
- C29. Batching. Purges are queued during the request, keyed by post, and
  flushed once at shutdown. Bulk approving 50 comments on three posts sends
  three purge sets, once each. Where the server can finish the response
  early (PHP-FPM), the flush runs after the response is sent so the
  moderator's screen does not wait for it.
- C30. Purge order. With more than one purger configured, the origin cache
  purgers run before CDN purgers. Purging the CDN first lets it refetch the
  stale origin copy and cache it again.

### 4.6 Failure modes

| Failure | What happens | Open or closed | Why |
|---|---|---|---|
| F1. Purger HTTP error, timeout, or non-success API answer | Logged; the purged action reports failure; other purgers still run | Open | Moderation must never fail because a cache is down. The page updates at TTL. |
| F2. Purger throws | Caught at the queue; logged; next purger runs | Open | Same. |
| F3. No purger configured | The action purger runs (it only fires a hook); Site Health shows a recommendation | Open | A site with its own cache plugin may already listen. |
| F4. Purger misconfigured (missing token, unreadable cache path) | Typed configuration exception at first use, caught, logged once per request; Site Health shows it as critical | Open | Same, but loud. |
| F5. Cookie cannot be set (headers sent) | C9 | Open | The comment is saved; only the preview is lost. |
| F6. Cache ignores the bypass cookie | Reader sees the cached page without their comment | n/a | Outside the plugin. The checker reports it. |
| F7. Cache stores the redirect URL | One reader's held comment is visible to anyone with the URL until TTL | n/a | Outside the plugin; C17 headers reduce the chance; the checker reports it. |
| F8. Purge set empty (C21) | Nothing sent | Closed | Nothing public changed. |
| F9. Purge time budget exceeded (section 9) | Remaining URLs dropped and logged | Open | A moderator request must not hang. |

### 4.7 Checking the cache (observability case)

- C31. A WP-CLI command checks a given article URL through whatever layers sit
  in front of the URL it is given (point it at the public URL to test the CDN
  path, or at the origin to test the origin cache). It needs debug mode
  (section 11), in which every PHP render sends a header with a unique render
  ID. A cached response repeats the stored ID; a live render has a new one.
  The command:
  1. fetches the URL twice with no cookie and reports whether the second
     response was a cache hit (informational only);
  2. fetches it twice with a dummy `comment_author_email_<hash>` cookie and
     fails if the render IDs repeat (C13 broken);
  3. fetches it twice with `?unapproved=0&moderation-hash=x` and fails if
     the render IDs repeat or the response lacks `no-store` (C14 or C17
     broken);
  4. fetches it twice with no cookie and reports any `Set-Cookie` on a
     response that was a cache hit (C15 broken);
  5. prints one line per check, exit code 0 when all pass, 1 otherwise.
  The dummy cookie value is a fixed placeholder, never a real address.
- C32. A WP-CLI command purges one post's purge set on demand and prints the
  result per purger. Useful after changing a cache rule.

## 5. Public interface

Namespace `IndianOpinion\CommentsThroughCache`. Hook names use the prefix
`comments_through_cache_`. Constants use the prefix `COMMENTS_THROUGH_CACHE_`.
All names below are proposals for the builder; keep them unless a WordPress
convention forces a change, and record any change in ARCHITECTURE.md.

### 5.1 Files

- `comments-through-cache.php`: plugin header (name, description, version,
  requires at least 6.4, requires PHP 8.1, licence MIT), loads the classes
  without Composer, calls the bootstrap.
- A documented one-line mu-plugin loader that requires the plugin file.
- `uninstall.php` (section 6).

### 5.2 Classes

- `Plugin`: `register(): void` adds every hook once; safe to call twice.
- `Config`: reads constants and filters once per request, validates them, and
  exposes typed getters. Throws `ConfigurationException` on invalid values
  that cannot be clamped.
- `CookieSetter`: the C1 to C12 behaviour.
- `NoStoreHeaders`: C17 and C18.
- `HeldNotice`: C19a to C19g. Also a static `render(): string` used by the
  template function.
- `PurgeUrlCollector`: `forPost(int $postId): list<string>` (C20, C21).
- `PurgeQueue`: `add(int $postId, string $reason): void`, `flush(): void`
  (C29, C30, F1 to F4, F9).
- `Purger` interface:
  - `purge(list<string> $urls): PurgeResult`
  - `layer(): string`, returning `origin` or `edge` (for C30 ordering)
  - `name(): string`
- Built-in purgers:
  - `ActionPurger` (layer `origin`): fires the purge action with the URL list.
    The default. Lets an existing cache plugin or site code do the purge.
  - `HttpPurger` (layer `origin`): sends one request per URL to a configured
    endpoint template, for nginx with a cache purge module, Varnish, or any
    purge endpoint. Method `PURGE` by default, `GET` allowed. The endpoint
    template takes the tokens `{scheme}`, `{host}`, `{path}` and `{query}`.
    Sends the site's host in the `Host` header.
  - `FileCachePurger` (layer `origin`): for nginx FastCGI or proxy cache
    without a purge module. Computes the cache file name from a configured key
    template (tokens `{scheme}`, `{method}`, `{host}`, `{uri}`), the MD5 of the
    key, and the configured `levels`, and removes that file if it exists. It
    computes one key per method in a configured list (default `GET` and
    `HEAD`), so every key the cache can hold for the URL is covered. It refuses
    to remove anything outside the configured cache directory (path is
    resolved and checked) and anything that is not a regular file.
  - `CloudflarePurger` (layer `edge`): purge by URL through the Cloudflare
    API, zone ID and API token from constants only. Sends URLs in batches
    (default 30 per request, filterable). Treats any response without a
    success flag as failure.
- `PurgeResult`: value object with the purger name, URLs attempted, URLs
  succeeded, and error messages.
- `Checker`: the C31 logic, separated from the WP-CLI wrapper so it can be
  unit tested with a fake HTTP client.
- Exceptions: `ConfigurationException`, `PurgeException`, both extending a
  package base exception.

### 5.3 Template function

- `comments_through_cache_held_notice(): void` prints the notice where a theme
  wants it (C19f). It prints nothing when C19a or C19b do not hold. When the
  theme calls it, the plugin does not also print on the form action for that
  request.

### 5.4 Filters

| Filter | Arguments | Default | Purpose |
|---|---|---|---|
| `comments_through_cache_should_set_cookie` | bool, comment, user, consent | per C1 to C5 | C11 |
| `comments_through_cache_cookie_lifetime` | int seconds, comment | 86400 | C6 |
| `comments_through_cache_cookie_fields` | string `email` or `all`, comment | `email` | C7 |
| `comments_through_cache_no_store_args` | list of query argument names | `unapproved`, `moderation-hash` | C17 |
| `comments_through_cache_show_held_notice` | bool, post ID, context `redirect` or `cookie` | true | C19a, C19b |
| `comments_through_cache_held_notice_text` | string, post ID | C19e text | C19e |
| `comments_through_cache_purge_urls` | list of URLs, post ID, reason | C20 | add twins, listings |
| `comments_through_cache_max_comment_pages` | int | 10 | C20 |
| `comments_through_cache_should_purge` | bool, comment, new status, old status | per C22 to C28 | override |
| `comments_through_cache_purgers` | list of `Purger` | from config | add or replace purgers |
| `comments_through_cache_cloudflare_batch_size` | int | 30 | `CloudflarePurger` |

### 5.5 Actions

| Action | Arguments | When |
|---|---|---|
| `comments_through_cache_purge` | list of URLs, reason | fired by `ActionPurger` |
| `comments_through_cache_purged` | list of `PurgeResult` | after every flush |
| `comments_through_cache_cookie_set` | comment ID, fields set, lifetime | after C1 or C2 |

The reason string is one of `comment_approved_on_post`, `status_to_approved`,
`status_from_approved`, `approved_comment_edited`, `manual`.

### 5.6 WP-CLI

- `wp comments-through-cache check <url>`: C31.
- `wp comments-through-cache purge <post-id>`: C32.
- `wp comments-through-cache config`: prints the effective configuration with
  secrets masked (token shown as set or not set, never the value).

## 6. Data

- Database: nothing. No options, no tables, no post or comment meta, no
  transients.
- Browser: the commenter cookie(s) of C7, with core's names, path, domain and
  secure flag, and the plugin's lifetime.
- Disk: nothing written. `FileCachePurger` only removes cache files it
  computed and verified (5.2).
- Logs: purge failures and configuration errors through PHP's error log, in
  debug mode or on failure (section 11). Never a cookie value, email address
  or API token.
- Retention: the cookie expires after its lifetime in the reader's browser.
  The plugin cannot reach it earlier.
- Uninstall: `uninstall.php` exists for convention and removes nothing,
  because nothing is stored. It states that in a comment. Cookies already set
  expire on their own.

## 7. Configuration and sensible defaults

Constants in `wp-config.php`. Filters override constants. Defaults make the
plugin safe with no configuration: cookie fix, notice, no-store headers, and
the action purger.

| Constant | Type | Default | Notes |
|---|---|---|---|
| `COMMENTS_THROUGH_CACHE_COOKIE_LIFETIME` | int seconds | 86400 | clamped 300 to 2 592 000 |
| `COMMENTS_THROUGH_CACHE_COOKIE_FIELDS` | `email` or `all` | `email` | C7 |
| `COMMENTS_THROUGH_CACHE_NOTICE` | bool | true | turns off C19a and C19b |
| `COMMENTS_THROUGH_CACHE_PURGERS` | comma list of `action`, `http`, `file`, `cloudflare` | `action` | order within a layer is kept; origin before edge always |
| `COMMENTS_THROUGH_CACHE_HTTP_ENDPOINT` | URL template | none | required for `http` |
| `COMMENTS_THROUGH_CACHE_HTTP_METHOD` | `PURGE` or `GET` | `PURGE` | |
| `COMMENTS_THROUGH_CACHE_FILE_PATH` | absolute directory | none | required for `file` |
| `COMMENTS_THROUGH_CACHE_FILE_LEVELS` | string like `1:2` | `1:2` | must match the cache's levels |
| `COMMENTS_THROUGH_CACHE_FILE_KEY` | key template | `{scheme}{method}{host}{uri}` | must match the cache key |
| `COMMENTS_THROUGH_CACHE_FILE_METHODS` | comma list | `GET,HEAD` | |
| `COMMENTS_THROUGH_CACHE_CF_ZONE_ID` | string | none | required for `cloudflare` |
| `COMMENTS_THROUGH_CACHE_CF_API_TOKEN` | string | none | required for `cloudflare`; a token scoped to cache purge on one zone |
| `COMMENTS_THROUGH_CACHE_HTTP_TIMEOUT` | int seconds | 3 | per request |
| `COMMENTS_THROUGH_CACHE_PURGE_BUDGET` | int seconds | 5 | whole flush (F9) |
| `COMMENTS_THROUGH_CACHE_DEBUG` | bool | false | render ID header, debug logs |

Behaviour with and without a CDN (document this table in the README):

| Setup | Cookie and notice | Bypass rules to add | Purgers |
|---|---|---|---|
| No page cache | works | none | none needed (action purger is harmless) |
| Cache plugin only | works if the plugin bypasses on `comment_author_` (most do) | check the plugin's cookie list | `action` if the plugin listens, or its own hook |
| Origin cache only (nginx, Varnish) | works once C13 to C16 are in the cache config | C13 to C16 at origin | `file` or `http` |
| CDN that does not cache HTML, origin cache behind it | works | C13 to C16 at origin only | `file` or `http` |
| CDN that caches HTML, origin cache behind it | works only if the CDN also honours C13 and C14 | C13 to C16 at origin and at the edge | origin purger, then `cloudflare` |
| CDN that caches HTML, no origin cache | works only if the CDN honours C13 and C14 | C13, C14 at the edge | `cloudflare` |
| CDN that caches HTML and cannot bypass on a cookie | the redirect page works; refresh shows the cached copy until edge TTL | C14 at the edge; keep edge HTML TTL short | `cloudflare` |

The README must warn: a CDN rule that overrides the edge TTL for HTML may
ignore origin `Cache-Control`, including `no-store`. The redirect URL then
needs an explicit bypass rule at the edge (C14), not just the header.

## 8. Security and privacy

- P1. Cookie contents. Core's commenter cookies hold the author's name, email
  address and website in clear text (URL encoded). They are not HttpOnly,
  because themes read them in JavaScript to prefill the form, so any script on
  the page, including third-party scripts, can read them. They are sent with
  every request to the site's cookie path, including through the CDN.
- P2. Minimisation. Default `email` sets only the email cookie, the one core
  needs for the preview. The lifetime is one day, not core's roughly eleven
  months. The plugin never sets cookies for a reader who is logged in, for
  spam or trash, or when the reader consented (core already did).
- P3. Lawful basis. The plugin sets a cookie the reader did not opt into
  through the consent box. The case for it is that the cookie is needed to
  deliver what the reader just asked for (seeing their own submitted
  comment). Whether that meets the strictly necessary exemption in the
  operator's jurisdiction (for example PECR and UK GDPR, or the ePrivacy
  rules in the EU) is a legal question for each operator. The README must say
  so, describe the cookie for a cookie policy, and show the filter that
  turns it off. This PRD makes no legal ruling.
- P4. No logging of personal data. The plugin never logs cookie values, email
  addresses or comment content. The README tells operators not to put the
  `Cookie` header in access logs or CDN log exports.
- P5. Forged bypass. Any client can send a `comment_author_` cookie and get an
  uncached page. This is true of every WordPress cache setup and is not made
  worse by the plugin. The README recommends request rate limits on uncached
  requests at the origin.
- P6. Redirect URL exposure. Anyone holding a valid redirect URL sees that
  held comment while core honours the hash. The plugin keeps it out of caches
  (C14, C17) but cannot stop the reader sharing it.
- P7. Secrets. The Cloudflare token is read from a constant, never stored in
  the database, never printed by the config command, never logged, and never
  sent anywhere but the Cloudflare API over HTTPS.
- P8. Output. The notice text is escaped. The template function prints only
  plugin markup.
- P9. Purge endpoints. The HTTP purger only calls the configured endpoint. The
  README tells operators to restrict purge endpoints to local or private
  addresses. URLs from filters that are not on the site's host are dropped
  (C20), so a filter cannot make the plugin purge another site.
- P10. File purger safety. Only computed file names inside the configured,
  resolved cache directory are removed; symlinks out of it and non-files are
  refused.
- P11. No admin surface in 0.1.0, so no nonces or capability checks are needed
  beyond WP-CLI, which runs with the shell user's rights.

## 9. Performance budget

- A cache hit never reaches PHP, so the plugin adds nothing to it.
- Uncached anonymous request without the cookie or redirect arguments: no
  database queries added, under 0.2 ms of plugin work.
- Uncached request with the cookie: at most one extra indexed query (C19b).
- Comment post: two or three extra `Set-Cookie` headers, no queries.
- Moderation action: building the purge set is in memory plus at most one
  count of comments for pagination. The flush runs after the response where
  possible. HTTP timeout 3 s per request, 5 s for the whole flush (F9).
- Cost to accept: each commenter gets uncached pages for the cookie lifetime
  across the whole cookie path. With one day and normal commenting rates this
  is a small share of traffic. Sites with very many commenters can lower the
  lifetime.

## 10. Compatibility

- PHP 8.1, 8.2, 8.3. WordPress 6.4 and later. Single site and multisite (per
  site; cookie hash and home URL are per site).
- Themes: works with any theme that submits through core's comment endpoint
  and renders comments with core's comment query. Documented limitations:
  - A theme that replaces the comment form fields and drops the consent box:
    this is the main case the plugin fixes.
  - A theme that does not call core's comment form function: no automatic
    notice; use the template function (C19f).
  - A theme or plugin that posts comments through its own AJAX endpoint and
    does not fire core's cookie action: no cookie, no preview. Out of scope.
  - A theme that does not label held comments: the reader sees their comment
    without a "held" label; the notice (C19a, C19b) still says it is held.
  - A theme whose JavaScript prefills the form from the name and URL cookies:
    prefill works only with `all` (C7).
- Caches: nginx FastCGI and proxy cache (bypass maps, optional purge module),
  Varnish, WordPress cache plugins that honour `comment_author_` cookies,
  Cloudflare. Other CDNs through the action purger or a custom `Purger`.
- Anti-spam plugins: run before the cookie action and set spam status, which
  C5 respects.

## 11. Observability

- Debug mode (`COMMENTS_THROUGH_CACHE_DEBUG`): every PHP render sends
  `X-Comments-Through-Cache-Render: <random id>` and, on bypass requests,
  `X-Comments-Through-Cache: cookie` or `redirect`. Off in production by
  default, because it adds a header to every cached page.
- The purged action carries every `PurgeResult`, so a site can log or count
  them.
- Error log lines on purge failure and configuration error, prefixed
  `[comments-through-cache]`, one line per failure, no personal data.
- Site Health: one test reporting the configured purgers, any configuration
  error (critical), and a recommendation when only the action purger is
  active and nothing listens to it.
- WP-CLI `check`, `purge` and `config` (section 5.6).

## 12. Acceptance tests

Unit tests run against a stub layer in `tests/stubs/` with a fake HTTP client
and a temporary directory for the file purger.

| Test | Case | Assertion |
|---|---|---|
| T1 | C1 | anonymous, no consent, held: email cookie set after core's expiring cookie, lifetime 86400, core's name, path, domain |
| T2 | C2 | anonymous, no consent, approved on arrival: cookie set and purge queued |
| T3 | C3 | consent true: plugin sets nothing |
| T4 | C4 | logged in: plugin sets nothing |
| T5 | C5 | spam and trash: no cookie, no purge |
| T6 | C6 | filter returns 10 and 99 999 999: clamped to 300 and 2 592 000; debug log line |
| T7 | C7 | `email` sets one cookie; `all` sets three |
| T8 | C8 | null comment: no cookie, no error |
| T9 | C9 | headers sent: no cookie, one debug log line, no exception |
| T10 | C10 | rejected submission (cookie action never fired): nothing happens |
| T11 | C11 | filter returns false: no cookie |
| T12 | C12 | HTTPS home URL: secure flag on; HTTP: off |
| T13 | C13 | example nginx and Varnish snippets contain the cookie bypass; checker fails against a fake cache that ignores the cookie |
| T14 | C14 | checker fails against a fake cache that stores the redirect URL |
| T15 | C15 | checker reports a fake cache hit that carries `Set-Cookie` |
| T16 | C16 | example snippets mark only `GET` and `HEAD` cacheable |
| T17 | C17 | request with `unapproved` or `moderation-hash`: both headers sent; filter adds a name |
| T18 | C18 | plain request: no cache headers sent |
| T19 | C19a | valid redirect, held comment on this post: notice printed once, escaped, `role="status"` |
| T20 | C19b | cookie only, held comment on this post: notice printed; filter off: not printed |
| T21 | C19c | referenced comment approved, spam, missing, or on another post: no notice |
| T22 | C19d | bad hash, no cookie: no notice |
| T23 | C19e | text filter applied and escaped; empty string suppresses |
| T24 | C19f | template function prints notice; form action then prints nothing |
| T25 | C19g | the notice is only produced on requests matching a bypass condition |
| T26 | C20 | purge set: permalink, comment pages up to cap, comments feed, filter additions; deduped; fragment stripped; foreign host dropped |
| T27 | C21 | draft, private, scheduled, password protected, non-public type: empty set, no purger called |
| T28 | C22 | posted approved: one purge for the post |
| T29 | C23 | posted held, spam, trash: no purge |
| T30 | C24 | held, spam, trash to approved: purge |
| T31 | C25 | approved to held, spam, trash, delete: purge |
| T32 | C26 | equal statuses, held to spam, spam to trash: no purge |
| T33 | C27 | approved comment edited: purge; held comment edited: no purge |
| T34 | C28 | approved reply: purges the post's set |
| T35 | C29 | 50 approvals on three posts in one request: three purge sets, each sent once, at shutdown |
| T36 | C30 | `cloudflare` listed before `file`: file purger still runs first |
| T37 | C31 | checker exit codes and lines for all pass, each single failure, and debug mode off (refuses with a clear message) |
| T38 | C32 | purge command calls every purger and prints each result |
| T39 | F1 | HTTP 500 and timeout from one purger: logged, failure result, next purger runs, no exception escapes |
| T40 | F2 | purger throws: caught, logged, next purger runs |
| T41 | F3 | no configuration: action purger fires the purge action with the URL list |
| T42 | F4 | `cloudflare` without token: configuration exception caught, Site Health critical, other purgers run |
| T43 | F9 | slow fake purger: flush stops at budget, remainder logged |
| T44 | 5.2 FileCachePurger | key template and levels produce the documented file name for `GET` and `HEAD`; file removed; path outside directory and symlink refused |
| T45 | 5.2 CloudflarePurger | 65 URLs give three requests of 30, 30 and 5; bearer token sent; response without success flag is failure |
| T46 | 5.2 HttpPurger | endpoint template expanded, method and Host header correct |
| T47 | P4, P7 | no log line or config output contains an email address, cookie value or token |
| T48 | 6 | plugin performs no database writes during any test |

## 13. Open questions

Answer each in docs/ARCHITECTURE.md.

1. Lawful basis wording (P3): the README text that describes the cookie for
   a cookie policy, and whether the default should be "off until the owner
   turns it on" in some locales. This needs the operator's legal review, not
   the builder's ruling; the builder documents the trade-off.
2. Which Cloudflare plans and rule types can bypass the cache on a cookie,
   and the exact rule expression to ship as the example. Verify against
   current public Cloudflare documentation.
3. Can the plugin expire its own cookie early, on the reader's next visit,
   once that email has no held comments left on the site? It would need to
   tell its own short-lived cookie apart from a consented one.
4. Is the core moderation-hash time window stable across supported
   WordPress versions, and should the notice (C19a) state how long the
   redirect link works?
5. Whether nginx stores `HEAD` under its own key when the key includes the
   method and HEAD conversion is on. Default `GET,HEAD` is safe either way;
   confirm and document.
6. Cloudflare purge-by-URL batch limit per plan; default 30 is assumed.
7. Should a site be able to ask for listing pages to be purged on approval
   through a ready-made helper (home page, the post's category), or is the
   filter enough?
8. Whether WordPress cache plugins that already purge on comment approval
   need a guard against double purging when they also listen to the action
   purger.

## 14. Lessons from production

1. Never assume core sets the commenter cookies. A theme that replaces the
   comment form fields can drop the consent box, and then core expires the
   cookies on every post.
2. Run the cookie handler after core's. Core sends expiring cookies first; the
   later `Set-Cookie` wins in the browser.
3. The cookie that shows a held comment is the same cookie that opens the
   cache bypass. Make it short-lived and set only what is needed.
4. Test the refresh of the clean URL, not the redirect page. The redirect page
   always works; the refresh is where the comment disappears.
5. Do not rely on `Cache-Control: no-store` to keep the redirect URL out of
   the cache. Origin caches are often set to ignore upstream `Cache-Control`.
   Bypass on the query argument in the cache itself.
6. Never let a cache store a response that sets a cookie. It replays one
   reader's cookie to the next.
7. Purge the origin before the CDN, or the CDN refetches the stale copy.
8. Purge when a comment leaves approved, not only when it enters it. A
   spammed or abusive comment must disappear now.
9. Purge every representation of the article that shows comments, not only
   the HTML permalink: comment pages, the comments feed, any text or AMP twin.
10. Purge the article, not the site. Listings keep their time-based expiry; a
    purge per comment on a busy site would rebuild the home page all day.
11. Do not give spam a cache bypass. No cookie for spam or trash.
12. A purge failure must never block or slow moderation. Queue, flush at
    shutdown, time-box, log, move on.
13. Tell the reader plainly, above the form, that the comment is held. A
    "held" label inside a long comment list gets missed and readers post
    again.
14. Treat any purge helper as optional. Check it exists before calling it;
    without it, do nothing and let the TTL work.
15. Never log the `Cookie` header or anything from the commenter cookies.
