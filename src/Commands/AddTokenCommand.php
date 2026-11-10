<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Commands;

use Illuminate\Console\Command;
use Refatbd\LaravelChatGptMirror\Models\MirrorToken;
use Refatbd\LaravelChatGptMirror\Services\TokenRefresher;
use Throwable;

class AddTokenCommand extends Command
{
    protected $signature = 'mirror:token:add {token? : ChatGPT __Secure-next-auth.session-token value} {--name= : Optional friendly label for the account}';
    protected $description = 'Add a new ChatGPT session token to the load-balancing pool';

    public function handle(TokenRefresher $refresher): int
    {
        $rawToken = $this->argument('token');

        if (empty($rawToken)) {
            $rawToken = $this->secret('Enter ChatGPT __Secure-next-auth.session-token');
        }

        if (empty($rawToken)) {
            $this->error('Token cannot be empty.');
            return self::FAILURE;
        }

        $cleanToken = trim($rawToken);
        $name = $this->option('name') ?: ('Account #' . (MirrorToken::count() + 1));

        $this->info("Validating and fetching session metadata from upstream...");

        $token = new MirrorToken([
            'name' => $name,
            'session_token' => $cleanToken,
            'status' => 'active',
        ]);
        $token->save();

        try {
            $refresher->refresh($token);
            $token->refresh();

            $this->newLine();
            $this->info("✅ Token successfully added and validated!");
            $this->table(
                ['ID', 'Name', 'Email', 'Plan', 'Expires At', 'Status'],
                [[
                    $token->id,
                    $token->name,
                    $token->email ?: 'N/A',
                    strtoupper($token->plan_type),
                    $token->access_token_expires_at?->toDateTimeString() ?: 'N/A',
                    $token->status,
                ]]
            );

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error("⚠️ Token was saved, but verification failed: " . $e->getMessage());
            $this->line("Check your internet connection or verify the session token validity.");
            return self::FAILURE;
        }
    }
}
