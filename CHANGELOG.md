# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
While the version is below `1.0.0`, minor releases may introduce additive,
backward-compatible features and patch releases contain fixes.

## [Unreleased]

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

[Unreleased]: https://github.com/webrek/supabase-php/compare/v0.5.0...HEAD
[0.5.0]: https://github.com/webrek/supabase-php/compare/v0.4.0...v0.5.0
[0.4.0]: https://github.com/webrek/supabase-php/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/webrek/supabase-php/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/webrek/supabase-php/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/webrek/supabase-php/releases/tag/v0.1.0
