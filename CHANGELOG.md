# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
While the version is below `1.0.0`, minor releases may introduce additive,
backward-compatible features and patch releases contain fixes.

## [Unreleased]

### Added
- **Per-request user context**: `Client::withAccessToken(?string $jwt)` returns a
  sibling client that authenticates as the given user (`Authorization: Bearer <jwt>`)
  so Row Level Security applies as that user. The apikey, HTTP client and every
  option are shared; `null` reverts to the apikey. The original client is unchanged.
- **Session binding with on-demand refresh**: `Client::withSession(Session $session,
  ?callable $onTokenRefreshed = null, int $expiryMargin = 30)` refreshes the session
  first when it is expired or about to expire, hands the new `Session` to the
  callback so it can be persisted, and returns a client authenticated as that user.
- `Session::isExpired(int $marginSeconds = 0, ?int $now = null)`.
- `ClientOptions::withAccessToken()` and `ClientOptions::withHttp()` immutable copies.
- **Local JWT verification**: `GoTrueClient::getClaims(string $jwt): Claims` verifies
  an access token against the project's JWKS (ES256 / RS256) or the configured
  `jwtSecret` (HS256) and returns a typed `Claims` object, with no round-trip to
  `/auth/v1/user`. Legacy HS256 tokens without a secret fall back to one request.
  The key set is memoised per process and, via `ClientOptions(jwksCache:)`, in any
  PSR-16 cache (`jwksCacheTtl`, default 600 s); an unknown `kid` refetches once.
- `Supabase\Auth\Claims`, `Supabase\Auth\Jwks` (with `clearProcessCache()`), and the
  internal `Supabase\Auth\JwtVerifier`.
- `ClientOptions`: `jwksCache`, `jwksCacheTtl`, `jwtSecret` (redacted in dumps).
- **Realtime broadcast over HTTP**: `RealtimeClient::broadcast(string $topic, string $event,
  array $payload, bool $private = false)` posts to `/realtime/v1/api/broadcast`, so a web
  request can notify subscribers without opening a WebSocket. Authorised as the client's
  bearer (the user's JWT on a `withAccessToken()` client).
- **Private channels**: `channel($name, ['private' => true])` joins with `private: true`;
  the client's access token is sent with the join automatically (an explicit `access_token`
  param still wins). `Channel::isPrivate()`, `Channel::setAccessToken()`, `Channel::pushAccessToken()`.
- `RealtimeClient::setAuth(?string $jwt)` rotates the user token in place: joined channels
  receive an `access_token` event and later joins carry the new token.
- **OAuth with PKCE from the server**: `Supabase\Auth\Pkce` (`generate()`, `fromVerifier()`,
  verifier redacted in dumps), `getOAuthSignInUrl($provider, $options, ?Pkce $pkce)` adds
  `code_challenge` / `code_challenge_method=s256`, and
  `GoTrueClient::exchangeCodeForSession(string $authCode, string $codeVerifier): Session`
  completes the flow (`grant_type=pkce`).
- `GoTrueClient::signInWithIdToken(string $provider, string $idToken, array $options = []): Session`
  (`grant_type=id_token`) for Google / Apple ID tokens.

- `FilterBuilder::scalar(): int|float|string|bool|null` returns the bare value of an RPC
  that yields a single result (`rpc('add', [...])->scalar()`). `execute()` is for row
  sets and returns `null` for a scalar body, which the README example used to rely on.

### Fixed
- `RealtimeClient::run()` can be called again after a dropped connection when
  auto-reconnect is on (for example when driving it in `run($seconds)` slices): it
  resumes the reconnect back-off instead of throwing "not connected". It still
  refuses to start before `connect()` and after `disconnect()`.

### Changed
- New requirements: `psr/simple-cache ^3.0` (interface only) and `ext-openssl`.
- `RealtimeClient` accepts optional `clock` and `sleeper` closures (trailing
  constructor arguments) so the reconnect loop can be tested deterministically.
- `Client::realtime()` no longer throws when no `webSocketFactory` is configured — the
  factory is only required by `connect()`, so `broadcast()` works without one.
  `RealtimeClient`'s first constructor argument is now nullable and it accepts
  `transport` and `accessToken`.

### Fixed
- `PhrityWebSocketConnection::close()` falls back to a normal closure (1000) for a
  close code outside `0..4999`, the range phrity/websocket 3.8 enforces.

## [1.0.0] - 2026-06-28

First stable release. The SDK covers Edge Functions, Database (PostgREST), Auth
(GoTrue), Storage, and Realtime (postgres changes, broadcast, and presence) with
opt-in auto-reconnect. The public API is now stable and follows Semantic
Versioning. The change-delivery paths (Database, Auth, Storage, and Realtime
postgres-changes and presence) are verified end-to-end against a real Supabase
stack in CI.

### Added
- **Realtime — Presence**: `Channel` now supports `onPresenceSync(callable)`,
  `onPresenceJoin(callable)`, `onPresenceLeave(callable)`, `track(array $payload)`,
  `untrack()`, and `presenceState(): array<string, list<array<string, mixed>>>`.
  The server sends `presence_state` (full sync) and `presence_diff` (join/leave
  deltas) Phoenix frames; the `Presence` class merges them and fires the registered
  callbacks. Presence is opt-in: passing `presence_key` in channel params or
  registering any presence callback enables it in the `phx_join` config.
- **Realtime — Auto-reconnect**: opt-in via
  `ClientOptions(realtimeAutoReconnect: true, realtimeReconnectBaseDelay: 1.0,
  realtimeReconnectMaxDelay: 30.0)`. When enabled, `run()` detects connection
  drops, waits with exponential backoff (capped at `realtimeReconnectMaxDelay`),
  reconnects, and re-subscribes all channels that were `joined` or `joining`.
  `poll()` users remain responsible for their own reconnection. The channel
  status callback is preserved across reconnects, and a client's tracked
  presence is re-sent after re-joining.

