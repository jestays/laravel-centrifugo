<?php

declare(strict_types=1);

namespace Jestays\Centrifugo\Tests\Unit\Broadcasting;

use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Jestays\Centrifugo\Broadcasting\CentrifugoBroadcaster;
use Jestays\Centrifugo\Channels\ScopedChannelMapper;
use Jestays\Centrifugo\Identity\ScopedUserMapper;
use Jestays\Centrifugo\Tests\Support\DecodesJwt;
use Jestays\Centrifugo\Tests\TestCase;
use Jestays\Centrifugo\Tokens\TokenManager;
use phpcent\Client;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class CentrifugoBroadcasterTest extends TestCase
{
    use DecodesJwt;

    public function test_broadcast_maps_channels_and_sends_event_payload_to_client(): void
    {
        $client = $this->getMockBuilder(Client::class)
            ->setConstructorArgs(['http://unused', 'api-key', 'secret'])
            ->onlyMethods(['broadcast'])
            ->getMock();

        $client->expects($this->once())
            ->method('broadcast')
            ->with(
                ['private:pos.orders.1'],
                $this->callback(static function (array $payload): bool {
                    return $payload['event'] === 'order.updated'
                        && $payload['data'] === ['id' => 1]
                        && $payload['socket'] === 'socket-1';
                }),
                false,
            )
            ->willReturn(['result' => []]);

        $broadcaster = $this->makeBroadcaster($client);

        $broadcaster->broadcast(['private-orders.1'], 'order.updated', [
            'data' => ['id' => 1],
            'socket' => 'socket-1',
        ]);
    }

    public function test_broadcast_wraps_throwable_in_broadcast_exception(): void
    {
        $client = $this->getMockBuilder(Client::class)
            ->setConstructorArgs(['http://unused', 'api-key', 'secret'])
            ->onlyMethods(['broadcast'])
            ->getMock();

        $client->expects($this->once())->method('broadcast')->willThrowException(new \Exception('centrifugo unreachable'));

        $broadcaster = $this->makeBroadcaster($client);

        $this->expectException(BroadcastException::class);

        $broadcaster->broadcast(['orders.1'], 'order.updated', ['data' => []]);
    }

    public function test_broadcast_throws_broadcast_exception_on_top_level_api_error(): void
    {
        $client = $this->getMockBuilder(Client::class)
            ->setConstructorArgs(['http://unused', 'api-key', 'secret'])
            ->onlyMethods(['broadcast'])
            ->getMock();

        $client->expects($this->once())->method('broadcast')->willReturn([
            'error' => ['code' => 102, 'message' => 'unknown channel'],
        ]);

        $broadcaster = $this->makeBroadcaster($client);

        $this->expectException(BroadcastException::class);
        $this->expectExceptionMessage('unknown channel');

        $broadcaster->broadcast(['orders.1'], 'order.updated', ['data' => []]);
    }

    public function test_broadcast_throws_broadcast_exception_on_per_channel_api_error(): void
    {
        $client = $this->getMockBuilder(Client::class)
            ->setConstructorArgs(['http://unused', 'api-key', 'secret'])
            ->onlyMethods(['broadcast'])
            ->getMock();

        $client->expects($this->once())->method('broadcast')->willReturn([
            'result' => [
                'responses' => [
                    ['result' => []],
                    ['error' => ['code' => 102, 'message' => 'unknown channel']],
                ],
            ],
        ]);

        $broadcaster = $this->makeBroadcaster($client);

        $this->expectException(BroadcastException::class);
        $this->expectExceptionMessage('unknown channel');

        $broadcaster->broadcast(['orders.1', 'orders.2'], 'order.updated', ['data' => []]);
    }

    public function test_broadcast_maps_a_non_list_channels_array_into_a_json_list(): void
    {
        $client = $this->getMockBuilder(Client::class)
            ->setConstructorArgs(['http://unused', 'api-key', 'secret'])
            ->onlyMethods(['broadcast'])
            ->getMock();

        $client->expects($this->once())
            ->method('broadcast')
            ->with($this->callback(static fn (array $channels): bool => array_is_list($channels) && $channels === ['private:pos.orders.1']))
            ->willReturn(['result' => []]);

        $broadcaster = $this->makeBroadcaster($client);

        $broadcaster->broadcast(['first' => 'private-orders.1'], 'order.updated', ['data' => []]);
    }

    public function test_auth_throws_unauthorized_for_guest(): void
    {
        $broadcaster = $this->makeBroadcaster($this->realClient());

        $request = Request::create('/broadcasting/auth', 'POST', ['channel' => 'private:pos.orders.1']);

        $this->expectException(HttpException::class);

        try {
            $broadcaster->auth($request);
        } catch (HttpException $exception) {
            $this->assertSame(401, $exception->getStatusCode());

            throw $exception;
        }
    }

    public function test_auth_authorizes_registered_broadcast_channel(): void
    {
        $broadcaster = $this->makeBroadcaster($this->realClient());
        $broadcaster->channel('orders.{id}', fn ($user, int $id): bool => (int) $user->id === $id);

        $request = Request::create('/broadcasting/auth', 'POST', ['channel' => 'private:pos.orders.1']);
        $request->setUserResolver(fn (): Authenticatable => $this->user(1));

        $result = $broadcaster->auth($request);
        $payload = $this->decodeJwtPayload($result['token']);

        $this->assertSame('pos:1', $payload['sub']);
        $this->assertSame('private:pos.orders.1', $payload['channel']);
    }

    public function test_auth_returns_forbidden_when_callback_denies_access(): void
    {
        $broadcaster = $this->makeBroadcaster($this->realClient());
        $broadcaster->channel('orders.{id}', static fn ($user, int $id): bool => false);

        $request = Request::create('/broadcasting/auth', 'POST', ['channel' => 'private:pos.orders.1']);
        $request->setUserResolver(fn (): Authenticatable => $this->user(1));

        $this->expectException(HttpException::class);

        try {
            $broadcaster->auth($request);
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());

            throw $exception;
        }
    }

    public function test_auth_returns_forbidden_for_foreign_application_channel(): void
    {
        $broadcaster = $this->makeBroadcaster($this->realClient());

        $request = Request::create('/broadcasting/auth', 'POST', ['channel' => 'private:proplus.orders.1']);
        $request->setUserResolver(fn (): Authenticatable => $this->user(1));

        $this->expectException(HttpException::class);

        try {
            $broadcaster->auth($request);
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());

            throw $exception;
        }
    }

    public function test_auth_returns_forbidden_for_unknown_namespace(): void
    {
        $broadcaster = $this->makeBroadcaster($this->realClient());

        $request = Request::create('/broadcasting/auth', 'POST', ['channel' => 'unknown:pos.orders.1']);
        $request->setUserResolver(fn (): Authenticatable => $this->user(1));

        $this->expectException(HttpException::class);

        try {
            $broadcaster->auth($request);
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());

            throw $exception;
        }
    }

    public function test_auth_returns_forbidden_for_malformed_channel(): void
    {
        $broadcaster = $this->makeBroadcaster($this->realClient());

        $request = Request::create('/broadcasting/auth', 'POST', ['channel' => 'pos.orders.1']);
        $request->setUserResolver(fn (): Authenticatable => $this->user(1));

        $this->expectException(HttpException::class);

        try {
            $broadcaster->auth($request);
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());

            throw $exception;
        }
    }

    public function test_auth_returns_unprocessable_when_channel_is_missing(): void
    {
        $broadcaster = $this->makeBroadcaster($this->realClient());

        $request = Request::create('/broadcasting/auth', 'POST');
        $request->setUserResolver(fn (): Authenticatable => $this->user(1));

        $this->expectException(HttpException::class);

        try {
            $broadcaster->auth($request);
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());

            throw $exception;
        }
    }

    public function test_auth_includes_presence_callback_info_in_subscription_token(): void
    {
        $broadcaster = $this->makeBroadcaster($this->realClient());
        $broadcaster->channel('branch.10', fn ($user): array => ['id' => $user->id, 'name' => 'Ada']);

        $request = Request::create('/broadcasting/auth', 'POST', ['channel' => 'presence:pos.branch.10']);
        $request->setUserResolver(fn (): Authenticatable => $this->user(1));

        $result = $broadcaster->auth($request);
        $payload = $this->decodeJwtPayload($result['token']);

        $this->assertSame(['id' => 1, 'name' => 'Ada'], $payload['info']);
    }

    public function test_auth_does_not_leak_presence_info_into_private_channel_tokens(): void
    {
        $broadcaster = $this->makeBroadcaster($this->realClient());
        $broadcaster->channel('orders.{id}', fn ($user): array => ['id' => $user->id]);

        $request = Request::create('/broadcasting/auth', 'POST', ['channel' => 'private:pos.orders.1']);
        $request->setUserResolver(fn (): Authenticatable => $this->user(1));

        $result = $broadcaster->auth($request);
        $payload = $this->decodeJwtPayload($result['token']);

        $this->assertSame([], $payload['info'] ?? []);
    }

    public function test_valid_authentication_response_returns_the_given_result_unchanged(): void
    {
        $broadcaster = $this->makeBroadcaster($this->realClient());
        $request = Request::create('/broadcasting/auth', 'POST');
        $result = ['token' => 'anything'];

        $this->assertSame($result, $broadcaster->validAuthenticationResponse($request, $result));
    }

    private function makeBroadcaster(Client $client): CentrifugoBroadcaster
    {
        $mapper = new ScopedChannelMapper('pos', [
            'public' => 'public',
            'private' => 'private',
            'presence' => 'presence',
        ]);

        return new CentrifugoBroadcaster($client, $mapper, new TokenManager($client, new ScopedUserMapper('pos'), 3600));
    }

    private function realClient(): Client
    {
        return (new Client('http://unused'))->setSecret('secret');
    }

    private function user(int $id): Authenticatable
    {
        return new class($id) implements Authenticatable
        {
            public function __construct(public int $id) {}

            public function getAuthIdentifierName(): string
            {
                return 'id';
            }

            public function getAuthIdentifier(): int
            {
                return $this->id;
            }

            public function getAuthPasswordName(): string
            {
                return 'password';
            }

            public function getAuthPassword(): string
            {
                return '';
            }

            public function getRememberToken(): ?string
            {
                return null;
            }

            public function setRememberToken($value): void {}

            public function getRememberTokenName(): string
            {
                return 'remember_token';
            }
        };
    }
}
