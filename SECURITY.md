# Security Policy

## Supported Versions

Only the latest release on the `main` branch receives security fixes.

## Reporting a Vulnerability

Please **do not** open a public GitHub issue for security vulnerabilities.

Use GitHub's built-in private vulnerability reporting instead:

1. Go to the repository: <https://github.com/webrek/supabase-php>
2. Click the **Security** tab.
3. Click **Report a vulnerability**.
4. Fill in the details and submit.

The maintainer will acknowledge the report within a few business days and work
with you on a coordinated disclosure timeline.

## Secure usage

The SDK enforces HTTPS and rejects non-secure URLs, but several security
properties depend on correct configuration of the PSR-18 client you provide
(or that is auto-discovered).

### Disable HTTP redirect following on your PSR-18 client

The SDK sends your project `apikey` in a custom HTTP header on every request.
Standard HTTP clients (e.g. Guzzle) strip `Authorization` and `Cookie` on
cross-origin redirects but do **not** strip a custom `apikey` header, so a
redirect to an untrusted host could leak your API key. The SDK rejects any 3xx
response it receives, but it cannot prevent a client that follows redirects
internally before returning a response. Configure your client to not follow
redirects:

```php
// Guzzle example
$httpClient = new \GuzzleHttp\Client(['allow_redirects' => false]);
```

### Set a request timeout on your PSR-18 client

The SDK does not impose a request timeout. Without one, a stalled or
slowloris-style endpoint can hang the process indefinitely. Set a sensible
timeout on your client:

```php
// Guzzle example
$httpClient = new \GuzzleHttp\Client(['timeout' => 10, 'connect_timeout' => 5]);
```

### Do not dump or serialize credential-holding objects

Do not pass a configured `Client`, `Transport`, or `ClientOptions` instance to
`var_dump()`, `var_export()`, `serialize()`, `print_r()`, or reflection-based
crash reporters. These objects hold your API key and any custom headers.
`serialize()` is blocked (throws `\LogicException`), and `var_dump()`/`print_r()`
redact sensitive fields, but `var_export()` and some crash-reporting libraries
can still expose raw values.

### `SupabaseException::getResponseBody()` may contain sensitive data

The raw response body returned by `getResponseBody()` can include tokens, error
details, or PII (especially in future Auth and Storage responses). Do not log or
expose it verbatim; extract only the fields you need.

### Prefer injecting an explicit, hardened PSR-18 client in production

Auto-discovery selects whatever PSR-18 client happens to be installed. For
production workloads, inject an explicit client configured with redirects
disabled, a request timeout, and TLS verification on:

```php
use Supabase\Client;
use Supabase\ClientOptions;

$httpClient = new \GuzzleHttp\Client([
    'allow_redirects' => false,
    'timeout'         => 10,
    'connect_timeout' => 5,
    'verify'          => true,
]);

$supabase = new Client(
    'https://YOUR-PROJECT.supabase.co',
    'YOUR-ANON-KEY',
    new ClientOptions(httpClient: $httpClient),
);
```
