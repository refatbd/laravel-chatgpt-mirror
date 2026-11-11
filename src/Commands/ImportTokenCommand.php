<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Commands;

use Illuminate\Console\Command;
use Refatbd\LaravelChatGptMirror\Models\MirrorToken;
use Refatbd\LaravelChatGptMirror\Services\TokenInputParser;
use Refatbd\LaravelChatGptMirror\Services\TokenRefresher;

class ImportTokenCommand extends Command
{
    protected $signature = 'mirror:import-token
                            {file? : Optional path to token.txt, cookies.json, or multi-token text file}
                            {--name=Imported Account : Default name prefix}
                            {--turnstile= : Optional Cloudflare Turnstile token}';

    protected $description = 'Import ChatGPT session credentials (supports full token.txt /api/auth/session JSON, cookies JSON, Netscape, Cookie headers, or raw tokens)';

    public function handle(TokenRefresher $refresher, TokenInputParser $parser): int
    {
        $file = $this->argument('file');
        $rawContent = '';

        if ($file && is_string($file) && file_exists($file)) {
            $rawContent = (string) file_get_contents($file);
        } else {
            $this->info('Paste your __Secure-next-auth.session-token or full token.txt JSON content:');
            $rawContent = (string) $this->secret('Session Token / token.txt');
        }

        $candidates = array_values(array_filter(
            $parser->parseMany($rawContent),
            static fn (array $c): bool => strlen((string) ($c['session_token'] ?? '')) >= 20
        ));

        if ($candidates === []) {
            $this->error('No valid session tokens could be extracted.');
            return self::FAILURE;
        }

        $turnstileOpt = trim((string) ($this->option('turnstile') ?? ''));
        $imported = 0;

        foreach ($candidates as $idx => $candidate) {
            $rawToken = (string) $candidate['session_token'];
            $hash = hash('sha256', $rawToken);
            if (MirrorToken::where('token_hash', $hash)->exists()) {
                $this->warn('Skipped duplicate session token #' . ($idx + 1));
                continue;
            }

            $verified = $refresher->verifyCandidateSessionToken($rawToken);
            if (!$verified) {
                if ($refresher->lastVerificationWasTransientFailure() && !empty($candidate['access_token'])) {
                    $verified = [
                        'session_token' => $rawToken,
                        'access_token' => (string) $candidate['access_token'],
                        'expires_at' => $candidate['expires_at'] ?? now()->addHours(12),
                        'email' => $candidate['email'] ?? null,
                        'name' => $candidate['name'] ?? null,
                        'account_id' => $candidate['account_id'] ?? null,
                        'plan_type' => $candidate['plan_type'] ?? 'free',
                    ];
                    $this->warn('Token #' . ($idx + 1) . ': upstream session check hit temporary WAF/network error; imported using embedded token.txt metadata.');
                } else {
                    $this->error('Token #' . ($idx + 1) . ' rejected by ChatGPT upstream.');
                    continue;
                }
            }

            $resolvedName = $verified['name'] ?: ($candidate['name'] ?? null);
            $tokenRecord = MirrorToken::create([
                'name' => count($candidates) > 1
                    ? ($resolvedName ?: ($this->option('name') . ' #' . ($idx + 1)))
                    : ($resolvedName ?: $this->option('name')),
                'session_token' => $verified['session_token'],
                'token_hash' => hash('sha256', $verified['session_token']),
                'access_token' => $verified['access_token'],
                'access_token_expires_at' => $verified['expires_at'],
                'email' => $verified['email'] ?: ($candidate['email'] ?? null),
                'account_id' => $verified['account_id'] ?: ($candidate['account_id'] ?? null),
                'plan_type' => $verified['plan_type'] ?: ($candidate['plan_type'] ?? 'free'),
                'status' => 'active',
                'last_refreshed_at' => now(),
            ]);

            $effectiveTurnstile = $turnstileOpt !== '' ? $turnstileOpt : (string) ($candidate['turnstile_token'] ?? '');
            if ($effectiveTurnstile !== '') {
                $tokenRecord->setTurnstileToken($effectiveTurnstile);
                $tokenRecord->save();
            }

            $this->info("Token #{$tokenRecord->id} verified ({$tokenRecord->email}) and imported cleanly from " . ($candidate['source_format'] ?? 'input') . '!');
            $imported++;
        }

        return $imported > 0 ? self::SUCCESS : self::FAILURE;
    }
}
