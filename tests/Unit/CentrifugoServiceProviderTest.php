<?php

declare(strict_types=1);

namespace Jestays\Centrifugo\Tests\Unit;

use Illuminate\Broadcasting\BroadcastManager;
use InvalidArgumentException;
use Jestays\Centrifugo\Broadcasting\CentrifugoBroadcaster;
use Jestays\Centrifugo\Centrifugo;
use Jestays\Centrifugo\Channels\ChannelMapper;
use Jestays\Centrifugo\Facades\Centrifugo as CentrifugoFacade;
use Jestays\Centrifugo\Identity\UserMapper;
use Jestays\Centrifugo\Tests\TestCase;
use Jestays\Centrifugo\Tokens\TokenManager;
use phpcent\Client;

final class CentrifugoServiceProviderTest extends TestCase
{
    public function test_centrifugo_service_is_bound_as_singleton_with_alias(): void
    {
        $centrifugo = $this->app->make('centrifugo');

        $this->assertInstanceOf(Centrifugo::class, $centrifugo);
        $this->assertSame($centrifugo, $this->app->make(Centrifugo::class));
    }

    public function test_client_channel_mapper_user_mapper_and_token_manager_are_bound(): void
    {
        $this->assertInstanceOf(Client::class, $this->app->make(Client::class));
        $this->assertInstanceOf(ChannelMapper::class, $this->app->make(ChannelMapper::class));
        $this->assertInstanceOf(UserMapper::class, $this->app->make(UserMapper::class));
        $this->assertInstanceOf(TokenManager::class, $this->app->make(TokenManager::class));
    }

    public function test_facade_resolves_to_the_centrifugo_service(): void
    {
        $this->assertInstanceOf(Centrifugo::class, CentrifugoFacade::getFacadeRoot());
    }

    public function test_broadcaster_resolves_through_the_broadcast_manager(): void
    {
        $broadcaster = $this->app->make(BroadcastManager::class)->connection('centrifugo');

        $this->assertInstanceOf(CentrifugoBroadcaster::class, $broadcaster);
    }

    public function test_configuration_defaults_are_merged(): void
    {
        $this->assertSame(3600, config('centrifugo.token_ttl'));
        $this->assertSame(['public' => 'public', 'private' => 'private', 'presence' => 'presence'], config('centrifugo.namespaces'));
        $this->assertTrue(config('centrifugo.routes.enabled'));
    }

    public function test_partial_namespaces_config_does_not_wipe_the_remaining_defaults(): void
    {
        $this->app['config']->set('centrifugo.namespaces', ['private' => 'priv']);
        $this->app->forgetInstance(ChannelMapper::class);

        $mapper = $this->app->make(ChannelMapper::class);

        $this->assertSame('priv:pos.orders.1', $mapper->toCentrifugo('private-orders.1'));
        $this->assertSame('public:pos.stock', $mapper->toCentrifugo('stock'));
        $this->assertSame('presence:pos.branch.10', $mapper->toCentrifugo('presence-branch.10'));
    }

    public function test_resolving_the_channel_mapper_without_an_application_configured_throws_a_clear_message(): void
    {
        $this->app['config']->set('centrifugo.application', null);
        $this->app->forgetInstance(ChannelMapper::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/CENTRIFUGO_APP/');

        $this->app->make(ChannelMapper::class);
    }
}
