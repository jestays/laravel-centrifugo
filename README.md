# Laravel Centrifugo

![Laravel Centrifugo](https://raw.githubusercontent.com/jestays/cdn/refs/heads/main/laravel-centrifugo.jpeg)

A Laravel broadcasting driver for [Centrifugo](https://centrifugal.dev/) 6+, built on top of the official
[`centrifugal/phpcent`](https://github.com/centrifugal/phpcent) client.

This package is designed for a single Centrifugo server shared by several Laravel applications. It scopes
channels and user identities per application, so different applications can safely share the same Centrifugo
instance without colliding.

## Architecture

```text
Laravel
    |
jestays/laravel-centrifugo
    |
centrifugal/phpcent
    |
Centrifugo
```

Laravel remains responsible for authentication, subscription authorization, and publishing. Transports
(WebSocket, SSE, HTTP streaming) are entirely Centrifugo's responsibility; this package does not implement
any transport and does not depend on Laravel Echo.

## Installation

```bash
composer require jestays/laravel-centrifugo
php artisan centrifugo:install
```

The installer publishes `config/centrifugo.php`, adds the required environment variables to `.env`, registers
the `centrifugo` broadcasting connection, and sets `BROADCAST_CONNECTION=centrifugo`.

## Configuration

```env
BROADCAST_CONNECTION=centrifugo

CENTRIFUGO_URL=http://localhost:8000
CENTRIFUGO_API_KEY=
CENTRIFUGO_TOKEN_HMAC_SECRET_KEY=
CENTRIFUGO_APP=app1
CENTRIFUGO_TOKEN_TTL=3600
CENTRIFUGO_VERIFY=true
```

`CENTRIFUGO_APP` identifies the current application on the shared Centrifugo server. It is required and must
match `[a-z0-9_-]+`. The package fails with a clear error as soon as a channel or user mapper is resolved
without a valid `CENTRIFUGO_APP`.

`CENTRIFUGO_TOKEN_TTL` is the default time-to-live, in seconds, applied to connection and subscription tokens
issued through `TokenManager`, the `Centrifugo` service, and the token endpoints when no explicit TTL is
given. A TTL of `0` produces a token with no `exp` claim, i.e. a token that never expires; use that
deliberately. Negative TTL values are rejected with an `InvalidArgumentException`.

`CENTRIFUGO_VERIFY` controls TLS certificate verification for the phpcent HTTP client when talking to
`CENTRIFUGO_URL`. Keep it `true` in production; only disable it for local development against a
self-signed Centrifugo instance.

**`BROADCAST_CONNECTION=centrifugo` must be the default broadcasting connection.** `Broadcast::channel()`
authorization callbacks are registered on Laravel's *default* broadcasting connection, and the subscription
token endpoint resolves that same default connection to reuse them. If `BROADCAST_CONNECTION` is set to
anything other than `centrifugo`, the subscription token endpoint throws a clear `RuntimeException` instead
of silently failing.

## Broadcasting example

Business code keeps using the standard Laravel broadcasting primitives:

```php
final class OrderUpdated implements ShouldBroadcast
{
    public function __construct(private readonly Order $order)
    {
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("orders.{$this->order->id}"),
        ];
    }
}
```

With `CENTRIFUGO_APP=app1`, this event is published to Centrifugo as:

```text
private:app1.orders.123
```

The event never needs to know about Centrifugo namespaces or application scoping.

## Channel naming

Every Centrifugo channel follows the same structure:

```text
<namespace>:<application>.<channel>
```

| Laravel channel                | Centrifugo channel            |
|---------------------------------|--------------------------------|
| `new Channel('stock.updated')`  | `public:app1.stock.updated`     |
| `new PrivateChannel('user.123')`| `private:app1.user.123`         |
| `new PresenceChannel('branch.10')`| `presence:app1.branch.10`     |

Namespace names (`public`, `private`, `presence`) are configurable in `config/centrifugo.php`, but there is a
single, modern naming strategy: legacy `$channel` naming is not supported.

### Name restrictions

- `CENTRIFUGO_APP` must match `[a-z0-9_-]+`, e.g. `app1`, `app2`, `my-app`.
- The Laravel channel name (the part after `private-`/`presence-`, or the whole name for public channels)
  must match `[A-Za-z0-9@,;._=-]+`. Names such as `orders.123`, `users.123.notifications`, `branch-10`, and
  `stock_updated` are all valid.
- Centrifugo's reserved symbols (`:`, `#`, `$`, `/`, `*`, `&`) and whitespace are rejected. This keeps
  `<namespace>:<application>.<channel>` unambiguous and reversible when the subscription token endpoint maps
  a Centrifugo channel back to its Laravel name.

Invalid application names throw an `InvalidArgumentException` when the mappers are resolved; invalid channel
names throw `Jestays\Centrifugo\Exceptions\InvalidCentrifugoChannel` as soon as a channel is mapped.

## Multi-application scoping

The same Centrifugo server can serve multiple Laravel applications, each with its own `CENTRIFUGO_APP`:

```text
private:app1.orders.123
private:app2.orders.123
private:app3.orders.123
```

An application can never request a subscription token for a channel that belongs to another application.
`ScopedChannelMapper` rejects any channel whose application segment does not match the current
`CENTRIFUGO_APP`.

## User identity

Authenticated Laravel users are mapped to Centrifugo user identifiers scoped by application, so the same
Laravel user ID never collides across applications:

```text
app1 user 123   -> app1:123
app2 user 123   -> app2:123
```

## Centrifugo server namespace configuration

Centrifugo 6 expects channel namespaces under the top-level `channel` key. Configure matching namespaces on
the server side:

```json
{
    "channel": {
        "namespaces": [
            {
                "name": "public",
                "allow_subscribe_for_client": true
            },
            {
                "name": "private"
            },
            {
                "name": "presence",
                "presence": true
            }
        ]
    }
}
```

The same configuration as an environment variable (useful for Docker):

```env
CENTRIFUGO_CHANNEL_NAMESPACES='[{"name":"public","allow_subscribe_for_client":true},{"name":"private"},{"name":"presence","presence":true}]'
```

Subscriptions to `private:` and `presence:` channels always require a subscription token issued through your
Laravel `Broadcast::channel()` authorization callbacks.

### What `public` means

`public` does **not** mean "anyone on the Internet can subscribe". Connecting to Centrifugo still requires a
valid connection token issued to an authenticated Laravel user, and `allow_subscribe_for_client: true` only
lets those already-authenticated, non-anonymous connections subscribe to `public:*` channels without a
subscription token.

Two consequences worth knowing:

- Public channels bypass Laravel's `Broadcast::channel()` authorization entirely.
- On a shared Centrifugo server, connection tokens from *every* application are signed with the same secret,
  so an authenticated `app2` user can subscribe to `public:app1.stock.updated`. Only publish data to public
  channels that any authenticated user of any application on the server may read; use `private:` or
  `presence:` channels otherwise.

Do not enable `allow_subscribe_for_anonymous` on these namespaces: this package's design assumes every
connection belongs to an authenticated user.

## Token endpoints

The package optionally registers two routes (enabled by default, see `config/centrifugo.php`):

| Method | Route                              | Purpose                              |
|--------|-------------------------------------|---------------------------------------|
| POST   | `/centrifugo/connection-token`      | Issues a Centrifugo connection token  |
| POST   | `/centrifugo/subscription-token`    | Issues a Centrifugo subscription token|

The subscription token endpoint expects a `channel` field containing the Centrifugo channel name (e.g.
`private:app1.orders.123`). It maps the channel back to its Laravel name (`orders.123`) and runs it through
your normal `Broadcast::channel()` authorization callbacks before issuing a token.

### Routes configuration

Routes are controlled by `config('centrifugo.routes')`:

```php
'routes' => [
    'enabled' => true,
    'prefix' => 'centrifugo',
    'middleware' => ['web', 'auth'],
],
```

- `enabled` toggles route registration entirely; set it to `false` if you want to expose the endpoints
  yourself, or not use them at all.
- `prefix` is the URL prefix under which both endpoints are registered (`/{prefix}/connection-token` and
  `/{prefix}/subscription-token`).
- `middleware` is fully configurable and not tied to any specific guard. Use `['api', 'auth:sanctum']` for a
  stateless SPA/mobile client, or keep the `web` session guard for a first-party web app. Partial overrides
  in `config/centrifugo.php` (e.g. only setting `prefix`) do not remove the other defaults; missing keys
  always fall back to `enabled: true`, `prefix: 'centrifugo'`, `middleware: ['web', 'auth']`.

## Frontend usage

Clients connect directly to Centrifugo using the official [`centrifuge-js`](https://github.com/centrifugal/centrifuge-js)
SDK, using the two token endpoints above as `getToken` callbacks. With the default `web`/`auth` middleware,
the endpoints sit behind Laravel's session guard and CSRF protection, so requests must send the
`XSRF-TOKEN` cookie back as an `X-XSRF-TOKEN` header and include credentials:

```js
import { Centrifuge } from 'centrifuge';

function xsrfToken() {
    return decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '');
}

async function fetchToken(url, body) {
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'include',
        headers: {
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        body: body ? JSON.stringify(body) : undefined,
    });

    const { token } = await response.json();

    return token;
}

const centrifuge = new Centrifuge('ws://localhost:8000/connection/websocket', {
    getToken: () => fetchToken('/centrifugo/connection-token'),
});

const subscription = centrifuge.newSubscription('private:app1.orders.123', {
    getToken: () => fetchToken('/centrifugo/subscription-token', { channel: 'private:app1.orders.123' }),
});

centrifuge.connect();
subscription.subscribe();
```

If your frontend is a stateless SPA or mobile client instead, set `centrifugo.routes.middleware` to
`['api', 'auth:sanctum']` (Sanctum token/PAT authentication) and drop the CSRF/cookie handling above in
favour of a plain `Authorization: Bearer <token>` header.

## Centrifugo service / facade

For explicit server-to-server calls, inject `Jestays\Centrifugo\Centrifugo` or use the `Centrifugo` facade.
It accepts Laravel-style channel names and raw user IDs, and maps them internally, so business code never
constructs a Centrifugo channel or user identity by hand.

Channel names follow the same convention as the broadcasting side: unprefixed names map to the configured
`public` namespace, so use the `private-` and `presence-` prefixes when targeting private or presence
channels:

```php
use Jestays\Centrifugo\Facades\Centrifugo;

// Public channel
Centrifugo::publish('stock.updated', ['product' => 123]);
// → public:app1.stock.updated

// Private channel
Centrifugo::publish('private-orders.123', ['status' => 'shipped']);
// → private:app1.orders.123

// Presence channel
Centrifugo::presence('presence-branch.10');
// → presence:app1.branch.10
```

The remaining methods follow the same rule:

```php
Centrifugo::broadcast(['private-orders.123', 'stock.updated'], ['status' => 'shipped']);
Centrifugo::presenceStats('presence-branch.10');
Centrifugo::history('private-orders.123', limit: 10);
Centrifugo::historyRemove('private-orders.123');
Centrifugo::subscribe('private-orders.123', $userId);   // subscribes the user to private:app1.orders.123
Centrifugo::unsubscribe('private-orders.123', $userId);
Centrifugo::disconnect($userId);
Centrifugo::channels();
Centrifugo::info();
Centrifugo::connectionToken($user);
Centrifugo::subscriptionToken($user, 'private-orders.123'); // token for private:app1.orders.123
```

`subscriptionToken()` receives the Laravel-style name and maps it internally — never pass a raw Centrifugo
channel such as `private:app1.orders.123`.

`Centrifugo::client()` is an escape hatch that returns the underlying `\phpcent\Client` instance for anything
this service does not wrap.

Every method above throws `Jestays\Centrifugo\Exceptions\CentrifugoApiError` when Centrifugo responds with an
HTTP 200 that still carries a top-level `error` key (Centrifugo's API-level error shape, e.g. an unknown
channel or namespace) — callers never receive a silent error array. `broadcast()` also inspects each
per-channel entry inside `result.responses` and throws `CentrifugoApiError` (naming the failing channel) when
any individual publication failed, even though the top-level response was successful.

`channels()` and `info()` are server-wide operations authenticated purely by `CENTRIFUGO_API_KEY`. They are
**not** application-scoped: on a shared Centrifugo server, `channels()` returns channels for every
application, not just the current one. Filter the result yourself (e.g. by the `<namespace>:<application>.`
prefix) if you need application-scoped results.

## Transports

This package does not implement WebSocket, SSE, or HTTP streaming. Those transports are Centrifugo's
responsibility; clients can use any official Centrifugo SDK to connect over the transport of their choice.

## Legacy channels

This package does not support legacy `$` private channels.

## Credits

This package was originally based on [`denis660/laravel-centrifugo`](https://github.com/denis660/laravel-centrifugo)
and is now independently maintained and versioned by [jestays](https://github.com/jestays). It relies on the
official [`centrifugal/phpcent`](https://github.com/centrifugal/phpcent) client and targets
[Centrifugo](https://centrifugal.dev/) 6+.

## License

Released under the [MIT License](LICENSE).
