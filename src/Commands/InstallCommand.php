<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class InstallCommand extends Command
{
    protected $signature = 'mirror:install {--force : Overwrite existing configuration}';
    protected $description = 'Install and bootstrap the Laravel ChatGPT Mirror package';

    public function handle(): int
    {
        $this->info('🚀 Installing Laravel ChatGPT Mirror...');

        // 1. Publish configuration
        $this->call('vendor:publish', [
            '--provider' => 'Refatbd\LaravelChatGptMirror\MirrorServiceProvider',
            '--tag' => 'mirror-config',
            '--force' => $this->option('force'),
        ]);

        // 2. Publish and run migrations
        $this->info('📦 Running database migrations for token storage...');
        $this->call('migrate');

        // 3. Check for MIRROR_API_KEY in .env
        $this->ensureApiKey();

        $this->newLine();
        $this->info('✅ Laravel ChatGPT Mirror successfully installed!');
        $this->line('👉 Add your first ChatGPT session token: <comment>php artisan mirror:token:add</comment>');
        $this->line('👉 Or import tokens from a file: <comment>php artisan mirror:token:import token.txt</comment>');

        return self::SUCCESS;
    }

    protected function ensureApiKey(): void
    {
        $envPath = base_path('.env');
        if (!file_exists($envPath)) {
            return;
        }

        $envContent = file_get_contents($envPath);
        if (!str_contains($envContent, 'MIRROR_API_KEY')) {
            $apiKey = 'sk-mirror-' . Str::random(40);
            file_put_contents($envPath, PHP_EOL . "MIRROR_API_KEY={$apiKey}" . PHP_EOL, FILE_APPEND);
            $this->info("🔑 Generated new MIRROR_API_KEY in .env: {$apiKey}");
        }
    }
}
