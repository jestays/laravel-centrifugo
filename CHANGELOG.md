# Changelog

All notable changes to `jestays/laravel-centrifugo` are documented in this file.

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
