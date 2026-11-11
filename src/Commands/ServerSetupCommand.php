<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Refatbd\LaravelChatGptMirror\Services\MirrorCache;
use Symfony\Component\Console\Output\OutputInterface;

class ServerSetupCommand extends Command
{
    private const BLOCK_START = '# BEGIN LARAVEL CHATGPT MIRROR';
    private const BLOCK_END = '# END LARAVEL CHATGPT MIRROR';

    protected $signature = 'mirror:server
        {target=auto : Web server target (auto, apache, webuzo, nginx, litespeed, caddy, octane)}
        {--write : Idempotently update public/.htaccess with Apache/LiteSpeed Bearer Auth & unbuffered SSE rules}
        {--vhost : Print the production VirtualHost / Server block / Caddyfile / Octane configuration}
        {--path= : Custom .htaccess path (defaults to public/.htaccess)}';

    /** @var array<int, string> */
    protected $aliases = ['mirror:apache'];

    protected $description = 'Configure web server rewrites, Bearer Authorization forwarding, unbuffered SSE streaming, and Redis deployment templates';

    public function handle(MirrorCache $cache): int
    {
        $rawTarget = strtolower(trim((string) $this->argument('target')));
        $invokedAs = strtolower((string) ($this->input?->getFirstArgument() ?? 'mirror:server'));
        if ($rawTarget === 'auto' && str_contains($invokedAs, 'apache')) {
            $rawTarget = 'apache';
        }

        $target = $this->resolveTarget($rawTarget);
        $writeHtaccess = (bool) $this->option('write');
        $showVhost = (bool) $this->option('vhost');

        $this->info("Laravel ChatGPT Mirror — Universal Server Setup (target: {$target})");

        if ($writeHtaccess) {
            if (in_array($target, ['apache', 'webuzo', 'litespeed', 'openlitespeed'], true)) {
                $htaccessPath = $this->resolveHtaccessPath();
                $this->writeHtaccessRules($htaccessPath);
            } else {
                $this->warn("Target '{$target}' does not use .htaccess. Printing server configuration instead.");
                $showVhost = true;
            }
        }

        if ($showVhost || !$writeHtaccess) {
            $this->printVhostTemplate($target);
        }

        $cacheDiag = $cache->diagnostics();
        $this->newLine();
        $this->line('<comment>Recommended .env for Multi-Worker Production + Redis:</comment>');
        $this->line("  MIRROR_SERVER_PLATFORM={$target}");
        $this->line('  MIRROR_TRUST_PROXIES=true');
        $this->line('  MIRROR_CACHE_STORE=mirror_redis');
        $this->line('  MIRROR_REDIS_CLIENT=' . (($cacheDiag['phpredis_loaded'] ?? false) ? 'phpredis' : (($cacheDiag['predis_installed'] ?? false) ? 'predis' : 'phpredis')));
        $this->line('  MIRROR_REDIS_HOST=127.0.0.1');
        $this->line('  MIRROR_REDIS_PORT=6379');
        $this->newLine();
        $this->line('Redis Status Now: ' . (($cacheDiag['redis_active'] ?? false)
            ? '<info>ACTIVE (' . ($cacheDiag['redis_ping'] ?? 'PONG') . ')</info>'
            : 'Fallback (' . ($cacheDiag['driver'] ?? 'array') . ') — set MIRROR_REDIS_HOST and ensure phpredis/predis is installed.'));

        return self::SUCCESS;
    }

    protected function resolveTarget(string $raw): string
    {
        return match ($raw) {
            'auto', '' => $this->detectDefaultTarget(),
            'webuzo' => 'apache',
            'openlitespeed' => 'litespeed',
            'frankenphp' => 'caddy',
            default => $raw,
        };
    }

    protected function detectDefaultTarget(): string
    {
        $configured = strtolower(trim((string) config('mirror.server.platform', 'auto')));
        if ($configured !== '' && $configured !== 'auto') {
            return $configured;
        }

        $software = strtolower((string) ($_SERVER['SERVER_SOFTWARE'] ?? ''));
        if (str_contains($software, 'litespeed')) {
            return 'litespeed';
        }
        if (str_contains($software, 'nginx')) {
            return 'nginx';
        }
        if (str_contains($software, 'caddy') || str_contains($software, 'frankenphp')) {
            return 'caddy';
        }

        return 'apache';
    }

