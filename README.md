# supabase-php

Framework-agnostic PHP client for [Supabase](https://supabase.com). PHP 8.3+.

## Status

**Only Edge Functions is available in the current release.**
Database (PostgREST), Auth, and Storage are planned and will be added in future
milestones.

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

## Sessions (design principle)

This SDK is stateless: it will never store or refresh sessions internally.
When Auth lands, it will return `Session`/`User` objects; persisting them will be
the caller's responsibility.

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
