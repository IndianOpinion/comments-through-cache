# Contributing

Thank you for helping. Small, focused pull requests are easiest to review.

## Setup

You need PHP 8.1 or later and Composer.

```sh
git clone https://github.com/IndianOpinion/comments-through-cache.git
cd comments-through-cache
composer install
```

No WordPress install is needed. The tests run against a small stub layer in
`tests/stubs/wordpress.php`.

## Tests and checks

```sh
composer check      # all of the below
vendor/bin/phpunit  # unit tests
vendor/bin/phpstan analyse
vendor/bin/phpcs    # PSR-12
vendor/bin/phpcbf   # fix what phpcs can fix
```

CI runs the same checks on PHP 8.1, 8.2 and 8.3. Write code that runs on 8.1:
no readonly classes, no DNF types, no `true` type, no constants in traits.

## Style

- PSR-12, `declare(strict_types=1);` in every file under `src/`.
- Typed exceptions; never silence errors with `@`.
- Escape every output. Never log cookie values, email addresses, comment
  content or tokens.
- Prose: short sentences, plain words, British spelling. No em dashes.

## Pull requests

- Branch from `main`. One change per pull request.
- Add or update a test for every behaviour change. Map it to a case in
  `docs/PRD.md` where one applies.
- Update `README.md` and `CHANGELOG.md` (Unreleased) when behaviour or
  configuration changes. Record design decisions in `docs/ARCHITECTURE.md`.
- A sign-off line is not required.

By contributing you agree that your work is released under the MIT licence.
