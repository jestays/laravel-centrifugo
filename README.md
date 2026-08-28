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
```

`CENTRIFUGO_APP` identifies the current application on the shared Centrifugo server. It is required and must
match `[a-z0-9_-]+`. The package fails with a clear error as soon as a channel or user mapper is resolved
without a valid `CENTRIFUGO_APP`.

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

Route middleware (`web`, `auth` by default) is fully configurable and not tied to any specific guard, so you
can use `auth:sanctum` or any other guard your application needs.

## Frontend usage

Clients connect directly to Centrifugo using the official [`centrifuge-js`](https://github.com/centrifugal/centrifuge-js)
SDK, using the two token endpoints above as `getToken` callbacks:

```js
import { Centrifuge } from 'centrifuge';

const centrifuge = new Centrifuge('ws://localhost:8000/connection/websocket', {
    getToken: async () => {
        const response = await fetch('/centrifugo/connection-token', { method: 'POST' });
        const { token } = await response.json();
        return token;
    },
});

const subscription = centrifuge.newSubscription('private:pos.orders.123', {
    getToken: async () => {
        const response = await fetch('/centrifugo/subscription-token', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ channel: 'private:pos.orders.123' }),
        });
        const { token } = await response.json();
        return token;
    },
});

centrifuge.connect();
subscription.subscribe();
```

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
