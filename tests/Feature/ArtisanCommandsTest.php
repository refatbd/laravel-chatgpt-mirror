<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Tests\Feature;

use Illuminate\Support\Facades\File;
use Refatbd\LaravelChatGptMirror\Models\MirrorToken;
use Refatbd\LaravelChatGptMirror\Services\TokenRefresher;
use Refatbd\LaravelChatGptMirror\Tests\TestCase;

class ArtisanCommandsTest extends TestCase
{
    public function test_mirror_token_list_empty_state(): void
    {
        $this->artisan('mirror:token:list')
            ->expectsOutputToContain('No tokens found')
            ->assertSuccessful();
    }

    public function test_mirror_token_list_displays_table_when_tokens_exist(): void
    {
        MirrorToken::create([
            'name' => 'Primary Account',
            'email' => 'rjrefat@gmail.com',
            'session_token' => 'sample-session-token',
            'access_token' => 'sample-access-token',
            'plan_type' => 'plus',
            'status' => 'active',
            'active_requests' => 2,
            'total_requests' => 150,
        ]);

        $this->artisan('mirror:token:list')
            ->expectsOutputToContain('Primary Account')
            ->assertSuccessful();
    }

    public function test_mirror_health_reports_pool_stats_without_warp(): void
    {
        MirrorToken::create([
            'name' => 'Health Test Account',
            'email' => 'health@mirror.local',
            'session_token' => 'sess-token-123',
            'access_token' => 'acc-token-123',
            'status' => 'active',
        ]);

        $this->artisan('mirror:health')
            ->expectsOutputToContain('Active Tokens in Pool')
            ->expectsOutputToContain('Direct Egress')
            ->doesntExpectOutputToContain('WARP');
    }

    public function test_mirror_token_add_and_import_token_extract_from_full_token_txt_file(): void
    {
        $sessionA = 'sess_cli_alpha_' . str_repeat('m', 24);
        $sessionB = 'sess_cli_beta_' . str_repeat('n', 24);

        $mockRefresher = $this->createMock(TokenRefresher::class);
        $mockRefresher->method('refresh')->willReturn(true);
        $mockRefresher->method('verifyCandidateSessionToken')
            ->willReturnCallback(static fn (string $tok): array => [
                'session_token' => $tok,
                'access_token' => 'jwt-' . substr($tok, 0, 12),
                'expires_at' => now()->addHours(2),
                'email' => null,
                'name' => null,
                'account_id' => null,
                'plan_type' => null,
            ]);
        $this->app->instance(TokenRefresher::class, $mockRefresher);

        $tmpDir = sys_get_temp_dir() . '/mirror_token_import_' . bin2hex(random_bytes(4));
        File::ensureDirectoryExists($tmpDir);

        try {
            $singleFile = $tmpDir . '/token.txt';
            File::put($singleFile, (string) json_encode([
                'user' => ['name' => 'CLI Bundle User', 'email' => 'cli-bundle@example.com'],
                'account' => ['id' => 'acct-cli-1', 'planType' => 'plus'],
                'sessionToken' => $sessionA,
                'accessToken' => 'jwt-sample-a',
                'WARNING_BANNER' => 'Synthetic test bundle',
            ], JSON_PRETTY_PRINT));

            $this->artisan('mirror:token:add', ['--file' => $singleFile])
                ->expectsOutputToContain('Extracted session token')
                ->assertSuccessful();

            $added = MirrorToken::where('email', 'cli-bundle@example.com')->first();
            $this->assertNotNull($added);
            $this->assertSame($sessionA, $added->session_token);
            $this->assertSame('CLI Bundle User', $added->name);

            $multiFile = $tmpDir . '/bulk_tokens.json';
            File::put($multiFile, (string) json_encode([
                [
                    'user' => ['name' => 'Bulk One', 'email' => 'cli-bundle@example.com'],
                    'sessionToken' => $sessionA, // duplicate skipped
                ],
                [
                    'user' => ['name' => 'Bulk Two', 'email' => 'bulk-two@example.com'],
                    'account' => ['id' => 'acct-cli-2', 'planType' => 'pro'],
                    'sessionToken' => $sessionB,
                ],
            ], JSON_PRETTY_PRINT));

            $this->artisan('mirror:import-token', ['file' => $multiFile])
                ->expectsOutputToContain('bulk-two@example.com')
                ->assertSuccessful();

            $imported = MirrorToken::where('email', 'bulk-two@example.com')->first();
            $this->assertNotNull($imported);
            $this->assertSame($sessionB, $imported->session_token);
            $this->assertSame('pro', $imported->plan_type);
        } finally {
            File::deleteDirectory($tmpDir);
        }
    }
}
