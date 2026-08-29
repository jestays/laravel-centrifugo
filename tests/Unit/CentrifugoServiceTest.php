<?php

declare(strict_types=1);

namespace Jestays\Centrifugo\Tests\Unit;

use Jestays\Centrifugo\Centrifugo;
use Jestays\Centrifugo\Channels\ScopedChannelMapper;
use Jestays\Centrifugo\Exceptions\CentrifugoApiError;
use Jestays\Centrifugo\Identity\ScopedUserMapper;
use Jestays\Centrifugo\Tests\Support\DecodesJwt;
use Jestays\Centrifugo\Tokens\TokenManager;
use phpcent\Client;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class CentrifugoServiceTest extends TestCase
{
    use DecodesJwt;

    public function test_publish_maps_channel_and_delegates_to_client(): void
    {
        $client = $this->mockClient();
        $client->expects($this->once())
            ->method('publish')
            ->with('private:pos.orders.1', ['event' => 'updated'], false)
            ->willReturn(['result' => []]);

        $this->makeCentrifugo($client)->publish('private-orders.1', ['event' => 'updated']);
    }

    public function test_broadcast_maps_channels_and_delegates_to_client(): void
    {
        $client = $this->mockClient();
        $client->expects($this->once())
            ->method('broadcast')
            ->with(['private:pos.orders.1', 'public:pos.stock'], ['event' => 'updated'], false)
            ->willReturn(['result' => []]);

        $this->makeCentrifugo($client)->broadcast(['private-orders.1', 'stock'], ['event' => 'updated']);
    }

    public function test_presence_maps_channel(): void
    {
        $client = $this->mockClient();
        $client->expects($this->once())->method('presence')->with('presence:pos.branch.10')->willReturn(['result' => []]);

        $this->makeCentrifugo($client)->presence('presence-branch.10');
    }

    public function test_presence_stats_maps_channel(): void
    {
        $client = $this->mockClient();
        $client->expects($this->once())->method('presenceStats')->with('presence:pos.branch.10')->willReturn(['result' => []]);

        $this->makeCentrifugo($client)->presenceStats('presence-branch.10');
    }

    public function test_history_maps_channel_and_forwards_arguments(): void
    {
        $client = $this->mockClient();
        $client->expects($this->once())
            ->method('history')
            ->with('public:pos.stock', 10, ['offset' => 1], true)
            ->willReturn(['result' => []]);

        $this->makeCentrifugo($client)->history('stock', 10, ['offset' => 1], true);
    }

    public function test_history_remove_maps_channel(): void
    {
        $client = $this->mockClient();
        $client->expects($this->once())->method('historyRemove')->with('public:pos.stock')->willReturn([]);

        $this->makeCentrifugo($client)->historyRemove('stock');
    }

    public function test_subscribe_maps_channel_and_user(): void
    {
        $client = $this->mockClient();
        $client->expects($this->once())
            ->method('subscribe')
            ->with('private:pos.orders.1', 'pos:42', 'client-1')
            ->willReturn([]);

        $this->makeCentrifugo($client)->subscribe('private-orders.1', 42, 'client-1');
    }

    public function test_unsubscribe_maps_channel_and_user(): void
    {
        $client = $this->mockClient();
        $client->expects($this->once())
            ->method('unsubscribe')
            ->with('private:pos.orders.1', 'pos:42', '')
            ->willReturn([]);

        $this->makeCentrifugo($client)->unsubscribe('private-orders.1', 42);
    }

    public function test_disconnect_maps_user(): void
    {
        $client = $this->mockClient();
        $client->expects($this->once())->method('disconnect')->with('pos:42', '')->willReturn([]);

        $this->makeCentrifugo($client)->disconnect(42);
    }

    public function test_channels_passes_through_the_pattern(): void
    {
        $client = $this->mockClient();
        $client->expects($this->once())->method('channels')->with('pos.*')->willReturn([]);

        $this->makeCentrifugo($client)->channels('pos.*');
    }

    public function test_info_passes_through(): void
    {
        $client = $this->mockClient();
        $client->expects($this->once())->method('info')->willReturn([]);

        $this->makeCentrifugo($client)->info();
    }

    public function test_publish_throws_centrifugo_api_error_on_a_top_level_error_response(): void
    {
        $client = $this->mockClient();
        $client->expects($this->once())->method('publish')->willReturn([
            'error' => ['code' => 102, 'message' => 'unknown channel'],
        ]);

        $this->expectException(CentrifugoApiError::class);
        $this->expectExceptionMessage('unknown channel');
        $this->expectExceptionCode(102);

        $this->makeCentrifugo($client)->publish('unknown-channel', ['event' => 'updated']);
    }

    public function test_broadcast_returns_response_when_all_per_channel_responses_succeed(): void
    {
        $response = [
            'result' => [
                'responses' => [
                    ['result' => []],
                    ['result' => []],
                ],
            ],
        ];

        $client = $this->mockClient();
        $client->expects($this->once())->method('broadcast')->willReturn($response);

        $this->assertSame($response, $this->makeCentrifugo($client)->broadcast(['private-orders.1', 'stock'], ['event' => 'updated']));
    }

    public function test_broadcast_throws_centrifugo_api_error_on_a_top_level_error_response(): void
    {
        $client = $this->mockClient();
        $client->expects($this->once())->method('broadcast')->willReturn([
            'error' => ['code' => 100, 'message' => 'internal server error'],
        ]);

        $this->expectException(CentrifugoApiError::class);
        $this->expectExceptionMessage('internal server error');
        $this->expectExceptionCode(100);

        $this->makeCentrifugo($client)->broadcast(['private-orders.1', 'stock'], ['event' => 'updated']);
    }

    public function test_broadcast_throws_centrifugo_api_error_on_a_per_channel_error_response(): void
    {
        $client = $this->mockClient();
        $client->expects($this->once())->method('broadcast')->willReturn([
            'result' => [
                'responses' => [
                    ['result' => []],
                    ['error' => ['code' => 102, 'message' => 'unknown channel']],
                ],
            ],
        ]);

        $this->expectException(CentrifugoApiError::class);
        $this->expectExceptionMessage('unknown channel (channel: public:pos.stock)');
        $this->expectExceptionCode(102);

        $this->makeCentrifugo($client)->broadcast(['private-orders.1', 'stock'], ['event' => 'updated']);
    }

    public function test_channels_throws_centrifugo_api_error_on_a_top_level_error_response(): void
    {
        $client = $this->mockClient();
        $client->expects($this->once())->method('channels')->willReturn([
            'error' => ['code' => 108, 'message' => 'internal server error'],
        ]);

        $this->expectException(CentrifugoApiError::class);
        $this->expectExceptionMessage('internal server error');

        $this->makeCentrifugo($client)->channels();
    }

    public function test_client_escape_hatch_returns_the_underlying_client(): void
    {
        $client = $this->createStub(Client::class);

        $this->assertSame($client, $this->makeCentrifugo($client)->client());
    }

    public function test_connection_token_delegates_to_token_manager_with_scoped_identity(): void
    {
        $centrifugo = $this->realCentrifugo();

        $payload = $this->decodeJwtPayload($centrifugo->connectionToken(42));

        $this->assertSame('pos:42', $payload['sub']);
    }

    public function test_subscription_token_maps_laravel_channel_before_delegating(): void
    {
        $centrifugo = $this->realCentrifugo();

        $payload = $this->decodeJwtPayload($centrifugo->subscriptionToken(42, 'private-orders.1'));

        $this->assertSame('pos:42', $payload['sub']);
        $this->assertSame('private:pos.orders.1', $payload['channel']);
    }

    private function mockClient(): Client&MockObject
    {
        return $this->createMock(Client::class);
    }

    private function makeCentrifugo(Client $client): Centrifugo
    {
        $mapper = new ScopedChannelMapper('pos', ['public' => 'public', 'private' => 'private', 'presence' => 'presence']);
        $users = new ScopedUserMapper('pos');

        return new Centrifugo($client, $mapper, $users, new TokenManager($client, $users, 3600));
    }

    private function realCentrifugo(): Centrifugo
    {
        return $this->makeCentrifugo((new Client('http://unused'))->setSecret('secret'));
    }
}
