<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Commands;

use GuzzleHttp\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Refatbd\LaravelChatGptMirror\Services\TokenPoolManager;

class HealthCheckCommand extends Command
{
    protected $signature = 'mirror:health';

    protected $description = 'Check connectivity to ChatGPT upstream, SHA3-512 PoW support, database schema, and pool status';

    public function handle(TokenPoolManager $pool): int
    {
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

        $this->table(
            ['Check', 'Result'],
            [
                ['Upstream URL', $upstream],
                ['Egress Mode', 'Direct Egress'],
                ['Public Egress IP', $egressIp],
                ['Upstream Reachability', $upstreamStatus],
                ['SHA3-512 PoW Solver', $powSupported ? 'Supported' : 'Missing PHP sha3-512'],
                ['GD Image Conversion', $gdSupported ? 'Loaded' : 'Missing PHP GD'],
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
