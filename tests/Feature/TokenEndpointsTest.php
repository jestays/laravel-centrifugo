<?php

declare(strict_types=1);

namespace Jestays\Centrifugo\Tests\Feature;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Broadcast;
use Jestays\Centrifugo\Tests\Support\DecodesJwt;
use Jestays\Centrifugo\Tests\TestCase;

final class TokenEndpointsTest extends TestCase
{
    use DecodesJwt;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('centrifugo.routes.middleware', ['web']);
    }

    public function test_connection_token_endpoint_returns_a_scoped_token_for_an_authenticated_user(): void
    {
        $response = $this->actingAs($this->user(42))->postJson('/centrifugo/connection-token');

        $response->assertOk();

        $payload = $this->decodeJwtPayload($response->json('token'));

        $this->assertSame('pos:42', $payload['sub']);
    }

    public function test_connection_token_endpoint_rejects_guests(): void
    {
        $this->postJson('/centrifugo/connection-token')->assertUnauthorized();
    }

    public function test_subscription_token_endpoint_honours_broadcast_channel_callbacks(): void
    {
        Broadcast::channel('orders.{id}', static fn ($user, int $id): bool => (int) $user->getAuthIdentifier() === $id);

        $response = $this->actingAs($this->user(1))->postJson('/centrifugo/subscription-token', [
            'channel' => 'private:pos.orders.1',
        ]);

        $response->assertOk();

        $payload = $this->decodeJwtPayload($response->json('token'));

        $this->assertSame('pos:1', $payload['sub']);
        $this->assertSame('private:pos.orders.1', $payload['channel']);
    }

    public function test_subscription_token_endpoint_denies_forbidden_channels(): void
    {
        Broadcast::channel('orders.{id}', static fn ($user, int $id): bool => false);

        $response = $this->actingAs($this->user(1))->postJson('/centrifugo/subscription-token', [
            'channel' => 'private:pos.orders.1',
        ]);

        $response->assertForbidden();
    }

    public function test_subscription_token_endpoint_requires_the_channel_field(): void
    {
        $response = $this->actingAs($this->user(1))->postJson('/centrifugo/subscription-token');

        $response->assertUnprocessable();
    }

    public function test_subscription_token_endpoint_rejects_guests(): void
    {
        $this->postJson('/centrifugo/subscription-token', ['channel' => 'private:pos.orders.1'])
            ->assertUnauthorized();
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
