<?php

declare(strict_types=1);

namespace Jestays\Centrifugo\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'centrifugo:install')]
final class InstallCommand extends Command
{
    protected $signature = 'centrifugo:install';

    protected $description = 'Install the Centrifugo broadcasting driver';

    public function handle(): void
    {
        $this->call('vendor:publish', ['--tag' => 'centrifugo-config']);

        $this->addEnvironmentVariables();
        $this->ensureBroadcastingIsInstalled();
        $this->updateBroadcastingConfiguration();
        $this->updateBroadcastConnection();
        $this->printSummary();

        $this->components->info('Centrifugo Laravel installed successfully.');
    }

    protected function addEnvironmentVariables(): void
    {
        if (File::missing($env = app()->environmentFile())) {
            return;
        }

        $contents = File::get($env);

        $variables = Arr::where([
            'CENTRIFUGO_URL' => 'CENTRIFUGO_URL="http://localhost:8000"',
            'CENTRIFUGO_API_KEY' => 'CENTRIFUGO_API_KEY='.Str::uuid()->toString(),
            'CENTRIFUGO_TOKEN_HMAC_SECRET_KEY' => 'CENTRIFUGO_TOKEN_HMAC_SECRET_KEY='.Str::uuid()->toString(),
            'CENTRIFUGO_APP' => 'CENTRIFUGO_APP=',
        ], fn ($value, $key) => ! Str::contains($contents, PHP_EOL.$key));

        if ($variables === []) {
            return;
        }

        File::append(
            $env,
            (Str::endsWith($contents, PHP_EOL) ? PHP_EOL : PHP_EOL.PHP_EOL).implode(PHP_EOL, $variables).PHP_EOL,
        );

        if (array_key_exists('CENTRIFUGO_APP', $variables)) {
            $this->components->warn('CENTRIFUGO_APP was added empty. Set a unique identifier for this application, e.g. CENTRIFUGO_APP=pos.');
        }
    }

    protected function ensureBroadcastingIsInstalled(): void
    {
        $broadcastingConfig = app()->configPath('broadcasting.php');

        if (File::exists($broadcastingConfig) && File::exists(base_path('routes/channels.php'))) {
            return;
        }

        if (! $this->getApplication()->has('install:broadcasting')) {
            return;
        }

        if (! $this->confirm('Would you like to enable event broadcasting?', true)) {
            return;
        }

        $this->call('install:broadcasting', ['--no-interaction' => true]);
    }

    protected function updateBroadcastingConfiguration(): void
    {
        $broadcastingConfig = app()->configPath('broadcasting.php');

        if (File::missing($broadcastingConfig)) {
            $this->components->warn('Skipping Centrifugo broadcasting configuration because config/broadcasting.php was not found.');

            return;
        }

        $contents = File::get($broadcastingConfig);
        $updated = $this->injectCentrifugoConnection($contents);

        if ($updated !== $contents) {
            File::put($broadcastingConfig, $updated);
        }
    }

    protected function updateBroadcastConnection(): void
    {
        if (File::missing($env = app()->environmentFile())) {
            return;
        }

        File::put($env, $this->upsertEnvironmentVariable(File::get($env), 'BROADCAST_CONNECTION', 'centrifugo'));
    }

    protected function injectCentrifugoConnection(string $contents): string
    {
        if (Str::contains($contents, "'centrifugo' => [")) {
            return $contents;
        }

        $connection = <<<'CONFIG'

        'centrifugo' => [
            'driver' => 'centrifugo',
        ],
CONFIG;

        $updated = preg_replace(
            "/('connections'\s*=>\s*\[\s*\R)/",
            "$1{$connection}\n\n",
            $contents,
            1,
            $count,
        );

        return $count === 1 && is_string($updated) ? $updated : $contents;
    }

    protected function upsertEnvironmentVariable(string $contents, string $key, string $value): string
    {
        $pattern = "/^{$key}=.*$/m";
        $replacement = "{$key}={$value}";

        if (preg_match($pattern, $contents) === 1) {
            return (string) preg_replace($pattern, $replacement, $contents);
        }

        return Str::endsWith($contents, PHP_EOL)
            ? $contents.$replacement.PHP_EOL
            : $contents.PHP_EOL.$replacement.PHP_EOL;
    }

    protected function printSummary(): void
    {
        $env = app()->environmentFile();
        $contents = File::exists($env) ? File::get($env) : '';

        $this->components->twoColumnDetail('Config file', 'config/centrifugo.php');

        foreach (['CENTRIFUGO_URL', 'CENTRIFUGO_API_KEY', 'CENTRIFUGO_TOKEN_HMAC_SECRET_KEY', 'CENTRIFUGO_APP', 'BROADCAST_CONNECTION'] as $key) {
            $this->components->twoColumnDetail($key, $this->readEnvironmentVariable($contents, $key) ?? '(not set)');
        }
    }

    protected function readEnvironmentVariable(string $contents, string $key): ?string
    {
        if (preg_match("/^{$key}=(.*)$/m", $contents, $matches) !== 1) {
            return null;
        }

        $value = trim($matches[1], "\"'");

        return $value === '' ? null : $value;
    }
}