    protected function resolveHtaccessPath(): string
    {
        $custom = trim((string) ($this->option('path') ?? ''));
        if ($custom !== '') {
            return $custom;
        }

        if (function_exists('public_path')) {
            return public_path('.htaccess');
        }

        return base_path('public/.htaccess');
    }

    protected function writeHtaccessRules(string $htaccessPath): void
    {
        File::ensureDirectoryExists(dirname($htaccessPath));

        $mirrorBlock = implode("\n", [
            self::BLOCK_START,
            '<IfModule mod_env.c>',
            '    SetEnvIfNoCase Request_URI "^/?(v1|api/chat|backend-api|__codex-api)/" no-gzip=1 no-brotli=1 dont-vary=1 MIRROR_SSE=1',
            '</IfModule>',
            '',
            '<IfModule mod_headers.c>',
            '    Header always set X-Accel-Buffering "no" env=MIRROR_SSE',
            '    Header always set X-LiteSpeed-Cache-Control "no-cache" env=MIRROR_SSE',
            '</IfModule>',
            '',
            '<IfModule mod_rewrite.c>',
            '    RewriteEngine On',
            '    RewriteCond %{HTTP:Authorization} .',
            '    RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]',
            '</IfModule>',
            '',
            '<IfModule mod_setenvif.c>',
            '    SetEnvIfNoCase ^Authorization$ "(.+)" HTTP_AUTHORIZATION=$1',
            '</IfModule>',
            self::BLOCK_END,
        ]);

        if (File::exists($htaccessPath)) {
            $existing = (string) File::get($htaccessPath);
            $cleaned = preg_replace(
                '/' . preg_quote(self::BLOCK_START, '/') . '.*?' . preg_quote(self::BLOCK_END, '/') . '\r?\n?/s',
                '',
                $existing
            );
            $trimmedExisting = ltrim((string) $cleaned);
            $finalContent = $mirrorBlock . "\n\n" . $trimmedExisting;
        } else {
            $finalContent = $mirrorBlock . "\n\n" . implode("\n", [
                '<IfModule mod_rewrite.c>',
                '    <IfModule mod_negotiation.c>',
                '        Options -MultiViews -Indexes',
                '    </IfModule>',
                '    RewriteEngine On',
                '    RewriteCond %{REQUEST_FILENAME} !-d',
                '    RewriteCond %{REQUEST_URI} (.+)/$',
                '    RewriteRule ^ %1 [L,R=301]',
                '    RewriteCond %{REQUEST_FILENAME} !-d',
                '    RewriteCond %{REQUEST_FILENAME} !-f',
                '    RewriteRule ^ index.php [L]',
                '</IfModule>',
                '',
            ]);
        }

        File::put($htaccessPath, $finalContent);
        $this->info("Updated {$htaccessPath} idempotently with Mirror Apache/LiteSpeed rules.");
    }

    protected function printVhostTemplate(string $target): void
    {
        $base = __DIR__ . '/../../resources/deploy';
        $templateFile = match ($target) {
            'nginx' => $base . '/nginx/mirror.conf',
            'caddy', 'frankenphp' => $base . '/caddy/Caddyfile.mirror',
            default => $base . '/apache/webuzo-vhost.conf',
        };

        $this->newLine();
        if (File::exists($templateFile)) {
            $this->line("<comment>--- Template ({$target}): {$templateFile} ---</comment>");
            $lines = preg_split('/\r\n|\r|\n/', trim((string) File::get($templateFile))) ?: [];
            foreach ($lines as $line) {
                $this->output->writeln($line, OutputInterface::OUTPUT_RAW);
            }
        }

        if ($target === 'octane') {
            $this->newLine();
            $this->line('<comment>--- Laravel Octane / Swoole / FrankenPHP Notes ---</comment>');
            $this->line('  php artisan octane:start --host=127.0.0.1 --port=8000 --workers=4 --max-requests=500');
            $this->line('  Place Nginx/Caddy in front with proxy_buffering off / flush_interval -1 for SSE routes.');
        }

        if (in_array($target, ['apache', 'webuzo'], true)) {
            $this->newLine();
            $this->line('<comment>Webuzo Apache Checklist:</comment>');
            $this->line('  1. Run `php artisan mirror:server apache --write` (or `mirror:apache --write`) to update `public/.htaccess`.');
            $this->line('  2. Add `AllowEncodedSlashes NoDecode`, `CGIPassAuth On`, and `Timeout 300` in Webuzo Apache Extra Configuration.');
        }
    }
}
