<?php

declare(strict_types=1);

namespace Jestays\Centrifugo\Tests\Integration;

use Illuminate\Support\Env;
use Jestays\Centrifugo\Centrifugo;
use Jestays\Centrifugo\Exceptions\CentrifugoApiError;
use Jestays\Centrifugo\Tests\TestCase;

final class CentrifugoServerTest extends TestCase
{
    protected function setUp(): void
    {
        if (self::serverUrl() === null) {
            $this->markTestSkipped('Set CENTRIFUGO_INTEGRATION_URL to run the integration tests against a real Centrifugo server.');
        }

        parent::setUp();
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('centrifugo.url', self::serverUrl());
        $app['config']->set('centrifugo.api_key', Env::get('CENTRIFUGO_INTEGRATION_API_KEY', 'integration-api-key'));
        $app['config']->set('centrifugo.token_hmac_secret_key', Env::get('CENTRIFUGO_INTEGRATION_HMAC_SECRET', 'integration-secret'));
    }

    public function test_info_reaches_the_server(): void
    {
        $result = $this->centrifugo()->info();

        $this->assertNotEmpty($result['result']['nodes']);
    }

    public function test_publishes_to_every_package_namespace(): void
    {
        $centrifugo = $this->centrifugo();

        $this->assertArrayHasKey('result', $centrifugo->publish('stock.updated', ['qty' => 1]));
        $this->assertArrayHasKey('result', $centrifugo->publish('private-orders.1', ['status' => 'paid']));
        $this->assertArrayHasKey('result', $centrifugo->publish('presence-branch.10', ['ping' => true]));
    }

    public function test_broadcasts_to_multiple_channels(): void
    {
        $result = $this->centrifugo()->broadcast(['stock.updated', 'private-orders.1'], ['event' => 'updated']);

        $responses = $result['result']['responses'] ?? [];

        $this->assertCount(2, $responses);

        foreach ($responses as $response) {
            $this->assertArrayNotHasKey('error', $response);
        }
    }

    public function test_presence_is_enabled_for_the_presence_namespace(): void
    {
        $result = $this->centrifugo()->presence('presence-branch.10');

        $this->assertArrayHasKey('result', $result);
    }

    public function test_presence_is_rejected_for_a_namespace_without_presence(): void
    {
        $this->expectException(CentrifugoApiError::class);

        $this->centrifugo()->presence('private-orders.1');
    }

    public function test_publishing_to_an_unknown_namespace_returns_an_unknown_channel_error(): void
    {
        $response = $this->centrifugo()->client()->publish('unknown:pos.orders.1', []);

        $this->assertSame(102, $response['error']['code'] ?? null);
    }

    private function centrifugo(): Centrifugo
    {
        return $this->app->make(Centrifugo::class);
    }

    private static function serverUrl(): ?string
    {
        $url = Env::get('CENTRIFUGO_INTEGRATION_URL');

        return is_string($url) && $url !== '' ? $url : null;
    }
}
