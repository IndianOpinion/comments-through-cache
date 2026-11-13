# Security policy

## Reporting a vulnerability

Email hello@indianopinion.org with "security" in the subject. Please do not
open a public issue for a security problem.

Include what you found, how to reproduce it, and the version affected. We
reply within five working days and aim to ship a fix within 30 days of
confirming the problem. We credit reporters in the CHANGELOG unless you ask
us not to.

## Supported versions

Only the latest release gets security fixes.

## Scope notes

- The plugin sets a commenter cookie that holds an email address. That is by
  design and documented in the README. Reports are welcome when the plugin
  exposes more than the README says.
- Any client can send a `comment_author_` cookie to skip the page cache. This
  is true of every WordPress cache setup; see the README for rate limits.