## [0.5.2] - 2026-06-28

### Added
- **Realtime**: `PhrityWebSocketConnection` and `PhrityWebSocketConnectionFactory`
  — a ready-made `WebSocketConnection` adapter backed by `phrity/websocket ^3.0`.
  Install the backing library (`composer require phrity/websocket`) and pass
  `new PhrityWebSocketConnectionFactory()` as `ClientOptions(webSocketFactory:)`.
  The adapter handles the WebSocket handshake, per-receive read timeouts, and
  auto-ping-response via phrity's `PingResponder` middleware. `phrity/websocket`
  is listed under `suggest` in `composer.json` so it is not imposed on consumers
  who bring their own transport.

### Fixed
- **Realtime**: `onPostgresChanges` callbacks now receive the change in the same
  shape as the official `realtime-js` client — `eventType`, `new`, `old` (plus
  `schema`, `table`, `commit_timestamp`, `errors`) — instead of the raw Phoenix
  wire payload (`type`, `record`, `old_record`). This matches the documented
  README usage. Verified end-to-end against a real Supabase stack.

## [0.5.1] - 2026-06-28

### Security
- Exception response-body redaction is now **fail-safe across all exception
  types**: a baseline set of credential fields (`apikey`, `access_token`,
  `refresh_token`, `token`, `password`, `secret`) is redacted by default, so
  even `PostgrestException` and `FunctionsException` no longer expose common
  credentials in `getResponseBody()`. Each subclass adds its own extra fields on
  top (Auth and Storage keep their existing keys).
- `ClientOptions` now implements `JsonSerializable`, so `json_encode()` no longer
  exposes the raw access token.

### Added
- Configurable Realtime heartbeat interval via
  `ClientOptions(realtimeHeartbeatInterval: ...)`.

### Changed
- `AuthHttp` and `StorageHttp` now throw a typed exception when a successful
  (2xx) response body is malformed JSON, instead of silently returning `[]`.
- `StorageHttp` gained a `__debugInfo()` for parity with the other HTTP helpers.

### Fixed
- `FileApi::createSignedUrl()` now throws `StorageException` when the response
  lacks the expected `signedURL` field, instead of returning a wrong base-only URL.

## [0.5.0] - 2026-06-28

### Added
- **Realtime** module. Subscribe to Postgres changes and exchange broadcast
  messages over a persistent WebSocket connection (Phoenix channels protocol,
  vsn 1.0.0), for long-lived CLI / worker processes.
  - `Client::realtime()` → `RealtimeClient`: `connect()`, `poll()`, blocking
    `run()`, `stop()`, `disconnect()`, with automatic heartbeats.
  - `Channel`: `onPostgresChanges()` (routed by the server-assigned id) and
    `onBroadcast()` / `send()`.
  - `WebSocketConnection` and `WebSocketConnectionFactory` interfaces — the SDK
    ships no concrete WebSocket client; the consumer provides the transport
    (reference adapter documented in the README), injected via
    `ClientOptions(webSocketFactory:)`. No new runtime dependency.
  - `RealtimeException` with credential redaction; the apikey is redacted in
    debug output and the client is non-serializable.

## [0.4.0] - 2026-06-28

### Added
- **Storage** module. `Client::storage()` → `StorageClient` (bucket CRUD:
  create/get/list/update/delete/empty) and `->from(bucket)` → `FileApi`
  (upload/download/list/remove/move/copy, plus `createSignedUrl`,
  `createSignedUrls`, `createSignedUploadUrl`, `uploadToSignedUrl`,
  `getPublicUrl`).
- `Transport` request body widened to accept a PSR-7 `StreamInterface` (uploads).
- `StorageException` redacts signed-URL tokens from response bodies.

## [0.3.0] - 2026-06-28

### Added
- **Auth (GoTrue)** module. `Client::auth()` → `GoTrueClient` (sign up, sign in
  with password / OTP / OAuth URL, get user, refresh, sign out, verify, recover,
  update user, resend) plus `auth()->admin()` → `AdminClient` (service-role user
  management: create/get/update/delete/list users, invite, generate link).
- Typed `Session` and `User` value objects with token redaction.
- `AuthException` redacts token fields from response bodies.

## [0.2.0] - 2026-06-27

### Added
- **Database (PostgREST)** module. `Client::from(table)` → `QueryBuilder`
  (select/insert/upsert/update/delete) → `FilterBuilder` (filter operators,
  modifiers, `execute()`, `count()`); `Client::rpc()` for stored procedures.

## [0.1.0] - 2026-06-27

### Added
- Foundation: framework-agnostic `Client` and `ClientOptions` built on PSR-18 /
  PSR-17 with `php-http/discovery` (no hard HTTP client dependency), `Transport`,
  and a typed `Supabase\Exception\*` hierarchy.
- **Edge Functions** module. `Client::functions()` → `FunctionsClient::invoke()`.

[Unreleased]: https://github.com/webrek/supabase-php/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/webrek/supabase-php/compare/v0.5.2...v1.0.0
[0.5.2]: https://github.com/webrek/supabase-php/compare/v0.5.1...v0.5.2
[0.5.1]: https://github.com/webrek/supabase-php/compare/v0.5.0...v0.5.1
[0.5.0]: https://github.com/webrek/supabase-php/compare/v0.4.0...v0.5.0
[0.4.0]: https://github.com/webrek/supabase-php/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/webrek/supabase-php/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/webrek/supabase-php/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/webrek/supabase-php/releases/tag/v0.1.0
