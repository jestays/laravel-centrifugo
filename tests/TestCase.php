<?php

declare(strict_types=1);

namespace Jestays\Centrifugo\Tests;

use Jestays\Centrifugo\CentrifugoServiceProvider;
use Jestays\Centrifugo\Facades\Centrifugo as CentrifugoFacade;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [CentrifugoServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['Centrifugo' => CentrifugoFacade::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));

        $app['config']->set('centrifugo.application', 'pos');
        $app['config']->set('centrifugo.token_hmac_secret_key', 'secret');
        $app['config']->set('centrifugo.api_key', 'api-key');
        $app['config']->set('centrifugo.url', 'http://localhost:8000');

        $app['config']->set('broadcasting.default', 'centrifugo');
        $app['config']->set('broadcasting.connections.centrifugo', ['driver' => 'centrifugo']);
    }
}
