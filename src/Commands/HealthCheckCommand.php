<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Commands;

use GuzzleHttp\Client;
use Illuminate\Console\Command;
use Refatbd\LaravelChatGptMirror\Services\TokenPoolManager;
use Throwable;

class HealthCheckCommand extends Command
{
    protected $signature = 'mirror:health';
    protected $description = 'Perform an operational health check on Mirror upstream egress and token pool';

    public function handle(TokenPoolManager $poolManager): int
    {
        $this->info("🔍 Running Laravel ChatGPT Mirror Diagnostic...");
        $this->newLine();

        // 1. Direct Upstream Egress Check
        $upstream = config('mirror.upstream', 'https://chatgpt.com');
        $this->line("1. Testing Direct Upstream Connectivity (<comment>{$upstream}</comment>)...");

        $http = new Client([
            'base_uri' => $upstream,
            'timeout' => 10,
            'verify' => (bool) config('mirror.egress.verify_ssl', true),
            'proxy' => config('mirror.egress.proxy'),
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/134.0.0.0 Safari/537.36',
            ],
        ]);

        try {
            $resp = $http->get('/', ['http_errors' => false]);
            $this->info("   ✓ Direct Network Egress: Reachable (HTTP {$resp->getStatusCode()})");
        } catch (Throwable $e) {
            $this->error("   ✗ Direct Network Egress Failed: " . $e->getMessage());
        }

        // 2. Token Pool Health Check
        $this->newLine();
        $this->line("2. Inspecting Token Pool Status...");
        $stats = $poolManager->getPoolStats();

        $this->table(['Metric', 'Value'], [
            ['Total Configured Tokens', $stats['total_tokens']],
            ['Active Healthy Tokens', $stats['active_healthy']],
            ['Tokens in Cooldown', $stats['in_cooldown']],
            ['Current In-Flight Requests', $stats['total_in_flight_requests']],
            ['Total Requests Served', $stats['total_requests_served']],
            ['Load Balancing Strategy', config('mirror.pool.strategy', 'least_busy')],
            ['Egress Mode', config('mirror.egress.mode', 'direct') . ' (WARP: Disabled)'],
        ]);

        if ($stats['active_healthy'] > 0) {
            $this->info("✅ Mirror is operational and ready to serve traffic!");
            return self::SUCCESS;
        }

        $this->warn("⚠️ No active tokens found in the pool. Use `php artisan mirror:token:add` to register a session token.");
        return self::FAILURE;
    }
}
