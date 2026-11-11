<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Commands;

use Illuminate\Console\Command;
use Refatbd\LaravelChatGptMirror\Models\MirrorToken;
use Refatbd\LaravelChatGptMirror\Services\TokenRefresher;

class ImportTokenCommand extends Command
{
    protected $signature = 'mirror:import-token
                            {file? : Optional path to a file containing session tokens (one per line)}
                            {--name=Imported Account : Default name prefix}
                            {--turnstile= : Optional Cloudflare Turnstile token}';

    protected $description = 'Bulk import or interactively paste ChatGPT __Secure-next-auth.session-token credentials';

    public function handle(TokenRefresher $refresher): int
    {
        $file = $this->argument('file');
        $tokensToImport = [];

        if ($file && is_string($file) && file_exists($file)) {
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines) {
                $tokensToImport = array_map('trim', $lines);
            }
        } else {
            $this->info('Paste your __Secure-next-auth.session-token value:');
            $token = trim((string) $this->secret('Session Token'));
            if ($token !== '') {
                $tokensToImport[] = $token;
            }
        }

        $tokensToImport = array_values(array_filter(array_map('trim', $tokensToImport)));
        if ($tokensToImport === []) {
            $this->error('No valid tokens provided.');
            return self::FAILURE;
        }

        $turnstile = $this->option('turnstile');
        $imported = 0;

        foreach ($tokensToImport as $idx => $rawToken) {
            $hash = hash('sha256', $rawToken);
            if (MirrorToken::where('token_hash', $hash)->exists()) {
                $this->warn('Skipped duplicate session token #' . ($idx + 1));
                continue;
            }

            $verified = $refresher->verifyCandidateSessionToken($rawToken);
            if (!$verified) {
                $this->error('Token #' . ($idx + 1) . ' rejected by ChatGPT upstream.');
                continue;
            }

            $tokenRecord = MirrorToken::create([
                'name' => count($tokensToImport) > 1
                    ? $this->option('name') . ' #' . ($idx + 1)
                    : ($verified['name'] ?: $this->option('name')),
                'session_token' => $verified['session_token'],
                'token_hash' => hash('sha256', $verified['session_token']),
                'access_token' => $verified['access_token'],
                'access_token_expires_at' => $verified['expires_at'],
                'email' => $verified['email'],
                'account_id' => $verified['account_id'],
                'plan_type' => $verified['plan_type'] ?? 'free',
                'status' => 'active',
                'last_refreshed_at' => now(),
            ]);

            if (is_string($turnstile) && trim($turnstile) !== '') {
                $tokenRecord->setTurnstileToken($turnstile);
                $tokenRecord->save();
            }

            $this->info("Token #{$tokenRecord->id} verified ({$tokenRecord->email}) and imported cleanly!");
            $imported++;
        }

        return $imported > 0 ? self::SUCCESS : self::FAILURE;
    }
}
