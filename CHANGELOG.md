# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-11-14

### Added

- Commenter cookie for anonymous readers who did not tick the consent box, so
  a held comment survives a refresh and the reader bypasses the page cache.
  Email cookie only by default, one day lifetime, clamped to 5 minutes to 30
  days.
- Early expiry: the cookie is cleared once the reader has no held comments
  left on the site. A cookie set by the reader's consent is never touched.
- `Cache-Control: private, no-store, max-age=0` and `X-Accel-Expires: 0` on
  the post-comment redirect URL.
- "Your comment is held" notice above the comment form, and the
  `comments_through_cache_held_notice()` template function.
- Targeted purge of the article, its comment pages and its comments feed on
  approve, unapprove, spam, trash, restore, delete and edit. Queued per post,
  merged into one call per purger at shutdown, origin before edge,
  time-boxed.
- Purgers: action (default), http (purge endpoint), file (nginx cache files)
  and cloudflare (purge by URL in batches of 30). Each can be turned off
  through a filter.
- WP-CLI commands `check`, `purge` and `config`.
- Site Health test, debug headers, and example nginx, Varnish and Cloudflare
  rules.

[Unreleased]: https://github.com/IndianOpinion/comments-through-cache/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/IndianOpinion/comments-through-cache/releases/tag/v0.1.0
