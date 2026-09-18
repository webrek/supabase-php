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

## Mutation testing

`composer mutate` runs Pest's mutation testing over `src/` (needs PCOV or
Xdebug) and fails below the floor set by `--min` in the script. The floor is a
ratchet: when the score goes up, raise `--min` in `composer.json` in the same
PR; never lower it. Keep `--everything`: code without any covering test counts
against the score on purpose. Mutants that only survive because OpenSSL
tolerates non-minimal DER, or that flip a default value nobody should assert,
are not worth a test.

## Running integration tests

Integration tests exercise every module against a real Supabase stack running
in Docker: Database (PostgREST, including RLS as a signed-in user), Auth (GoTrue,
including local JWT verification and the PKCE magic-link flow through the mail
catcher), Storage, and Realtime (postgres changes, presence, REST broadcast and
private channels over a real WebSocket).  They are **automatically skipped**
when the required env vars are absent, so `composer test` remains green in
environments without a running stack.

### Requirements

- Docker (Docker Desktop or OrbStack) running
- [Supabase CLI](https://supabase.com/docs/guides/cli) installed locally
  (`brew install supabase/tap/supabase`)
- Env vars exported in your shell — `supabase status -o env` prints them all:

```bash
eval "$(supabase status -o env)"
export SUPABASE_URL="$API_URL"
export SUPABASE_ANON_KEY="$ANON_KEY"
export SUPABASE_SERVICE_ROLE_KEY="$SERVICE_ROLE_KEY"
export SUPABASE_JWT_SECRET="$JWT_SECRET"      # optional: local HS256 verification in the JWKS test
export SUPABASE_MAIL_URL="$MAILPIT_URL"       # optional: the PKCE test reads the magic link from Mailpit
```

Without the two optional vars those assertions are skipped, nothing fails.

### Starting the local stack

```bash
# First time — generate config.toml (not committed) and apply migrations.
# The first start pulls the Supabase images (a few GB).
supabase init
supabase start

# After changing anything under supabase/migrations/
supabase db reset
```

The migrations under `supabase/migrations/` are applied automatically by
`supabase start`: `20260628000001_integration.sql` creates
`public.integration_items` (database and postgres-changes tests) and
`20260917000001_user_context_and_private_channels.sql` creates
`public.private_notes` behind per-user RLS plus the `realtime.messages` policies
that authorise private channels for authenticated users only.

### Running the tests

```bash
# Integration suite only (requires the stack to be running)
composer test:integration

# Full suite — integration tests are skipped when SUPABASE_URL is unset
composer test
```

### Stopping the stack

```bash
supabase stop --no-backup
```

## Reporting security issues

Please do not open public issues for vulnerabilities — see [SECURITY.md](SECURITY.md).
