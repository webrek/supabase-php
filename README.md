# supabase-php

Framework-agnostic PHP client for [Supabase](https://supabase.com). PHP 8.3+.

## Status

**Available:** Auth (user flows & admin), Edge Functions, Database (PostgREST)
**Planned:** Storage, Realtime

## Installation

```bash
composer require webrek/supabase-php
```

You also need any PSR-18 HTTP client and PSR-17 factories. If you do not already
have one, install Guzzle and Nyholm PSR-7:

```bash
composer require guzzlehttp/guzzle nyholm/psr7
```

The client auto-discovers them via `php-http/discovery`. You can also inject your
own via `ClientOptions`.

For production deployments, install without dev dependencies and with an
optimized, authoritative classmap:

```bash
composer install --no-dev --optimize-autoloader --classmap-authoritative
```

## Quick start

```php
use Supabase\Client;

$supabase = new Client('https://YOUR-PROJECT.supabase.co', 'YOUR-ANON-KEY');
```

## Edge Functions

```php
$result = $supabase->functions()->invoke('hello', [
    'body' => ['name' => 'world'],
]);
```

## Database (PostgREST)

```php
// Select with filters and ordering
$rows = $supabase->from('users')
    ->select('id, email')
    ->eq('active', true)
    ->order('created_at', ascending: false)
    ->limit(10)
    ->execute();

// Select a single row
$user = $supabase->from('users')->select('*')->eq('id', 1)->single()->execute();

// maybeSingle(): returns the first matching row, or null if there are none.
// Note: unlike supabase-js it does NOT error when several rows match — it returns the first.
$maybe = $supabase->from('users')->select('*')->eq('email', 'a@b.com')->maybeSingle()->execute();

// Insert (returns rows when you chain ->select())
$created = $supabase->from('users')->insert(['email' => 'a@b.com'])->select()->execute();

// Update
$supabase->from('users')->update(['active' => false])->eq('id', 5)->execute();

// Delete
$supabase->from('users')->delete()->eq('id', 5)->execute();

// Upsert
$supabase->from('users')
    ->upsert(['id' => 1, 'email' => 'updated@b.com'])
    ->execute();

// Count rows
$total = $supabase->from('users')->select('*')->eq('active', true)->count();

// RPC (remote procedure call)
$result = $supabase->rpc('add', ['a' => 1, 'b' => 2])->execute();

// Advanced filters: in(), or(), full-text search, ranges
$posts = $supabase->from('posts')
    ->select('id, title')
    ->in('status', ['published', 'featured'])
    ->or('author_id.eq.1,author_id.eq.2')
    ->textSearch('body', 'php & sdk')
    ->range(0, 19)
    ->execute();
```

The Database module supports the full set of PostgREST filtering operators — `eq`, `neq`, `gt`, `gte`, `lt`, `lte`, `like`, `ilike`, `is`, `in`, `contains`, `containedBy`, `rangeGt`, `rangeGte`, `rangeLt`, `rangeLte`, `rangeAdjacent`, `overlaps`, `textSearch`, `not`, `or`, `match`, and `filter` (escape hatch) — plus modifiers (`order`, `limit`, `range`, `single`, `maybeSingle`), `count()`, and error handling via `PostgrestException`.

## Auth (GoTrue)

```php
// Sign up (returns null if the project requires email confirmation)
$session = $supabase->auth()->signUp('a@b.com', 'password');

// Sign in
$session = $supabase->auth()->signInWithPassword('a@b.com', 'password');
$session->accessToken;   // string (redacted in dumps; never logged)
$session->user->id;      // string

// Get / update the user behind a JWT
$user = $supabase->auth()->getUser($session->accessToken);
$user = $supabase->auth()->updateUser($session->accessToken, ['data' => ['name' => 'Ada']]);

// Refresh and sign out
$session = $supabase->auth()->refreshSession($session->refreshToken);
$supabase->auth()->signOut($session->accessToken);

// OTP, password reset, OAuth URL
$supabase->auth()->signInWithOtp(['email' => 'a@b.com']);
$supabase->auth()->resetPasswordForEmail('a@b.com');
$url = $supabase->auth()->getOAuthSignInUrl('github', ['redirect_to' => 'https://app.test/cb']);
```

Sessions are stateless: the SDK never stores or refreshes them automatically — persist
`accessToken`/`refreshToken` yourself. Tokens are redacted in `var_dump`/`print_r`/`json_encode`
and in `AuthException` bodies, and `Session` cannot be serialized. (PHP's `var_export()` cannot
be intercepted — never `var_export()` a `Session`.)

### Admin API (service_role)

Construct the client with your **service_role** key (never expose it to browsers):

```php
$admin = (new Client('https://YOUR-PROJECT.supabase.co', 'YOUR-SERVICE-ROLE-KEY'))->auth()->admin();

$user  = $admin->createUser(['email' => 'a@b.com', 'password' => 'pw']);
$user  = $admin->getUserById($user->id);
$user  = $admin->updateUserById($user->id, ['user_metadata' => ['role' => 'member']]);
$users = $admin->listUsers(page: 1, perPage: 50);   // User[]
$admin->inviteUserByEmail('new@b.com');
$link  = $admin->generateLink(['type' => 'magiclink', 'email' => 'a@b.com']);
$admin->deleteUser($user->id);
```

## Injecting your own HTTP client

```php
use Supabase\Client;
use Supabase\ClientOptions;

$supabase = new Client('https://YOUR-PROJECT.supabase.co', 'YOUR-ANON-KEY', new ClientOptions(
    httpClient: $myPsr18Client,
    requestFactory: $myPsr17Factory,
    streamFactory: $myPsr17Factory,
));
```

## Error handling

Operations return data directly and throw typed exceptions on failure:

```php
use Supabase\Exception\SupabaseException;

try {
    $supabase->functions()->invoke('broken');
} catch (SupabaseException $e) {
    $e->getStatusCode();   // HTTP status
    $e->getErrorCode();    // Supabase error code, if any
    $e->getResponseBody(); // raw response body
}
```

## Security

- **HTTPS enforced.** The SDK rejects any `$url` that does not use `https`, except
  for `http://localhost` and `http://127.0.0.1` (local Supabase dev). This prevents
  your API key and tokens from being sent in cleartext.
- **Disable HTTP redirects on your PSR-18 client.** The SDK sends your `apikey` in
  a custom header that is not stripped on cross-origin redirects. The SDK rejects
  3xx responses, but a client that follows redirects internally can leak the key
  before the SDK sees the response. Set `allow_redirects: false` (Guzzle) or the
  equivalent for your client.
- **Set a request timeout.** The SDK does not impose one; without it a stalled
  endpoint can hang the process indefinitely.
- **Do not dump or serialize credential-holding objects.** `Client`, `Transport`,
  and `ClientOptions` hold your API key. `var_export()` and some crash reporters
  can expose raw values even though `serialize()` is blocked and `var_dump()` is
  redacted.
- **Exception bodies may contain sensitive data.** `SupabaseException::getResponseBody()`
  returns the raw response body, which may include tokens or PII. Do not log or
  expose it verbatim.
- **Inject a hardened client in production.** Auto-discovery picks up whatever PSR-18
  client is installed. For production, pass an explicit client with redirects off,
  timeout set, and TLS verification on via `ClientOptions`:

  ```php
  $httpClient = new \GuzzleHttp\Client([
      'allow_redirects' => false,
      'timeout'         => 10,
      'verify'          => true,
  ]);
  new Client($url, $key, new ClientOptions(httpClient: $httpClient));
  ```

For full guidance and vulnerability reporting, see [SECURITY.md](SECURITY.md).

## License

MIT
