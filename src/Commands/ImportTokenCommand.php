<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Commands;

use Illuminate\Console\Command;
use Refatbd\LaravelChatGptMirror\Models\MirrorToken;
use Refatbd\LaravelChatGptMirror\Services\TokenRefresher;
use Throwable;

class ImportTokenCommand extends Command
{
    protected $signature = 'mirror:token:import {file : Path to the file containing session token(s) or auth JSON}';
    protected $description = 'Import one or more ChatGPT session tokens from a file (e.g. token.txt or session JSON)';

    public function handle(TokenRefresher $refresher): int
    {
        $filePath = $this->argument('file');

        if (!file_exists($filePath)) {
            $filePath = base_path($filePath);
        }

        if (!file_exists($filePath)) {
            $this->error("File not found: {$this->argument('file')}");
            return self::FAILURE;
        }

        $content = file_get_contents($filePath);
        $json = json_decode(trim($content), true);

        // If it's a full /api/auth/session JSON dump
        if (is_array($json) && (isset($json['accessToken']) || isset($json['sessionToken']))) {
            $this->info("Detected full ChatGPT session dump. Importing account directly...");

            $sessionToken = $json['sessionToken'] ?? ($json['accessToken'] ?? '');
            $accessToken = $json['accessToken'] ?? null;
            $email = $json['user']['email'] ?? null;
            $name = $json['user']['name'] ?? 'Imported Account';
            $planType = $json['account']['planType'] ?? 'free';
            $accountId = $json['account']['id'] ?? ($json['user']['id'] ?? null);
            $expiresAt = isset($json['expires']) ? \Carbon\Carbon::parse($json['expires']) : now()->addDays(7);

            $token = MirrorToken::create([
                'name' => $name,
                'email' => $email,
                'session_token' => $sessionToken,
                'access_token' => $accessToken,
                'access_token_expires_at' => $expiresAt,
                'plan_type' => $planType,
                'account_id' => $accountId,
                'status' => 'active',
                'last_refreshed_at' => now(),
                'meta' => $json,
            ]);

            $this->info("🎉 Successfully imported session for {$name} ({$email}) [Plan: {$planType}]!");
            return self::SUCCESS;
        }

        $extractedTokens = $this->extractTokens($content);

        if (empty($extractedTokens)) {
            $this->error("Could not find any valid session tokens in {$filePath}.");
            return self::FAILURE;
        }

        $this->info("Found " . count($extractedTokens) . " token(s) in file. Importing into pool...");

        $successCount = 0;
        foreach ($extractedTokens as $idx => $rawToken) {
            $name = 'Imported Token #' . ($idx + 1);
            $token = new MirrorToken([
                'name' => $name,
                'session_token' => $rawToken,
                'status' => 'active',
            ]);
            $token->save();

            try {
                $refresher->refresh($token);
                $this->line("  ✓ Token #{$token->id} ({$token->email}) validated.");
                $successCount++;
            } catch (Throwable $e) {
                $this->warn("  ⚠ Token #{$token->id} saved with warning: " . $e->getMessage());
            }
        }

        $this->newLine();
        $this->info("🎉 Successfully imported {$successCount} token(s)!");

        return self::SUCCESS;
    }

    protected function extractTokens(string $content): array
    {
        $tokens = [];
        $trimmed = trim($content);

        // 1. Try parsing JSON (e.g. from /api/auth/session or array of tokens)
        $json = json_decode($trimmed, true);
        if (is_array($json)) {
            if (isset($json['accessToken'])) {
                // If this is session dump, we can use accessToken or check for session cookie
                $tokens[] = $json['accessToken'];
            }
            if (isset($json['tokens']) && is_array($json['tokens'])) {
                foreach ($json['tokens'] as $t) {
                    $tokens[] = is_string($t) ? $t : ($t['token'] ?? '');
                }
            }
        }

        // 2. Line by line tokens
        if (empty($tokens)) {
            $lines = preg_split("/\r\n|\n|\r/", $trimmed);
            foreach ($lines as $line) {
                $line = trim($line);
                if (!empty($line) && !str_starts_with($line, '#')) {
                    $tokens[] = $line;
                }
            }
        }

        return array_values(array_filter($tokens));
    }
}
