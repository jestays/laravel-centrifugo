<?php

declare(strict_types=1);

namespace Jestays\Centrifugo;

use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Jestays\Centrifugo\Broadcasting\CentrifugoBroadcaster;
use Jestays\Centrifugo\Channels\ChannelMapper;
use Jestays\Centrifugo\Channels\ScopedChannelMapper;
use Jestays\Centrifugo\Commands\InstallCommand;
use Jestays\Centrifugo\Identity\ScopedUserMapper;
use Jestays\Centrifugo\Identity\UserMapper;
use Jestays\Centrifugo\Support\ApplicationName;
use Jestays\Centrifugo\Tokens\TokenManager;
use phpcent\Client;

final class CentrifugoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/centrifugo.php', 'centrifugo');

        $this->app->singleton(Client::class, function ($app): Client {
            $config = $app->make('config');

            return (new Client(
                rtrim((string) $config->get('centrifugo.url'), '/').'/api',
                (string) $config->get('centrifugo.api_key'),
                (string) $config->get('centrifugo.token_hmac_secret_key'),
            ))
                ->setUseAssoc(true)
                ->setSafety((bool) $config->get('centrifugo.verify'));
        });

        $this->app->singleton(ChannelMapper::class, function ($app): ChannelMapper {
            $config = $app->make('config');

            return new ScopedChannelMapper(
                ApplicationName::validate($config->get('centrifugo.application')),
                (array) $config->get('centrifugo.namespaces'),
            );
        });

        $this->app->singleton(UserMapper::class, function ($app): UserMapper {
            return new ScopedUserMapper(
                ApplicationName::validate($app->make('config')->get('centrifugo.application')),
            );
        });

        $this->app->singleton(TokenManager::class, function ($app): TokenManager {
            return new TokenManager(
                $app->make(Client::class),
                $app->make(UserMapper::class),
                (int) $app->make('config')->get('centrifugo.token_ttl'),
            );
        });

        $this->app->singleton(Centrifugo::class, function ($app): Centrifugo {
            return new Centrifugo(
                $app->make(Client::class),
                $app->make(ChannelMapper::class),
                $app->make(UserMapper::class),
                $app->make(TokenManager::class),
            );
        });

        $this->app->alias(Centrifugo::class, 'centrifugo');
    }

    public function boot(BroadcastManager $broadcastManager): void
    {
        $this->publishes([
            __DIR__.'/../config/centrifugo.php' => $this->app->configPath('centrifugo.php'),
        ], 'centrifugo-config');

        if ($this->app->runningInConsole()) {
            $this->commands([InstallCommand::class]);
        }

        $broadcastManager->extend('centrifugo', function ($app): CentrifugoBroadcaster {
            return new CentrifugoBroadcaster(
                $app->make(Client::class),
                $app->make(ChannelMapper::class),
                $app->make(TokenManager::class),
            );
        });

        if ((bool) $this->app->make('config')->get('centrifugo.routes.enabled')) {
            $this->registerRoutes();
        }
    }

    private function registerRoutes(): void
    {
        $config = $this->app->make('config');

        Route::group([
            'prefix' => $config->get('centrifugo.routes.prefix'),
            'middleware' => $config->get('centrifugo.routes.middleware'),
        ], function (): void {
            $this->loadRoutesFrom(__DIR__.'/../routes/centrifugo.php');
        });
    }
}
