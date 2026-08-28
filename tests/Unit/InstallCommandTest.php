<?php

declare(strict_types=1);

namespace Jestays\Centrifugo\Tests\Unit;

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Jestays\Centrifugo\Commands\InstallCommand;
use Jestays\Centrifugo\Tests\Support\TemporaryLaravelAppTestCase;
use ReflectionMethod;

final class RecordingBroadcastingInstallCommand extends Command
{
    protected $signature = 'install:broadcasting';

    protected $description = 'Record broadcasting installation';

    public function handle(): int
    {
        $configPath = app()->configPath('broadcasting.php');

        if (! is_dir(dirname($configPath))) {
            mkdir(dirname($configPath), 0777, true);
        }

        file_put_contents($configPath, <<<'PHP'
<?php

return [
    'connections' => [
        'log' => [
            'driver' => 'log',
        ],
    ],
];
PHP);

        $routesPath = base_path('routes/channels.php');

        if (! is_dir(dirname($routesPath))) {
            mkdir(dirname($routesPath), 0777, true);
        }

        file_put_contents($routesPath, "<?php\n");

        return self::SUCCESS;
    }
}

final class InstallCommandTest extends TemporaryLaravelAppTestCase
{
    public function test_handle_runs_the_full_install_flow_against_a_real_filesystem(): void
    {
        $this->registerRecordingBroadcastingInstallCommand();

        $this->deleteAppFile('config/broadcasting.php');
        $this->deleteAppFile('routes/channels.php');
        $this->writeAppFile('.env', 'APP_NAME=Laravel');

        $this->artisan('centrifugo:install')
            ->expectsConfirmation('Would you like to enable event broadcasting?', 'yes')
            ->assertExitCode(0);

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
        $this->assertSame("<?php\n", $this->readAppFile('routes/channels.php'));
        $this->assertFileExists($this->appFilePath('config/centrifugo.php'));
    }

    public function test_handle_warns_about_an_empty_centrifugo_app_identifier(): void
    {
        $this->registerRecordingBroadcastingInstallCommand();

        $this->writeAppFile('routes/channels.php', "<?php\n");
        $this->writeAppFile('.env', 'APP_NAME=Laravel');

        $this->artisan('centrifugo:install')
            ->expectsOutputToContain('CENTRIFUGO_APP was added empty.')
            ->assertExitCode(0);
    }

    public function test_handle_skips_installer_when_user_declines_and_warns_about_missing_config(): void
    {
        $this->registerRecordingBroadcastingInstallCommand();

        $this->deleteAppFile('config/broadcasting.php');
        $this->deleteAppFile('routes/channels.php');
        $this->writeAppFile('.env', "APP_NAME=Laravel\n");

        $this->artisan('centrifugo:install')
            ->expectsConfirmation('Would you like to enable event broadcasting?', 'no')
            ->expectsOutputToContain('Skipping Centrifugo broadcasting configuration because config/broadcasting.php was not found.')
            ->assertExitCode(0);

        $env = $this->readAppFile('.env');

        $this->assertStringContainsString('CENTRIFUGO_TOKEN_HMAC_SECRET_KEY=', $env);
        $this->assertStringContainsString('BROADCAST_CONNECTION=centrifugo', $env);
        $this->assertFileDoesNotExist($this->appFilePath('routes/channels.php'));
        $this->assertFileDoesNotExist($this->appFilePath('config/broadcasting.php'));
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

    private function registerRecordingBroadcastingInstallCommand(): void
    {
        $this->app->make(ConsoleKernel::class)->registerCommand(new RecordingBroadcastingInstallCommand);
    }
}
