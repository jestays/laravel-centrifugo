<?php

declare(strict_types=1);

namespace Jestays\Centrifugo\Tests\Unit;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Jestays\Centrifugo\Commands\InstallCommand;
use Jestays\Centrifugo\Tests\Support\TemporaryLaravelAppTestCase;
use ReflectionMethod;

final class InstallCommandTest extends TemporaryLaravelAppTestCase
{
    public function test_handle_runs_the_full_install_flow_against_a_real_filesystem(): void
    {
        $this->deleteAppFile('config/broadcasting.php');
        $this->deleteAppFile('routes/channels.php');
        $this->writeAppFile('.env', 'APP_NAME=Laravel');

        $this->artisan('centrifugo:install')->assertExitCode(0);

        $env = $this->readAppFile('.env');
        $broadcasting = $this->readAppFile('config/broadcasting.php');

        $this->assertStringContainsString('CENTRIFUGO_TOKEN_HMAC_SECRET_KEY=', $env);
        $this->assertStringContainsString('CENTRIFUGO_API_KEY=', $env);
        $this->assertStringContainsString('CENTRIFUGO_URL="http://localhost:8000"', $env);
        $this->assertStringContainsString('CENTRIFUGO_APP=', $env);
        $this->assertStringContainsString('BROADCAST_CONNECTION=centrifugo', $env);
        $this->assertStringNotContainsString('BROADCAST_DRIVER=', $env);
        $this->assertStringContainsString("'centrifugo' => [", $broadcasting);
        $this->assertStringContainsString("'driver' => 'centrifugo'", $broadcasting);
        $this->assertFileExists($this->appFilePath('config/centrifugo.php'));
    }

    public function test_handle_publishes_broadcasting_config_only_when_missing(): void
    {
        $existingConfig = <<<'PHP'
<?php

return [
    'connections' => [
        'log' => [
            'driver' => 'log',
        ],
    ],
];
PHP;

        $this->writeAppFile('config/broadcasting.php', $existingConfig);
        $this->writeAppFile('.env', 'APP_NAME=Laravel');

        $this->artisan('centrifugo:install')->assertExitCode(0);

        $broadcasting = $this->readAppFile('config/broadcasting.php');

        $this->assertStringContainsString("'log' => [", $broadcasting);
        $this->assertStringContainsString("'centrifugo' => [", $broadcasting);
    }

    public function test_handle_creates_channels_route_file_when_missing(): void
    {
        $this->deleteAppFile('routes/channels.php');
        $this->writeAppFile('.env', 'APP_NAME=Laravel');

        $this->artisan('centrifugo:install')->assertExitCode(0);

        $channels = $this->readAppFile('routes/channels.php');

        $this->assertStringContainsString('<?php', $channels);
        $this->assertStringContainsString('use Illuminate\Support\Facades\Broadcast;', $channels);
    }

    public function test_handle_does_not_overwrite_an_existing_channels_route_file(): void
    {
        $this->writeAppFile('routes/channels.php', "<?php\n\n// existing channels\n");
        $this->writeAppFile('.env', 'APP_NAME=Laravel');

        $this->artisan('centrifugo:install')->assertExitCode(0);

        $this->assertSame("<?php\n\n// existing channels\n", $this->readAppFile('routes/channels.php'));
    }

    public function test_handle_prints_instructions_when_broadcasting_is_not_wired_into_bootstrap(): void
    {
        $this->writeAppFile('.env', 'APP_NAME=Laravel');

        $this->artisan('centrifugo:install')
            ->expectsOutputToContain('Broadcasting channel routes are not wired into bootstrap/app.php yet.')
            ->expectsOutputToContain('withBroadcasting')
            ->assertExitCode(0);
    }

    public function test_handle_does_not_print_instructions_when_broadcasting_is_already_wired(): void
    {
        $this->writeAppFile('bootstrap/app.php', "<?php\n\nreturn Illuminate\\Foundation\\Application::configure()->withBroadcasting(__DIR__.'/../routes/channels.php')->create();\n");
        $this->writeAppFile('.env', 'APP_NAME=Laravel');

        $this->artisan('centrifugo:install')
            ->doesntExpectOutputToContain('Broadcasting channel routes are not wired into bootstrap/app.php yet.')
            ->assertExitCode(0);
    }

