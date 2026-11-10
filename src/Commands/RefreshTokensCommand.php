<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Commands;

use Illuminate\Console\Command;
use Refatbd\LaravelChatGptMirror\Models\MirrorToken;
use Refatbd\LaravelChatGptMirror\Services\TokenRefresher;
use Throwable;

class RefreshTokensCommand extends Command
{
    protected $signature = 'mirror:token:refresh {--force : Refresh even if access token is not expired}';
    protected $description = 'Refresh access tokens for all active accounts in the pool';

    public function handle(TokenRefresher $refresher): int
    {
        $force = $this->option('force');
        $tokens = MirrorToken::where('status', '!=', 'disabled')->get();

        if ($tokens->isEmpty()) {
            $this->warn('No tokens to refresh.');
            return self::SUCCESS;
        }

        $this->info("Refreshing tokens (Total: {$tokens->count()})...");

        $refreshed = 0;
        foreach ($tokens as $token) {
            if ($force || $token->needsRefresh()) {
                try {
                    $refresher->refresh($token);
                    $this->line("  ✓ Token #{$token->id} ({$token->email}) refreshed successfully.");
                    $refreshed++;
                } catch (Throwable $e) {
                    $this->error("  ✗ Token #{$token->id} refresh failed: " . $e->getMessage());
                }
            } else {
                $this->line("  - Token #{$token->id} is still fresh. Skipping.");
            }
        }

        $this->newLine();
        $this->info("Done! Refreshed {$refreshed} token(s).");

        return self::SUCCESS;
    }
}
