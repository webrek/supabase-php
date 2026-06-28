# Contributing

Thanks for your interest in improving `supabase-php`. This document explains how
to set up the project and the standards a change must meet.

## Requirements

- PHP 8.3 or newer
- [Composer](https://getcomposer.org/)

## Setup

```bash
git clone https://github.com/webrek/supabase-php.git
cd supabase-php
composer install
```

## Development workflow

The project uses test-driven development. Write a failing test first, then the
minimal code to make it pass.

Run the checks the CI runs:

```bash
composer test    # Pest test suite
composer stan    # PHPStan at level max
composer lint    # Laravel Pint (code style; auto-fixes)
```

All three must pass before a pull request can be merged. The CI matrix runs on
PHP 8.3 and 8.4.

To reproduce the CI environment from scratch (fresh dependencies, no lockfile):

```bash
rm -rf vendor composer.lock && composer install && composer test
```

## Standards

- **Tests**: every behavior change ships with tests that verify real behavior
  (not just mocks). Keep the test output clean — no stray warnings.
- **Static analysis**: PHPStan runs at level `max`. Add precise types and array
  shapes rather than suppressions.
- **Style**: Laravel Pint enforces the code style. Run `composer lint` before
  committing.
- **Types**: `declare(strict_types=1);` in every file; `final` for concrete
  classes; `readonly` value objects where appropriate.
- **Security**: never log or expose credentials. Classes that hold an API key or
  token must redact it in debug output and block serialization. Response bodies
  stored on exceptions must have sensitive fields redacted.
- **Dependencies**: the package depends only on PSR HTTP interfaces and
  `php-http/discovery`. Do not add a concrete HTTP or WebSocket client to
  `require` — these are provided by the consumer.
- **Commits**: use clear, imperative commit subjects (Conventional Commits style,
  e.g. `feat(storage): ...`, `fix(realtime): ...`, `docs: ...`).

## Pull requests

1. Open an issue first for anything beyond a small fix, so the approach can be
   discussed before you invest time.
2. Keep pull requests focused — one logical change per PR.
3. Update the README and `CHANGELOG.md` (`Unreleased` section) when behavior or
   the public API changes.
4. Do not introduce backward-incompatible changes to the public API without
   prior discussion.

## Reporting security issues

Please do not open public issues for vulnerabilities — see [SECURITY.md](SECURITY.md).