    public function test_handle_never_touches_node_or_package_json(): void
    {
        $this->deleteAppFile('config/broadcasting.php');
        $this->deleteAppFile('routes/channels.php');
        $this->writeAppFile('.env', 'APP_NAME=Laravel');

        $this->artisan('centrifugo:install')->assertExitCode(0);

        $this->assertFileDoesNotExist($this->appFilePath('package.json'));
        $this->assertFileDoesNotExist($this->appFilePath('node_modules'));
    }

    public function test_handle_warns_about_an_empty_centrifugo_app_identifier(): void
    {
        $this->writeAppFile('.env', 'APP_NAME=Laravel');

        $this->artisan('centrifugo:install')
            ->expectsOutputToContain('CENTRIFUGO_APP was added empty.')
            ->assertExitCode(0);
    }

    public function test_add_environment_variables_returns_early_for_missing_file_and_skips_existing_values(): void
    {
        $command = $this->installCommand();

        $this->deleteAppFile('.env');

        $this->invokeProtected($command, 'addEnvironmentVariables');

        $this->assertFileDoesNotExist($this->appFilePath('.env'));

        $contents = implode("\n", [
            'APP_NAME=Laravel',
            'CENTRIFUGO_URL="http://localhost:8000"',
            'CENTRIFUGO_API_KEY=api-key',
            'CENTRIFUGO_TOKEN_HMAC_SECRET_KEY=secret',
            'CENTRIFUGO_APP=pos',
        ])."\n";

        $this->writeAppFile('.env', $contents);

        $this->invokeProtected($command, 'addEnvironmentVariables');

        $this->assertSame($contents, $this->readAppFile('.env'));
    }

    public function test_update_broadcasting_configuration_skips_an_existing_connection(): void
    {
        $command = $this->installCommand();
        $existingConfig = <<<'PHP'
<?php

return [
    'connections' => [
        'centrifugo' => [
            'driver' => 'centrifugo',
        ],
    ],
];
PHP;

        $this->writeAppFile('config/broadcasting.php', $existingConfig);

        $this->invokeProtected($command, 'updateBroadcastingConfiguration');

        $this->assertSame($existingConfig, $this->readAppFile('config/broadcasting.php'));
    }

    public function test_upsert_environment_variable_replaces_or_appends_the_value(): void
    {
        $command = $this->installCommand();

        $this->assertSame(
            "APP_NAME=Laravel\nBROADCAST_CONNECTION=centrifugo\n",
            $this->invokeProtected($command, 'upsertEnvironmentVariable', "APP_NAME=Laravel\nBROADCAST_CONNECTION=log\n", 'BROADCAST_CONNECTION', 'centrifugo')
        );

        $this->assertSame(
            "APP_NAME=Laravel\nBROADCAST_CONNECTION=centrifugo\n",
            $this->invokeProtected($command, 'upsertEnvironmentVariable', "APP_NAME=Laravel\n", 'BROADCAST_CONNECTION', 'centrifugo')
        );
    }

    public function test_inject_centrifugo_connection_adds_the_connection_once(): void
    {
        $command = $this->installCommand();
        $broadcastingConfig = <<<'PHP'
<?php

return [
    'connections' => [
        'log' => [
            'driver' => 'log',
        ],
    ],
];
PHP;

        $updated = $this->invokeProtected($command, 'injectCentrifugoConnection', $broadcastingConfig);

        $this->assertStringContainsString("'centrifugo' => [", $updated);
        $this->assertSame($updated, $this->invokeProtected($command, 'injectCentrifugoConnection', $updated));
    }

    private function installCommand(): InstallCommand
    {
        $kernel = $this->app->make(ConsoleKernel::class);
        $commands = $kernel->all();
        $command = $commands['centrifugo:install'];

        $this->assertInstanceOf(InstallCommand::class, $command);

        return $command;
    }

    private function invokeProtected(InstallCommand $command, string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod($command, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($command, ...$arguments);
    }
}
