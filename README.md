# Laravel Centrifugo

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
CENTRIFUGO_APP=pos
CENTRIFUGO_TOKEN_TTL=3600
CENTRIFUGO_VERIFY=true
```

`CENTRIFUGO_APP` identifies the current application on the shared Centrifugo server. It is required and must
match `[a-z0-9_-]+`. The package fails with a clear error as soon as a channel or user mapper is resolved
without a valid `CENTRIFUGO_APP`.

`CENTRIFUGO_TOKEN_TTL` is the default time-to-live, in seconds, applied to connection and subscription tokens
issued through `TokenManager`, the `Centrifugo` service, and the token endpoints when no explicit TTL is
given. A TTL of `0` produces a token with no `exp` claim, i.e. a token that never expires; use that
deliberately.

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

With `CENTRIFUGO_APP=pos`, this event is published to Centrifugo as:

```text
private:pos.orders.123
```

The event never needs to know about Centrifugo namespaces or application scoping.

## Channel naming

Every Centrifugo channel follows the same structure:

```text
<namespace>:<application>.<channel>
```

| Laravel channel                | Centrifugo channel            |
|---------------------------------|--------------------------------|
| `new Channel('stock.updated')`  | `public:pos.stock.updated`     |
| `new PrivateChannel('user.123')`| `private:pos.user.123`         |
| `new PresenceChannel('branch.10')`| `presence:pos.branch.10`     |

Namespace names (`public`, `private`, `presence`) are configurable in `config/centrifugo.php`, but there is a
single, modern naming strategy: legacy `$channel` naming is not supported.

## Multi-application scoping

The same Centrifugo server can serve multiple Laravel applications, each with its own `CENTRIFUGO_APP`:

```text
private:pos.orders.123
private:proplus.orders.123
private:qms.orders.123
```

An application can never request a subscription token for a channel that belongs to another application.
`ScopedChannelMapper` rejects any channel whose application segment does not match the current
`CENTRIFUGO_APP`.

## User identity

Authenticated Laravel users are mapped to Centrifugo user identifiers scoped by application, so the same
Laravel user ID never collides across applications:

```text
POS user 123    -> pos:123
Pro+ user 123   -> proplus:123
```

## Centrifugo server namespace configuration

Configure matching namespaces on the Centrifugo server side, for example:

```json
{
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
```

## Token endpoints

The package optionally registers two routes (enabled by default, see `config/centrifugo.php`):

| Method | Route                              | Purpose                              |
|--------|-------------------------------------|---------------------------------------|
| POST   | `/centrifugo/connection-token`      | Issues a Centrifugo connection token  |
| POST   | `/centrifugo/subscription-token`    | Issues a Centrifugo subscription token|

The subscription token endpoint expects a `channel` field containing the Centrifugo channel name (e.g.
`private:pos.orders.123`). It maps the channel back to its Laravel name (`orders.123`) and runs it through
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

const subscription = centrifuge.newSubscription('private:pos.orders.123', {
    getToken: () => fetchToken('/centrifugo/subscription-token', { channel: 'private:pos.orders.123' }),
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
constructs a Centrifugo channel or user identity by hand:

```php
use Jestays\Centrifugo\Facades\Centrifugo;

Centrifugo::publish('orders.123', ['status' => 'shipped']);
Centrifugo::broadcast(['orders.123', 'stock.updated'], ['status' => 'shipped']);
Centrifugo::presence('branch.10');
Centrifugo::presenceStats('branch.10');
Centrifugo::history('orders.123', limit: 10);
Centrifugo::historyRemove('orders.123');
Centrifugo::subscribe('orders.123', $userId);
Centrifugo::unsubscribe('orders.123', $userId);
Centrifugo::disconnect($userId);
Centrifugo::channels();
Centrifugo::info();
Centrifugo::connectionToken($user);
Centrifugo::subscriptionToken($user, 'orders.123');
```

`Centrifugo::client()` is an escape hatch that returns the underlying `\phpcent\Client` instance for anything
this service does not wrap.

Every method above throws `Jestays\Centrifugo\Exceptions\CentrifugoApiError` when Centrifugo responds with an
HTTP 200 that still carries a top-level `error` key (Centrifugo's API-level error shape, e.g. an unknown
channel or namespace) — callers never receive a silent error array.

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
