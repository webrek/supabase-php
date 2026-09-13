# supabase-php

[![Packagist Version](https://img.shields.io/packagist/v/webrek/supabase-php)](https://packagist.org/packages/webrek/supabase-php)
[![PHP Version](https://img.shields.io/packagist/dependency-v/webrek/supabase-php/php)](https://packagist.org/packages/webrek/supabase-php)
[![CI](https://github.com/webrek/supabase-php/actions/workflows/ci.yml/badge.svg)](https://github.com/webrek/supabase-php/actions/workflows/ci.yml)
[![Downloads](https://img.shields.io/packagist/dt/webrek/supabase-php)](https://packagist.org/packages/webrek/supabase-php)
[![License](https://img.shields.io/packagist/l/webrek/supabase-php)](LICENSE)

Framework-agnostic PHP client for [Supabase](https://supabase.com). PHP 8.3+.

## Status

**Status:** Stable (1.0). Available: Auth (user flows & admin), Edge Functions, Database (PostgREST), Storage, Realtime (postgres changes, broadcast & presence, with opt-in auto-reconnect).

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

## Storage

```php
// Buckets
$supabase->storage()->createBucket('avatars', ['public' => true]);
$bucket  = $supabase->storage()->getBucket('avatars');   // Bucket
$buckets = $supabase->storage()->listBuckets();          // Bucket[]

// Objects
$files = $supabase->storage();
$files->from('avatars')->upload('me.png', $bytesOrStream, ['contentType' => 'image/png', 'upsert' => true]);
$data  = $files->from('avatars')->download('me.png');    // raw bytes (capped at 50 MiB by default)
$items = $files->from('avatars')->list('folder');
$files->from('avatars')->move('me.png', 'old/me.png');
$files->from('avatars')->copy('me.png', 'copy.png');
$files->from('avatars')->remove(['old/me.png']);

// URLs
$signed = $files->from('avatars')->createSignedUrl('me.png', 60); // expires in 60s
$public = $files->from('avatars')->getPublicUrl('me.png');        // for public buckets
```

`upload()` accepts a string or a PSR-7 `StreamInterface`. Storage uses the key the client was
built with (anon respects Storage RLS policies; service_role bypasses them). `download()` caps at
50 MiB by default — raise the `maxBytes` argument or use a signed URL for larger files.

## Realtime

Subscribe to Postgres changes and exchange broadcast messages over a persistent
WebSocket connection. Realtime is connection-oriented and meant for long-lived
CLI / worker processes, not a typical web request.

Because there is no PSR standard for WebSockets, this library does **not** bundle
a WebSocket client. You provide a connection by implementing
`Supabase\Realtime\WebSocketConnection` (the SDK implements the Phoenix channels
protocol on top of it) and wiring a factory through `ClientOptions`:

```php
use Supabase\Client;
use Supabase\ClientOptions;
use Supabase\Realtime\WebSocketConnection;
use Supabase\Realtime\WebSocketConnectionFactory;

$client = new Client('https://YOUR-PROJECT.supabase.co', 'YOUR-ANON-KEY', new ClientOptions(
    webSocketFactory: new MyWebSocketConnectionFactory(),
));

$realtime = $client->realtime();

$realtime->connect();

$realtime->channel('room-1')
    ->onPostgresChanges('*', 'public', 'messages', null, function (array $change): void {
        // $change['eventType'], $change['new'], $change['old']
    })
    ->onBroadcast('cursor', function (array $message): void {
        // $message['payload']
    })
    ->subscribe();
$realtime->channel('room-1')->send('cursor', ['x' => 10, 'y' => 20]); // broadcast
$realtime->run(30.0); // blocking loop: dispatches messages and sends heartbeats for 30s
$realtime->disconnect();
```

Prefer your own loop? Call `poll(float $timeout)` repeatedly instead of `run()`,
and `stop()` to break out of `run()` from inside a callback.

### Broadcast from a web request (no WebSocket)

`broadcast()` posts a message through the Realtime REST endpoint, so an ordinary
HTTP request can notify connected clients without holding a socket open — no
`WebSocketConnectionFactory` needed:

```php
$supabase->realtime()->broadcast('room-1', 'order-updated', ['id' => 42]);
$supabase->realtime()->broadcast('room-1', 'order-updated', ['id' => 42], private: true);
```

Subscribers receive it as a regular broadcast event. The request carries the
client's bearer, so `withAccessToken($jwt)->realtime()->broadcast(...)` is
authorised as that user.

### Private channels and user tokens

Private channels are authorised by Realtime RLS policies on `realtime.messages`.
Pass `private: true` and act as a user: the client's access token (see
`withAccessToken()`) is sent with the join automatically.

```php
$realtime = $supabase->withAccessToken($jwt)->realtime();
$realtime->connect();
$realtime->channel('room-1', ['private' => true])
    ->onBroadcast('*', function (array $message): void { /* ... */ })
    ->subscribe();

// Long-running worker: rotate the token without reconnecting. Joined channels
// receive an access_token event; later joins carry the new token.
$realtime->setAuth($freshJwt);
```

### Presence

Track which clients are online and get notified when they join or leave. Register
callbacks with `onPresenceSync`, `onPresenceJoin`, and `onPresenceLeave` before
calling `subscribe()`, then call `track()` to broadcast your own state:

```php
$channel = $realtime->channel('room-1')
    ->onPresenceSync(function (): void {
        // Fired every time the full presence state is (re)synced — on join and
        // after every diff. Read the current snapshot with presenceState().
    })
    ->onPresenceJoin(function (string $key, array $currentPresences, array $newPresences): void {
        // A client started tracking; $newPresences lists their state payloads.
    })
    ->onPresenceLeave(function (string $key, array $currentPresences, array $leftPresences): void {
        // A client stopped tracking.
    })
    ->subscribe();

// Announce your own state (any serialisable array):
$channel->track(['user_id' => 42, 'online_at' => time()]);

// Read the current snapshot:
// array<string, list<array<string, mixed>>> — keyed by presence key; each
// presence carries a `presence_ref` field plus your tracked payload.
$state = $channel->presenceState();

// Stop broadcasting (leaves the channel's presence):
$channel->untrack();
```

### Auto-reconnect

By default the client does not reconnect after a dropped connection. Pass
`realtimeAutoReconnect: true` to make `run()` reconnect with exponential backoff
and automatically re-subscribe all active channels:

```php
$client = new Client('https://YOUR-PROJECT.supabase.co', 'YOUR-ANON-KEY', new ClientOptions(
    webSocketFactory: new MyWebSocketConnectionFactory(),
    realtimeAutoReconnect: true,
    realtimeReconnectBaseDelay: 1.0,  // initial retry delay in seconds (default)
    realtimeReconnectMaxDelay: 30.0,  // cap on retry delay in seconds (default)
));

$realtime = $client->realtime();
$realtime->connect();
// ... subscribe channels ...
$realtime->run(); // loops forever; reconnects + re-subscribes if the socket drops
```

When driving the loop yourself with `poll()`, reconnection is not automatic.
After a receive error, call `connect()` again and re-subscribe your channels.

### Implementing `WebSocketConnection`

`WebSocketConnection` is a small contract you back with any WebSocket client.
The URL passed to `connect()` already contains the `apikey` query parameter, so
never log it verbatim.

```php
use Supabase\Realtime\WebSocketConnection;
use Supabase\Realtime\WebSocketConnectionFactory;

final class MyWebSocketConnection implements WebSocketConnection
{
    private $client; // your WebSocket client of choice

    public function connect(string $url, array $headers = []): void
    {
        // open the connection to $url with $headers
    }

    public function send(string $data): void
    {
        // send a text frame
    }

    public function receive(float $timeoutSeconds): ?string
    {
        // return the next text frame, or null if $timeoutSeconds elapsed
    }

    public function close(int $code = 1000, string $reason = ''): void
    {
        // close the connection
    }

    public function isConnected(): bool
    {
        // report connection state
    }
}

final class MyWebSocketConnectionFactory implements WebSocketConnectionFactory
{
    public function create(): WebSocketConnection
    {
        return new MyWebSocketConnection();
    }
}
```

Calling `realtime()` without configuring a `webSocketFactory` throws a
`Supabase\Exception\RealtimeException`.

### Ready-made adapter: phrity/websocket

A concrete `WebSocketConnection` implementation backed by
[phrity/websocket](https://github.com/sirn-se/websocket-php) is included in
this package. Install the backing library and use it directly:

```bash
composer require phrity/websocket
```

```php
use Supabase\Client;
use Supabase\ClientOptions;
use Supabase\Realtime\PhrityWebSocketConnectionFactory;

$client = new Client('https://YOUR-PROJECT.supabase.co', 'YOUR-ANON-KEY', new ClientOptions(
    webSocketFactory: new PhrityWebSocketConnectionFactory(),
));
```

`PhrityWebSocketConnectionFactory` is a drop-in: it creates one
`PhrityWebSocketConnection` per `RealtimeClient`, handles the WebSocket
handshake, adds headers, applies a read timeout on every `receive()` call, and
auto-responds to WebSocket-level ping frames via phrity's `PingResponder`
middleware.

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

Sessions are stateless: the SDK never stores them — persist `accessToken`/`refreshToken`
yourself. `withSession()` (below) refreshes one on demand. Tokens are redacted in
`var_dump`/`print_r`/`json_encode` and in `AuthException` bodies, and `Session` cannot be
serialized. (PHP's `var_export()` cannot be intercepted — never `var_export()` a `Session`.)

### Acting as a user (Row Level Security)

On the server every request arrives with a different user's JWT. Bind it to a
sibling client instead of constructing a new one: the apikey, HTTP client and
all options are shared, only the bearer changes, and the original client is
untouched.

```php
use Supabase\Auth\Session;

// From a JWT you already hold (cookie, Authorization header, ...)
$asUser = $supabase->withAccessToken($jwt);
$todos  = $asUser->from('todos')->select()->execute();   // RLS applies as that user

// From a Session — refreshed first if it is expired or expires within 30s
$asUser = $supabase->withSession($session, onTokenRefreshed: function (Session $fresh): void {
    // persist $fresh->accessToken / $fresh->refreshToken (cookie, cache, ...)
});
```

`withSession()` refreshes proactively, before the first request, and only when
needed; it does not retry a request whose token expired mid-flight. A refresh
token that is no longer valid throws `AuthException`. `withAccessToken(null)`
returns a client that uses the apikey as bearer again.

### Verifying a JWT locally (JWKS)

`getClaims()` checks a token's signature against the project's public keys
(`/auth/v1/.well-known/jwks.json`) plus its `exp`/`nbf`, without calling
`/auth/v1/user`. Give it a PSR-16 cache so the key set is fetched once and
shared across requests:

```php
use Supabase\ClientOptions;

$supabase = new Client($url, $anonKey, new ClientOptions(
    jwksCache: $psr16Cache,   // any PSR-16 store: APCu, Redis, filesystem, ...
    jwksCacheTtl: 600,        // seconds (default)
));

$claims = $supabase->auth()->getClaims($jwt);   // AuthException if invalid, expired or not yet valid
$claims->sub;          // user id
$claims->role;         // 'authenticated'
$claims->email;
$claims->raw['aal'];   // any other claim
```

ES256 and RS256 keys are verified locally. Projects still on the legacy HS256
shared secret publish no public key: pass `jwtSecret` in `ClientOptions` to
verify locally, otherwise `getClaims()` falls back to one `/auth/v1/user`
request. Without a cache the key set is fetched once per process (memoised
for `jwksCacheTtl`); an unknown `kid` triggers a single refetch so rotated keys
are picked up. Requires `ext-openssl`.

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
