# Changelog

All notable changes to `jestays/laravel-centrifugo` are documented in this file.

## 1.0.2

Patch release. No breaking changes.

- `Centrifugo::broadcast()` now detects per-channel errors inside `result.responses` and throws
  `CentrifugoApiError` (naming the failing channel) instead of silently treating a partially failed
  broadcast as successful. The Laravel broadcaster and the service now share the same per-channel
  error detection.
- Documented that `Centrifugo` service methods accept Laravel-style channel names: unprefixed names
  map to the `public` namespace, while the `private-`/`presence-` prefixes target private and
  presence channels. The README now shows explicit public, private, and presence service examples.

## 1.0.1

Robustness and documentation review after the initial release. No breaking changes.

- Fixed the Centrifugo server configuration example in the README: Centrifugo 6 expects namespaces under
  `channel.namespaces`, not at the top level.
- Documented what the `public` namespace actually means (authenticated clients only, shared across
  applications, no Laravel authorization) and why `allow_subscribe_for_anonymous` should stay off.
- Documented the application and channel name restrictions enforced by the mappers.
- `TokenManager` now rejects negative TTL values with an `InvalidArgumentException` instead of silently
  issuing already-expired tokens; `0` still means "no expiration".
- Fixed `centrifugo:install` to guard against a missing console application instance when checking for
  `config:publish`.
- Added integration tests that run against a real Centrifugo 6 server (opt-in via
  `CENTRIFUGO_INTEGRATION_URL`) and a CI job that boots `centrifugo/centrifugo:v6` in Docker.
- Added PHPStan (level 6) with a `composer analyse` script and a CI step.
- Added a dedicated `composer audit` CI job for current dependencies, independent from the
  compatibility matrix.
- Added endpoint-level tests asserting that subscription tokens are refused for channels belonging to
  another application.

## 1.0.0

Initial release of `jestays/laravel-centrifugo`, an independent package based initially on
[`denis660/laravel-centrifugo`](https://github.com/denis660/laravel-centrifugo) v5.3.

- Targets Centrifugo 6+ and uses [`centrifugal/phpcent`](https://github.com/centrifugal/phpcent) as the
  low-level API and JWT client instead of a hand-rolled HTTP/JWT implementation.
- Introduces a modern namespace-based channel model: `<namespace>:<application>.<channel>`.
- Adds multi-application scoping through `ChannelMapper`, rejecting channels that belong to another
  application on a shared Centrifugo server.
- Adds scoped user identities (`<application>:<id>`) through `UserMapper`.
- Adds proper `PresenceChannel` support, separate from private channel authorization.
- Adds a `TokenManager` and dedicated connection/subscription token HTTP endpoints.
- Drops legacy `$` private channel support entirely; there is a single, modern channel naming strategy.
- Requires PHP >= 8.2 and Laravel 11, 12, or 13.
