<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Refatbd\LaravelChatGptMirror\Models\MirrorToken;
use Refatbd\LaravelChatGptMirror\Services\TokenInputParser;
use Refatbd\LaravelChatGptMirror\Services\TokenRefresher;
use Throwable;

class AddTokenCommand extends Command
{
    protected $signature = 'mirror:token:add
                            {token? : ChatGPT session token, full token.txt JSON/Cookie text, or path to token.txt}
                            {--file= : Path to a file (e.g. token.txt) containing /api/auth/session JSON or cookies}
                            {--name= : Optional friendly label for the account}';

    protected $description = 'Extract, validate, and add a ChatGPT session token (supports raw tokens, token.txt JSON, or Cookie headers) to the pool';

    public function handle(TokenRefresher $refresher, TokenInputParser $parser): int
    {
        $rawInput = '';
        $fileOption = trim((string) ($this->option('file') ?? ''));
        if ($fileOption !== '' && File::exists($fileOption)) {
            $rawInput = (string) File::get($fileOption);
        } else {
            $arg = $this->argument('token');
            if (is_string($arg) && $arg !== '') {
                $rawInput = (strlen($arg) < 512 && is_file($arg))
                    ? (string) File::get($arg)
                    : $arg;
            }
        }

        if (trim($rawInput) === '') {
            $rawInput = (string) $this->secret('Paste __Secure-next-auth.session-token or full token.txt JSON');
        }

        $parsed = $parser->parse($rawInput);
        $cleanToken = trim((string) ($parsed['session_token'] ?? ''));

        if ($cleanToken === '') {
            $this->error('No valid __Secure-next-auth.session-token could be extracted.');
            return self::FAILURE;
        }

        $customName = trim((string) ($this->option('name') ?? ''));
        $name = $customName !== ''
            ? $customName
            : (!empty($parsed['name']) ? (string) $parsed['name'] : ('Account #' . (MirrorToken::count() + 1)));

        $this->info('Extracted session token (' . ($parsed['source_format'] ?? 'raw') . '); validating with upstream...');

        $hash = hash('sha256', $cleanToken);
        $token = MirrorToken::where('token_hash', $hash)->first();
        $attrs = [
            'name' => $name,
            'email' => $parsed['email'] ?? $token?->email,
            'session_token' => $cleanToken,
            'token_hash' => $hash,
            'access_token' => $parsed['access_token'] ?? $token?->access_token,
            'access_token_expires_at' => $parsed['expires_at'] ?? $token?->access_token_expires_at,
            'account_id' => $parsed['account_id'] ?? $token?->account_id,
            'plan_type' => $parsed['plan_type'] ?? $token?->plan_type ?? 'free',
            'status' => 'active',
        ];

        if ($token) {
            $token->update($attrs);
        } else {
            $token = MirrorToken::create($attrs);
        }

        if (!empty($parsed['turnstile_token'])) {
            $token->setTurnstileToken((string) $parsed['turnstile_token']);
            $token->save();
        }

        try {
            $ok = $refresher->refresh($token);
            $token->refresh();

            $this->newLine();
            if (!$ok) {
                $this->warn('Token was stored, but upstream session refresh returned an error: ' . ($token->last_error ?: 'unknown error'));
            } else {
                $this->info('✅ Token extracted, validated, and saved cleanly!');
            }
            $this->table(
                ['ID', 'Name', 'Email', 'Plan', 'Expires At', 'Status'],
                [[
                    $token->id,
                    $token->name,
                    $token->email ?: 'N/A',
                    strtoupper((string) $token->plan_type),
                    $token->access_token_expires_at?->toDateTimeString() ?: 'N/A',
                    $token->status,
                ]]
            );

            return $ok ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $e) {
            $this->error('⚠️ Token was saved, but verification failed: ' . $e->getMessage());
            $this->line('Check your internet connection or verify the session token validity.');
            return self::FAILURE;
        }
    }
}
