<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Commands;

use GuzzleHttp\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Refatbd\LaravelChatGptMirror\Services\DynamicConfigService;
use Refatbd\LaravelChatGptMirror\Services\MirrorCache;
use Refatbd\LaravelChatGptMirror\Services\TokenPoolManager;

class HealthCheckCommand extends Command
{
    protected $signature = 'mirror:health';

    protected $description = 'Check connectivity to ChatGPT upstream, SHA3-512 PoW support, Redis cache/locks, dynamic runtime config, and pool status';

    public function handle(TokenPoolManager $pool, MirrorCache $cache, DynamicConfigService $dynamicConfig): int
    {
        $dynamicConfig->ensureApplied();
        $dynState = $dynamicConfig->publicState();
        $this->info('Running ChatGPT Mirror Health Diagnostics...');

        $upstream = (string) config('mirror.upstream', 'https://chatgpt.com');
        $client = new Client([
            'timeout' => (float) config('mirror.http.timeout', 10),
            'connect_timeout' => (float) config('mirror.http.connect_timeout', 5),
            'verify' => (bool) config('mirror.http.verify_ssl', true),
            'proxy' => config('mirror.http.proxy'),
            'http_errors' => false,
        ]);

        $egressIp = 'Unknown';
        try {
            $res = $client->get('https://api.ipify.org?format=json');
            $data = json_decode((string) $res->getBody(), true);
            $egressIp = $data['ip'] ?? 'Unknown';
        } catch (\Throwable) {
            $egressIp = 'Failed to fetch IP';
        }

        $upstreamStatus = 'Unreachable';
        $upstreamOk = false;
        try {
            $res = $client->get($upstream . '/api/auth/session');
            $code = $res->getStatusCode();
            $upstreamStatus = "HTTP {$code}";
            $upstreamOk = $code >= 200 && $code < 500;
        } catch (\Throwable $e) {
            $upstreamStatus = $e->getMessage();
        }

        $powSupported = in_array('sha3-512', hash_algos(), true);
        $gdSupported = extension_loaded('gd');
        $tablesOk = Schema::hasTable('mirror_tokens')
            && Schema::hasTable('mirror_conversations')
            && Schema::hasTable('mirror_messages');

        $stats = $pool->getPoolStats();
        $cacheDiag = $cache->diagnostics();
        $zlibOn = filter_var((string) ini_get('zlib.output_compression'), FILTER_VALIDATE_BOOLEAN);
        $redisStatus = ($cacheDiag['redis_active'] ?? false)
            ? ('Active (' . ($cacheDiag['redis_ping'] ?? 'PONG') . ', ' . ($cacheDiag['latency_ms'] ?? '?') . 'ms)')
            : ('Fallback: ' . ($cacheDiag['driver'] ?? 'array') . ' (' . (($cacheDiag['phpredis_loaded'] ?? false) ? 'phpredis loaded' : (($cacheDiag['predis_installed'] ?? false) ? 'predis installed' : 'no redis client')) . ')');

        $this->table(
            ['Check', 'Result'],
            [
                ['Upstream URL', $upstream],
                ['Egress Mode', 'Direct Egress'],
                ['Public Egress IP', $egressIp],
                ['Upstream Reachability', $upstreamStatus],
                ['PHP Runtime / SAPI', PHP_VERSION . ' (' . PHP_SAPI . ')'],
                ['Server Platform Config', (string) config('mirror.server.platform', 'auto')],
                ['zlib Output Compression', $zlibOn ? 'Enabled (Auto-disabled during SSE)' : 'Off (SSE Ready)'],
                ['Stream Timeout Config', ((int) config('mirror.server.stream_max_execution_time', 0)) . 's (PHP ini: ' . ini_get('max_execution_time') . 's)'],
                ['SHA3-512 PoW Solver', $powSupported ? 'Supported' : 'Missing PHP sha3-512'],
                ['GD Image Conversion', $gdSupported ? 'Loaded' : 'Missing PHP GD'],
                ['Redis Mode', (string) ($cacheDiag['redis_mode'] ?? 'auto') . ' (desired: ' . (($cacheDiag['redis_desired'] ?? false) ? 'yes' : 'no') . ')'],
                ['Redis Client', (string) ($dynState['redis']['resolved_client'] ?? 'auto') . ' (config: ' . (string) ($dynState['redis']['client'] ?? 'auto') . ')'],
                ['Dynamic DB Overrides', ((int) ($dynState['overrides_active'] ?? 0)) . ' active'],
                ['Cache / Redis Status', $redisStatus],
                ['Distributed Locks', ($cacheDiag['locks_supported'] ?? false) ? 'Supported' : 'Single-node fallback'],
                ['Database Tables', $tablesOk ? 'OK' : 'Run migrations'],
                ['Active Tokens in Pool', "{$stats['active_tokens']} / {$stats['total_tokens']}"],
                ['Cooldown Tokens', (string) $stats['cooldown_tokens']],
                ['Active Requests Now', (string) $stats['total_active_requests']],
            ]
        );

        return ($upstreamOk && $powSupported && $tablesOk && $stats['active_tokens'] > 0)
            ? self::SUCCESS
            : self::FAILURE;
    }
}
