<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Refatbd\LaravelChatGptMirror\Services\DynamicConfigService;
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
        {--path= : Custom .htaccess path (defaults to public/.htaccess)}
        {--domain= : Server domain name (defaults to APP_URL host or example.com)}
        {--root= : Public document root path (defaults to public_path())}
        {--php-version= : Target PHP version for FPM socket paths (defaults to running PHP major.minor)}
        {--socket= : Custom PHP-FPM Unix socket or host:port (overrides default FPM socket)}
        {--host= : Bind host for Octane server output (defaults to 127.0.0.1)}
        {--port= : Bind port for Octane server output (defaults to 8000)}';

    /** @var array<int, string> */
    protected $aliases = ['mirror:apache'];

    protected $description = 'Configure web server rewrites, Bearer Authorization forwarding, unbuffered SSE streaming, and Redis deployment templates';

    public function handle(MirrorCache $cache, DynamicConfigService $dynamicConfig): int
    {
        $dynamicConfig->ensureApplied();

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
        $dynState = $dynamicConfig->publicState();
        $redisHost = (string) (($dynState['redis']['host'] ?? '') !== '' ? $dynState['redis']['host'] : '127.0.0.1');
        $redisPort = (int) ($dynState['redis']['port'] ?? 6379);
        $redisMode = (string) ($dynState['redis']['enabled'] ?? 'auto');
        $redisClient = (string) ($dynState['redis']['client'] ?? 'auto');
        $trustProxies = ($dynState['server']['trust_proxies'] ?? true) ? 'true' : 'false';
        $adminRoute = '/' . trim((string) config('mirror.routes.admin_prefix', 'mirror/admin'), '/');

        $this->newLine();
        $this->line('<comment>Dynamic Environment Baseline (.env or override live in ' . $adminRoute . '):</comment>');
        $this->line("  MIRROR_SERVER_PLATFORM={$target}");
        $this->line("  MIRROR_TRUST_PROXIES={$trustProxies}");
        $this->line("  MIRROR_REDIS_ENABLED={$redisMode}   # auto | enabled | disabled");
        $this->line("  MIRROR_REDIS_CLIENT={$redisClient}    # auto | phpredis | predis");
        $this->line("  MIRROR_REDIS_HOST={$redisHost}");
        $this->line("  MIRROR_REDIS_PORT={$redisPort}");
        $this->newLine();
        $this->line('Redis Status Now: ' . (($cacheDiag['redis_active'] ?? false)
            ? '<info>ACTIVE (' . ($cacheDiag['redis_ping'] ?? 'PONG') . ')</info>'
            : 'Fallback (' . ($cacheDiag['driver'] ?? 'array') . ', mode: ' . $redisMode . ') — app runs normally without Redis; enable anytime via .env or Admin Dashboard.'));

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
        $sseAlternation = $this->ssePathAlternation();

        $mirrorBlock = implode("\n", [
            self::BLOCK_START,
            '<IfModule mod_env.c>',
            "    SetEnvIfNoCase Request_URI \"^/?({$sseAlternation})/\" no-gzip=1 no-brotli=1 dont-vary=1 MIRROR_SSE=1",
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

        $domain = $this->resolvedDomain();
        $docRoot = $this->resolvedRoot();
        $phpVersion = $this->resolvedPhpVersion();
        $nginxSocket = $this->resolvedSocket("unix:/run/php/php{$phpVersion}-fpm.sock");
        $caddyFastcgi = $this->resolvedSocket('127.0.0.1:9000');
        $sseAlternation = $this->ssePathAlternation();
        $caddyPaths = $this->caddySsePaths();
        $streamTimeout = max(60, (int) config('mirror.server.stream_max_execution_time', 300));
        $octaneHost = trim((string) ($this->option('host') ?? '')) ?: '127.0.0.1';
        $octanePort = trim((string) ($this->option('port') ?? '')) ?: '8000';

        $this->newLine();
        if (File::exists($templateFile)) {
            $this->line("<comment>--- Template ({$target} | domain: {$domain} | root: {$docRoot}) ---</comment>");
            $raw = trim((string) File::get($templateFile));
            $rendered = strtr($raw, [
                'YOUR_DOMAIN.COM' => $domain,
                '/home/USERNAME/public_html/public' => $docRoot,
                '/var/www/YOUR_APP/public' => $docRoot,
                'unix:/run/php/php8.3-fpm.sock' => $nginxSocket,
                '127.0.0.1:9000' => $caddyFastcgi,
                '^/(v1|api/chat|backend-api|__codex-api)/' => "^/({$sseAlternation})/",
                '/v1/* /api/chat* /backend-api/* /__codex-api/*' => $caddyPaths,
                'Timeout 300' => "Timeout {$streamTimeout}",
                'ProxyTimeout 300' => "ProxyTimeout {$streamTimeout}",
                'fastcgi_read_timeout 300s;' => "fastcgi_read_timeout {$streamTimeout}s;",
                'fastcgi_send_timeout 300s;' => "fastcgi_send_timeout {$streamTimeout}s;",
            ]);

            $lines = preg_split('/\r\n|\r|\n/', $rendered) ?: [];
            foreach ($lines as $line) {
                $this->output->writeln($line, OutputInterface::OUTPUT_RAW);
            }
        }

        if ($target === 'octane') {
            $this->newLine();
            $this->line('<comment>--- Laravel Octane / Swoole / FrankenPHP Notes ---</comment>');
            $this->line("  php artisan octane:start --host={$octaneHost} --port={$octanePort} --workers=4 --max-requests=500");
            $this->line('  Place Nginx/Caddy in front with proxy_buffering off / flush_interval -1 for SSE routes.');
        }

        if (in_array($target, ['apache', 'webuzo'], true)) {
            $this->newLine();
            $this->line('<comment>Webuzo Apache Checklist:</comment>');
            $this->line('  1. Run `php artisan mirror:server apache --write` (or `mirror:apache --write`) to update `public/.htaccess`.');
            $this->line("  2. Add `AllowEncodedSlashes NoDecode`, `CGIPassAuth On`, and `Timeout {$streamTimeout}` in Webuzo Apache Extra Configuration.");
        }
    }

    /**
     * @return array<int, string>
     */
    protected function ssePathSegments(): array
    {
        $openai = trim((string) config('mirror.routes.openai_prefix', 'v1'), '/');
        $backend = trim((string) config('mirror.routes.backend_prefix', '__codex-api'), '/');

        $segments = array_filter([
            $openai !== '' ? $openai : 'v1',
            'api/chat',
            'backend-api',
            $backend !== '' ? $backend : '__codex-api',
        ], static fn (string $s): bool => $s !== '');

        return array_values(array_unique($segments));
    }

    protected function ssePathAlternation(): string
    {
        return implode('|', array_map(
            static fn (string $seg): string => (string) preg_replace('/([.+*?^$()\[\]{}|\\\\])/', '\\\\$1', $seg),
            $this->ssePathSegments()
        ));
    }

    protected function caddySsePaths(): string
    {
        return implode(' ', array_map(
            static fn (string $seg): string => '/' . $seg . '/*',
            $this->ssePathSegments()
        ));
    }

    protected function resolvedDomain(): string
    {
        $opt = trim((string) ($this->option('domain') ?? ''));
        if ($opt !== '') {
            return $opt;
        }

        $appUrl = trim((string) config('app.url', ''));
        $host = $appUrl !== '' ? (string) (parse_url($appUrl, PHP_URL_HOST) ?? '') : '';

        return $host !== '' ? $host : 'example.com';
    }

    protected function resolvedRoot(): string
    {
        $opt = trim((string) ($this->option('root') ?? ''));
        if ($opt !== '') {
            return $opt;
        }

        if (function_exists('public_path')) {
            return public_path();
        }

        return base_path('public');
    }

    protected function resolvedPhpVersion(): string
    {
        $opt = trim((string) ($this->option('php-version') ?? ''));
        if ($opt !== '' && preg_match('/^\d+\.\d+/', $opt, $m)) {
            return $m[0];
        }

        return PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
    }

    protected function resolvedSocket(string $default): string
    {
        $opt = trim((string) ($this->option('socket') ?? ''));
        return $opt !== '' ? $opt : $default;
    }
}
