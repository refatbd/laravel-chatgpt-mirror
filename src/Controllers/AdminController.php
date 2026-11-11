<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Refatbd\LaravelChatGptMirror\Models\MirrorConversation;
use Refatbd\LaravelChatGptMirror\Models\MirrorToken;
use Refatbd\LaravelChatGptMirror\Services\DynamicConfigService;
use Refatbd\LaravelChatGptMirror\Services\FailureTracker;
use Refatbd\LaravelChatGptMirror\Services\MirrorCache;
use Refatbd\LaravelChatGptMirror\Services\SecurityService;
use Refatbd\LaravelChatGptMirror\Services\TokenInputParser;
use Refatbd\LaravelChatGptMirror\Services\TokenPoolManager;
use Refatbd\LaravelChatGptMirror\Services\TokenRefresher;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Provides Admin UI dashboard, pool metrics, health/diagnostics/capabilities APIs,
 * dynamic server/Redis configuration, and programmatic /api/session token lifecycle management.
 */
class AdminController extends Controller
{
    private const FLASH_COOKIE = 'mirror_admin_flash';

    public function __construct(
        protected TokenPoolManager $pool,
        protected TokenRefresher $refresher,
        protected FailureTracker $failures,
        protected SecurityService $security,
        protected ?MirrorCache $cache = null,
        protected ?DynamicConfigService $dynamicConfig = null,
        protected ?TokenInputParser $tokenParser = null
    ) {
        $this->cache ??= app()->bound(MirrorCache::class) ? app(MirrorCache::class) : new MirrorCache();
        $this->dynamicConfig ??= app()->bound(DynamicConfigService::class) ? app(DynamicConfigService::class) : new DynamicConfigService();
        $this->tokenParser ??= app()->bound(TokenInputParser::class) ? app(TokenInputParser::class) : new TokenInputParser();
    }

    protected function cache(): MirrorCache
    {
        return $this->cache ??= app()->bound(MirrorCache::class) ? app(MirrorCache::class) : new MirrorCache();
    }

    protected function tokenParser(): TokenInputParser
    {
        return $this->tokenParser ??= app()->bound(TokenInputParser::class)
            ? app(TokenInputParser::class)
            : new TokenInputParser();
    }

    protected function dynamicConfig(): DynamicConfigService
    {
        return $this->dynamicConfig ??= app()->bound(DynamicConfigService::class)
            ? app(DynamicConfigService::class)
            : new DynamicConfigService();
    }

    /**
     * Resolve configured route paths dynamically with optional subdirectory base path.
     *
     * @return array<string, string>
     */
    protected function routePaths(string $basePath = ''): array
    {
        $sub = $basePath !== '' ? '/' . trim($basePath, '/') : '';
        $clean = static fn (string $val, string $fallback): string => '/' . (trim($val, '/') !== '' ? trim($val, '/') : $fallback);

        $docsRaw = trim((string) config('mirror.routes.docs_prefix', 'mirror'), '/');
        $docsBase = $docsRaw !== '' ? '/' . $docsRaw : '';

        return [
            'openai' => $sub . $clean((string) config('mirror.routes.openai_prefix', 'v1'), 'v1'),
            'backend' => $sub . $clean((string) config('mirror.routes.backend_prefix', '__codex-api'), '__codex-api'),
            'admin' => $sub . $clean((string) config('mirror.routes.admin_prefix', 'mirror/admin'), 'mirror/admin'),
            'playground' => $sub . $clean((string) config('mirror.routes.playground_prefix', 'mirror/playground'), 'mirror/playground'),
            'help' => $sub . $clean((string) config('mirror.routes.help_prefix', 'mirror/help'), 'mirror/help'),
            'assets' => $sub . $clean((string) config('mirror.routes.assets_prefix', 'mirror/assets'), 'mirror/assets'),
            'api_docs' => $sub . $docsBase . '/api-docs',
            'openapi' => $sub . $docsBase . '/openapi',
            'runtime_settings' => $sub . '/api/settings/runtime',
        ];
    }

    public function index(Request $request): Response
    {
        $stats = $this->pool->getPoolStats();
        $tokens = MirrorToken::orderBy('id', 'asc')->get();
        $conversations = MirrorConversation::orderByDesc('last_active_at')->take(25)->get();
        $recentFailures = $this->failures->recent();
        $flash = $this->pullFlash($request);

        $html = $this->renderView($request, $stats, $tokens, $conversations, $recentFailures, $flash);
        $response = response($html, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);

        if ($flash !== null) {
            $response->headers->clearCookie(self::FLASH_COOKIE, '/');
        }

        return $this->security->attachControlCookie($response, $request);
    }

    public function addToken(Request $request): Response
    {
        $rawInput = trim((string) $request->input('session_token', ''));
        if ($request->hasFile('token_file')) {
            $uploaded = $request->file('token_file');
            if ($uploaded && $uploaded->isValid() && $uploaded->getSize() <= 2 * 1024 * 1024) {
                $fileContent = (string) @file_get_contents((string) $uploaded->getRealPath());
                if (trim($fileContent) !== '') {
                    $rawInput = $rawInput !== '' ? ($rawInput . "\n" . $fileContent) : $fileContent;
                }
            }
        }

        $turnstileToken = trim((string) $request->input('turnstile_token', ''));
        $customName = trim((string) $request->input('name', ''));

        $candidates = $this->tokenParser()->parseMany($rawInput);
        $validCandidates = array_values(array_filter(
            $candidates,
            static fn (array $c): bool => strlen((string) ($c['session_token'] ?? '')) >= 20
        ));

        if ($validCandidates === []) {
            $hasOnlyAccess = !empty($candidates[0]['access_token']);
            $errMsg = $hasOnlyAccess
                ? 'Extracted accessToken only without __Secure-next-auth.session-token (sessionToken). Paste full token.txt (/api/auth/session JSON) or Cookie containing sessionToken.'
                : 'Session token must be at least 20 characters (supports full token.txt JSON, Cookie headers, cURL, or raw token).';
            return $this->redirectWithFlash('error', $errMsg, $request);
        }

        if (count($validCandidates) === 1) {
            $res = $this->verifyAndPersistCandidate($validCandidates[0], $customName, $turnstileToken, $request);
            if (!$res['ok']) {
                return $this->redirectWithFlash('error', $res['error'], $request);
            }

            /** @var MirrorToken $token */
            $token = $res['token'];
            $label = $token->email ?: $token->name;
            $note = $res['mode'] === 'session_bundle_fallback'
                ? "Extracted & saved account: {$label} (using embedded token.txt accessToken because upstream session check hit temporary WAF/network error)."
                : "Verified and saved account: {$label}";

            return $this->redirectWithFlash('success', $note, $request);
        }

        $savedCount = 0;
        $rejectedCount = 0;
        $labels = [];
        foreach ($validCandidates as $idx => $candidate) {
            $labelForRow = $customName !== '' ? ($customName . ' #' . ($idx + 1)) : '';
            $res = $this->verifyAndPersistCandidate($candidate, $labelForRow, $turnstileToken, $request);
            if ($res['ok']) {
                $savedCount++;
                /** @var MirrorToken $savedToken */
                $savedToken = $res['token'];
                $labels[] = $savedToken->email ?: $savedToken->name;
            } else {
                $rejectedCount++;
            }
        }

        if ($savedCount === 0) {
            return $this->redirectWithFlash('error', 'ChatGPT upstream rejected all extracted session tokens.', $request);
        }

        $summary = "Extracted and saved {$savedCount} account(s): " . implode(', ', array_slice($labels, 0, 5));
        if ($rejectedCount > 0) {
            $summary .= " ({$rejectedCount} rejected by upstream)";
        }

        return $this->redirectWithFlash('success', $summary, $request);
    }

    public function refreshToken(Request $request, int|string $id): Response
    {
        $token = MirrorToken::findOrFail($id);
        $ok = $this->resolveRefresher()->refresh($token);
        if ($ok) {
            $this->pool->invalidateDiscoveryCaches($token->getEffectiveAccountId());
        }

        return $ok
            ? $this->redirectWithFlash('success', "Token #{$token->id} ({$token->name}) refreshed successfully.", $request)
            : $this->redirectWithFlash('error', "Failed to refresh token #{$token->id}: " . ($token->last_error ?: 'upstream rejected session.'), $request);
    }

    public function deleteToken(Request $request, int|string $id): Response
    {
        $token = MirrorToken::findOrFail($id);
        $label = $token->name;
        $accountId = $token->getEffectiveAccountId();
        $token->delete();
        $this->security->rotateAssetLinkGeneration();
        $this->pool->invalidateDiscoveryCaches($accountId);

        return $this->redirectWithFlash('success', "Removed account '{$label}' and rotated asset tickets.", $request);
    }

    public function resetCounters(Request $request): Response
    {
        MirrorToken::query()->update([
            'active_requests' => 0,
            'consecutive_errors' => 0,
            'cooldown_until' => null,
            'status' => 'active',
            'last_error' => null,
        ]);

        $this->pool->invalidateDiscoveryCaches();

        return $this->redirectWithFlash('success', 'All token counters and cooldowns were reset.', $request);
    }

    public function stats(): Response
    {
        $stats = $this->pool->getPoolStats();

        return response()->json([
            'total_tokens' => $stats['total_tokens'],
            'active_healthy' => $stats['active_tokens'],
            'in_cooldown' => $stats['cooldown_tokens'],
            'total_requests_served' => $stats['total_lifetime_requests'],
            'total_in_flight_requests' => $stats['total_active_requests'],
            'strategy' => (string) config('mirror.pool.strategy', 'least_busy'),
            'pool' => $stats,
            'active_conversations' => MirrorConversation::count(),
        ]);
    }

    public function health(?Request $request = null): Response
    {
        $this->dynamicConfig()->ensureApplied();

        $dbOk = true;
        try {
            DB::select('SELECT 1');
        } catch (\Throwable) {
            $dbOk = false;
        }

        $stats = $this->pool->getPoolStats();
        $cacheDiag = $this->cache()->diagnostics();
        $runtimeDiag = $this->runtimeDiagnostics($request);
        $healthy = $dbOk && ($stats['active_tokens'] > 0);

        return response()->json([
            'status' => $healthy ? 'ok' : 'degraded',
            'ok' => $healthy,
            'configured' => $stats['total_tokens'] > 0,
            'version' => '2.1.0',
            'database' => $dbOk ? 'ok' : 'error',
            'active_tokens' => $stats['active_tokens'],
            'total_tokens' => $stats['total_tokens'],
            'cooldown_tokens' => $stats['cooldown_tokens'],
            'upstream' => config('mirror.upstream', 'https://chatgpt.com'),
            'egressMode' => 'direct',
            'sha3_512_supported' => in_array('sha3-512', hash_algos(), true),
            'gd_supported' => extension_loaded('gd'),
            'cache' => $cacheDiag,
            'runtime' => $runtimeDiag,
            'runtime_settings' => $this->dynamicConfig()->publicState(),
        ], $healthy ? 200 : 503);
    }

    public function diagnostics(?Request $request = null): Response
    {
        $this->dynamicConfig()->ensureApplied();

        $dbOk = true;
        try {
            DB::select('SELECT 1');
        } catch (\Throwable) {
            $dbOk = false;
        }

        $req = $request ?? request();
        $paths = $this->routePaths($this->security->resolveBasePath($req));
        $cacheDiag = $this->cache()->diagnostics();
        $runtimeDiag = $this->runtimeDiagnostics($request);

        return response()->json([
            'schemaVersion' => '2026-11',
            'ok' => $dbOk,
            'version' => '2.1.0',
            'build' => [
                'package' => 'refatbd/laravel-chatgpt-mirror',
                'version' => '2.1.0',
                'framework' => 'laravel',
                'php' => PHP_VERSION,
                'sapi' => PHP_SAPI,
            ],
            'storage' => [
                'engine' => (string) config('database.default'),
                'healthy' => $dbOk,
            ],
            'cache' => $cacheDiag,
            'runtime' => $runtimeDiag,
            'runtime_settings' => $this->dynamicConfig()->publicState(),
            'config' => [
                'upstream' => (string) config('mirror.upstream', 'https://chatgpt.com'),
                'egressMode' => 'direct',
                'openaiPrefix' => $paths['openai'],
                'docsPrefix' => $paths['api_docs'],
                'adminPrefix' => $paths['admin'],
                'playgroundPrefix' => $paths['playground'],
            ],
            'uptimeSeconds' => (int) (defined('LARAVEL_START') ? max(0, microtime(true) - LARAVEL_START) : 0),
            'database' => $dbOk ? 'ok' : 'error',
            'egressMode' => 'direct',
            'pool' => $this->pool->getPoolStats(),
            'active_conversations' => MirrorConversation::count(),
            'recentFailures' => $this->failures->recent(),
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    public function capabilities(Request $request): Response
    {
        $this->dynamicConfig()->ensureApplied();

        $paths = $this->routePaths($this->security->resolveBasePath($request));

        $endpoints = [
            'chat_completions' => "{$paths['openai']}/chat/completions",
            'responses' => "{$paths['openai']}/responses",
            'models' => "{$paths['openai']}/models",
            'capabilities' => "{$paths['openai']}/capabilities",
            'playground' => $paths['playground'],
            'admin' => $paths['admin'],
            'help' => $paths['help'],
            'api_docs' => $paths['api_docs'],
            'openapi' => $paths['openapi'],
            'runtime_settings' => $paths['runtime_settings'],
            'mirror_chat' => ($this->security->resolveBasePath($request) !== '' ? $this->security->resolveBasePath($request) : '') . '/api/chat',
            'mirror_conversations' => ($this->security->resolveBasePath($request) !== '' ? $this->security->resolveBasePath($request) : '') . '/api/conversations',
            'mirror_files' => ($this->security->resolveBasePath($request) !== '' ? $this->security->resolveBasePath($request) : '') . '/api/files',
            'mirror_assets' => ($this->security->resolveBasePath($request) !== '' ? $this->security->resolveBasePath($request) : '') . '/api/assets',
            'mirror_convert' => ($this->security->resolveBasePath($request) !== '' ? $this->security->resolveBasePath($request) : '') . '/api/convert/formats',
            'mirror_decoder_challenges' => ($this->security->resolveBasePath($request) !== '' ? $this->security->resolveBasePath($request) : '') . '/api/decoder-challenges',
        ];

        $cacheDiag = $this->cache()->diagnostics();
        $runtimeDiag = $this->runtimeDiagnostics($request);

        $features = [
            'streaming' => true,
            'sse_v1_patch' => true,
            'sentinel_pow' => in_array('sha3-512', hash_algos(), true),
            'conduit' => true,
            'responses_api' => true,
            'tools' => true,
            'attachments' => true,
            'rich_output' => true,
            'conversations' => true,
            'conversions' => true,
            'decoder_challenges' => true,
            'multi_account_pool' => true,
            'multi_worker_safe' => true,
            'dynamic_runtime_config' => true,
            'redis_cache' => (bool) ($cacheDiag['redis_active'] ?? false),
            'redis_mode' => (string) ($cacheDiag['redis_mode'] ?? 'auto'),
            'distributed_locks' => (bool) ($cacheDiag['locks_supported'] ?? false),
            'reverse_proxy_headers' => true,
            'browser_proxy' => true,
            'cors' => filter_var(config('mirror.cors.enabled', true), FILTER_VALIDATE_BOOLEAN),
            'rate_limiting' => filter_var(config('mirror.rate_limit.enabled', false), FILTER_VALIDATE_BOOLEAN),
            'websocket_proxy' => false,
            'warp_egress_toggle' => false,
        ];

        return response()->json([
            'schemaVersion' => '2026-11',
            'server' => 'laravel-chatgpt-mirror',
            'version' => '2.1.0',
            'routes' => $endpoints,
            'fields' => [
                'chat_completions' => ['model', 'messages', 'stream', 'tools', 'tool_choice', 'metadata'],
                'responses' => ['model', 'input', 'instructions', 'stream', 'tools', 'tool_choice', 'metadata'],
                'mirror_chat' => ['conversationId', 'message', 'model', 'gizmoId', 'private', 'temporary', 'attachments', 'instructions'],
            ],
            'supported' => $features,
            'endpoints' => $endpoints,
            'features' => $features,
            'cache' => $cacheDiag,
            'runtime' => $runtimeDiag,
            'runtime_settings' => $this->dynamicConfig()->publicState(),
            'notes' => [
                'websocket_proxy' => 'Standard PHP-FPM/Apache/Nginx servers terminate HTTP SSE natively; raw WebSocket upgrades fall back automatically to HTTP SSE + Conduit.',
            ],
        ]);
    }

    public function getRuntimeSettings(Request $request): Response
    {
        $this->dynamicConfig()->ensureApplied();

        return response()->json([
            'ok' => true,
            'runtime_settings' => $this->dynamicConfig()->publicState(),
            'cache' => $this->cache()->diagnostics(),
            'runtime' => $this->runtimeDiagnostics($request),
        ]);
    }

    public function putRuntimeSettings(Request $request): Response
    {
        $input = $this->sanitizeRuntimeInput($request);
        $this->dynamicConfig()->saveOverrides($input);

        return response()->json([
            'ok' => true,
            'message' => 'Runtime settings saved and applied live.',
            'runtime_settings' => $this->dynamicConfig()->publicState(),
            'cache' => $this->cache()->diagnostics(),
            'runtime' => $this->runtimeDiagnostics($request),
        ]);
    }

    public function resetRuntimeSettings(Request $request): Response
    {
        $this->dynamicConfig()->resetOverrides();

        return response()->json([
            'ok' => true,
            'message' => 'Dynamic overrides cleared; reverted to .env / config defaults.',
            'runtime_settings' => $this->dynamicConfig()->publicState(),
            'cache' => $this->cache()->diagnostics(),
            'runtime' => $this->runtimeDiagnostics($request),
        ]);
    }

    public function saveRuntime(Request $request): Response
    {
        $input = $this->sanitizeRuntimeInput($request, htmlForm: true);
        $this->dynamicConfig()->saveOverrides($input);

        $state = $this->dynamicConfig()->publicState();
        $cacheDiag = $this->cache()->diagnostics();

        if (($state['redis']['enabled'] ?? 'auto') === 'enabled' && !($cacheDiag['redis_active'] ?? false)) {
            $reason = $cacheDiag['redis_error'] ?? 'Redis client/server unavailable';
            return $this->redirectWithFlash(
                'success',
                "Runtime settings saved live. Redis is enabled but currently using fallback store ({$cacheDiag['driver']}): {$reason}",
                $request
            );
        }

        return $this->redirectWithFlash('success', 'Dynamic web server, proxy & Redis settings saved and applied live.', $request);
    }

    public function resetRuntime(Request $request): Response
    {
        $this->dynamicConfig()->resetOverrides();

        return $this->redirectWithFlash('success', 'All dynamic runtime overrides were cleared back to .env / config defaults.', $request);
    }

    public function testRedis(Request $request): Response
    {
        $this->dynamicConfig()->ensureApplied();

        $params = $this->sanitizeRuntimeInput($request);
        if (empty($params['redis_password']) && empty($params['clear_redis_password'])) {
            $existingPassword = config('mirror.redis.password');
            if (is_string($existingPassword) && $existingPassword !== '') {
                $params['redis_password'] = $existingPassword;
            }
        }

        $result = $this->dynamicConfig()->testRedisConnection($params);
        return response()->json($result, 200);
    }

    /**
     * @return array<string, mixed>
     */
    protected function sanitizeRuntimeInput(Request $request, bool $htmlForm = false): array
    {
        $input = $request->all();

        foreach (['redis_url', 'redis.url'] as $urlKey) {
            if (isset($input[$urlKey]) && str_contains((string) $input[$urlKey], '://***@')) {
                unset($input[$urlKey]);
            }
        }

        if ($htmlForm || $request->boolean('_runtime_form')) {
            $input['redis_persistent'] = $request->boolean('redis_persistent');
            $input['cache_assets_in_redis'] = $request->boolean('cache_assets_in_redis');
            $input['server_trust_proxies'] = $request->boolean('server_trust_proxies');
            $input['server_disable_output_compression'] = $request->boolean('server_disable_output_compression');
            $input['clear_redis_password'] = $request->boolean('clear_redis_password');
            if ($request->boolean('_has_gateway_controls')) {
                $input['cors_enabled'] = $request->boolean('cors_enabled');
                $input['rate_limit_enabled'] = $request->boolean('rate_limit_enabled');
            }
        }

        return $input;
    }

    public function getSession(): Response
    {
        $stats = $this->pool->getPoolStats();
        $latest = MirrorToken::orderByDesc('last_refreshed_at')->orderByDesc('id')->first();

        return response()->json([
            'configured' => $stats['total_tokens'] > 0,
            'savedAt' => $latest?->last_refreshed_at?->toIso8601String() ?? $latest?->created_at?->toIso8601String(),
            'hasTurnstileToken' => $latest ? ($latest->getTurnstileToken() !== null) : false,
            'active_tokens' => $stats['active_tokens'],
            'healthy_tokens' => $stats['active_tokens'],
            'total_tokens' => $stats['total_tokens'],
            'accounts' => collect($stats['tokens'])->map(fn (array $t) => [
                'id' => $t['id'],
                'name' => $t['name'],
                'email' => $t['email'],
                'plan' => $t['plan_type'],
                'status' => $t['status'],
                'expires_at' => $t['expires_at'],
                'has_turnstile_token' => $t['has_turnstile_token'] ?? false,
            ])->all(),
        ]);
    }

    public function postSession(Request $request): Response
    {
        $rawSessionInput = $request->input('sessionToken') ?? $request->input('session_token');
        $turnstileToken = trim((string) ($request->input('turnstileToken') ?? $request->input('turnstile_token') ?? ''));
        $name = trim((string) ($request->input('name') ?? ''));

        $candidate = null;
        if (is_string($rawSessionInput) && trim($rawSessionInput) !== '') {
            $candidate = $this->tokenParser()->parse($rawSessionInput);
            // If user sent `{"sessionToken": "...", "accessToken": "...", "user": {...}}` directly, merge outer metadata
            if ($candidate !== null && ($request->has('accessToken') || $request->has('user') || $request->has('account'))) {
                $outer = $this->tokenParser()->parseSessionArray($request->all());
                if ($outer !== null) {
                    foreach (['access_token', 'expires_at', 'email', 'name', 'account_id', 'plan_type', 'turnstile_token'] as $field) {
                        if (empty($candidate[$field]) && !empty($outer[$field])) {
                            $candidate[$field] = $outer[$field];
                        }
                    }
                    if (($candidate['source_format'] ?? 'raw_session_token') === 'raw_session_token' && !empty($outer['source_format'])) {
                        $candidate['source_format'] = $outer['source_format'];
                    }
                }
            }
        } elseif ($request->has('accessToken') || $request->has('cookies') || $request->has('__Secure-next-auth.session-token')) {
            $candidate = $this->tokenParser()->parseSessionArray($request->all());
        } elseif (trim((string) $request->getContent()) !== '') {
            $candidate = $this->tokenParser()->parse((string) $request->getContent());
        }

        if ($candidate === null || strlen((string) ($candidate['session_token'] ?? '')) < 20) {
            return response()->json(['error' => 'That does not look like a valid sessionToken (minimum 20 characters).'], 400);
        }

        $res = $this->verifyAndPersistCandidate($candidate, $name, $turnstileToken, $request, rotateAssets: true);
        if (!$res['ok']) {
            return response()->json(['error' => 'Failed to verify session token with ChatGPT upstream'], 401);
        }

        /** @var MirrorToken $token */
        $token = $res['token'];

        return response()->json([
            'ok' => true,
            'id' => $token->id,
            'accountId' => $token->account_id,
            'email' => $token->email,
            'plan_type' => $token->plan_type,
            'source_format' => $candidate['source_format'] ?? 'raw_session_token',
            'verification_mode' => $res['mode'],
            'account' => [
                'id' => $token->id,
                'name' => $token->name,
                'email' => $token->email,
                'accountId' => $token->account_id,
                'plan' => $token->plan_type,
                'status' => $token->status,
            ],
        ]);
    }

    protected function resolveRefresher(): TokenRefresher
    {
        if (function_exists('app') && app()->bound(TokenRefresher::class)) {
            $bound = app(TokenRefresher::class);
            if ($bound instanceof TokenRefresher) {
                return $bound;
            }
        }

        return $this->refresher;
    }

    /**
     * Verify a parsed session candidate against ChatGPT upstream (falling back cleanly to
     * embedded unexpired JWT from full `token.txt` JSON only on non-401 WAF/transport errors).
     *
     * @param array<string, mixed> $candidate
     * @return array{ok: true, token: MirrorToken, mode: string}|array{ok: false, error: string}
     */
    protected function verifyAndPersistCandidate(
        array $candidate,
        string $customName,
        string $explicitTurnstile,
        Request $request,
        bool $rotateAssets = false
    ): array {
        $refresher = $this->resolveRefresher();
        $sessionToken = trim((string) ($candidate['session_token'] ?? ''));
        $verified = $refresher->verifyCandidateSessionToken($sessionToken);
        $mode = 'upstream';

        if ($verified) {
            foreach (['email', 'name', 'account_id', 'plan_type'] as $metaField) {
                if (empty($verified[$metaField]) && !empty($candidate[$metaField])) {
                    $verified[$metaField] = $candidate[$metaField];
                }
            }
        } elseif ($refresher->lastVerificationWasTransientFailure() && !empty($candidate['access_token'])) {
            $jwt = (string) $candidate['access_token'];
            $jwtExp = MirrorToken::decodeJwtExpiry($jwt);
            $expCarbon = $candidate['expires_at'] instanceof Carbon
                ? $candidate['expires_at']
                : ($jwtExp !== null ? Carbon::createFromTimestamp($jwtExp) : null);

            if (($jwtExp !== null && $jwtExp > time() + 60) || ($expCarbon !== null && $expCarbon->isFuture())) {
                $verified = [
                    'session_token' => $sessionToken,
                    'access_token' => $jwt,
                    'expires_at' => $expCarbon ?? now()->addHours(12),
                    'email' => $candidate['email'] ?? null,
                    'name' => $candidate['name'] ?? null,
                    'account_id' => $candidate['account_id'] ?? null,
                    'plan_type' => $candidate['plan_type'] ?? 'free',
                ];
                $mode = 'session_bundle_fallback';
            }
        }

        if (!$verified) {
            $this->failures->record('invalid_session_token', $request->header('x-request-id'), 'session_verification_failed');
            return ['ok' => false, 'error' => 'ChatGPT upstream rejected this __Secure-next-auth.session-token.'];
        }

        $hash = hash('sha256', $verified['session_token']);
        $token = MirrorToken::where('token_hash', $hash)->first();

        $attributes = [
            'name' => $customName !== '' ? $customName : ($verified['name'] ?: 'Account ' . Str::random(4)),
            'session_token' => $verified['session_token'],
            'token_hash' => $hash,
            'access_token' => $verified['access_token'],
            'access_token_expires_at' => $verified['expires_at'],
            'email' => $verified['email'],
            'account_id' => $verified['account_id'],
            'plan_type' => $verified['plan_type'] ?? 'free',
            'status' => 'active',
            'consecutive_errors' => 0,
            'cooldown_until' => null,
            'last_refreshed_at' => now(),
            'last_error' => null,
        ];

        if ($token) {
            $token->update($attributes);
        } else {
            $token = MirrorToken::create($attributes);
        }

        $effectiveTurnstile = $explicitTurnstile !== ''
            ? $explicitTurnstile
            : (string) ($candidate['turnstile_token'] ?? ($verified['turnstile_token'] ?? ''));
        if ($effectiveTurnstile !== '') {
            $token->setTurnstileToken($effectiveTurnstile);
            $token->save();
        }

        if ($rotateAssets) {
            $this->security->rotateAssetLinkGeneration();
        }
        $this->pool->invalidateDiscoveryCaches($token->getEffectiveAccountId());

        return ['ok' => true, 'token' => $token, 'mode' => $mode];
    }

    public function deleteSession(): Response
    {
        $this->pool->invalidateDiscoveryCaches();
        MirrorToken::query()->delete();
        $this->security->rotateAssetLinkGeneration();

        return response()->json(['ok' => true, 'message' => 'All mirror sessions cleared']);
    }

    /**
     * @return array<string, mixed>
     */
    protected function runtimeDiagnostics(?Request $request = null): array
    {
        $this->dynamicConfig()->ensureApplied();

        $req = $request ?? request();
        $serverSoftware = (string) ($req->server('SERVER_SOFTWARE') ?: ($_SERVER['SERVER_SOFTWARE'] ?? PHP_SAPI));
        $configuredPlatform = (string) config('mirror.server.platform', 'auto');
        $detectedPlatform = $this->detectServerPlatform($serverSoftware, $req);
        $forceHttps = config('mirror.server.force_https');

        return [
            'php_version' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'server_software' => $serverSoftware,
            'configured_platform' => $configuredPlatform,
            'detected_platform' => $detectedPlatform,
            'zlib_output_compression' => filter_var((string) ini_get('zlib.output_compression'), FILTER_VALIDATE_BOOLEAN),
            'output_buffering' => (string) ini_get('output_buffering'),
            'max_execution_time' => (int) ini_get('max_execution_time'),
            'stream_max_execution_time' => (int) config('mirror.server.stream_max_execution_time', 0),
            'trust_proxies' => (bool) config('mirror.server.trust_proxies', true),
            'force_https' => $forceHttps === null ? 'auto' : (bool) $forceHttps,
            'https_detected' => $this->security->isSecureRequest($req),
            'resolved_origin' => $this->security->resolveOrigin($req),
            'resolved_base_path' => $this->security->resolveBasePath($req),
            'resolved_base_url' => $this->security->resolveBaseUrl($req),
            'authorization_header_present' => $req->headers->has('Authorization')
                || (string) $req->server('HTTP_AUTHORIZATION', '') !== ''
                || (string) $req->server('REDIRECT_HTTP_AUTHORIZATION', '') !== '',
        ];
    }

    protected function detectServerPlatform(string $serverSoftware, Request $request): string
    {
        if (isset($_SERVER['LARAVEL_OCTANE']) || class_exists(\Laravel\Octane\Octane::class, false)) {
            return 'octane';
        }

        $lower = strtolower($serverSoftware);
        if (str_contains($lower, 'frankenphp') || $request->server('FRANKENPHP')) {
            return 'frankenphp';
        }
        if (str_contains($lower, 'litespeed')) {
            return 'litespeed';
        }
        if (str_contains($lower, 'apache')) {
            return 'apache';
        }
        if (str_contains($lower, 'nginx')) {
            return 'nginx';
        }
        if (str_contains($lower, 'caddy')) {
            return 'caddy';
        }
        if (PHP_SAPI === 'cli-server') {
            return 'php-cli-server';
        }

        return PHP_SAPI;
    }

    protected function redirectWithFlash(string $type, string $message, ?Request $request = null): Response
    {
        $req = $request ?? request();
        $payload = json_encode(['type' => $type, 'message' => $message]);
        $sig = hash_hmac('sha256', (string) $payload, $this->security->hmacSecret());
        $encoded = base64_encode(json_encode(['p' => $payload, 's' => $sig]));

        $adminPath = $this->routePaths()['admin'];
        $targetUrl = $req instanceof Request ? ($this->security->resolveBaseUrl($req) . $adminPath) : $adminPath;
        $response = redirect($targetUrl);
        $response->headers->setCookie(Cookie::create(
            self::FLASH_COOKIE,
            $encoded,
            now()->addMinutes(5)->getTimestamp(),
            '/',
            null,
            $req instanceof Request ? $this->security->isSecureRequest($req) : false,
            true,
            false,
            Cookie::SAMESITE_LAX
        ));

        return $response;
    }

    /**
     * @return array{type: string, message: string}|null
     */
    protected function pullFlash(Request $request): ?array
    {
        $raw = $request->cookie(self::FLASH_COOKIE);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode((string) base64_decode($raw, true), true);
        if (!is_array($decoded) || empty($decoded['p']) || empty($decoded['s'])) {
            return null;
        }

        $expected = hash_hmac('sha256', (string) $decoded['p'], $this->security->hmacSecret());
        if (!hash_equals($expected, (string) $decoded['s'])) {
            return null;
        }

        $flash = json_decode((string) $decoded['p'], true);
        return is_array($flash) && isset($flash['type'], $flash['message']) ? $flash : null;
    }

    /**
     * @param array<string, mixed> $stats
     * @param iterable<MirrorToken> $tokens
     * @param iterable<MirrorConversation> $conversations
     * @param array<int, array<string, mixed>> $recentFailures
     * @param array{type: string, message: string}|null $flash
     */
    protected function renderView(
        Request $request,
        array $stats,
        iterable $tokens,
        iterable $conversations,
        array $recentFailures,
        ?array $flash
    ): string {
        $this->dynamicConfig()->ensureApplied();

        $originUrl = $this->security->resolveOrigin($request);
        $basePath = $this->security->resolveBasePath($request);
        $baseUrl = $this->security->resolveBaseUrl($request);
        $paths = $this->routePaths($basePath);
        $adminBase = $originUrl . $paths['admin'];
        $safeAdminBase = e($adminBase);
        $cacheDiag = $this->cache()->diagnostics();
        $runtimeDiag = $this->runtimeDiagnostics($request);
        $dynState = $this->dynamicConfig()->publicState();
        $apiKey = (string) config('mirror.api_key', '');
        $maskedKey = $apiKey !== ''
            ? (substr($apiKey, 0, 6) . str_repeat('*', max(4, strlen($apiKey) - 6)))
            : 'Open (Local/Unauthenticated)';

        $flashHtml = '';
        if ($flash !== null) {
            $bg = $flash['type'] === 'success' ? '#ecfdf5' : '#fef2f2';
            $border = $flash['type'] === 'success' ? '#10b981' : '#ef4444';
            $color = $flash['type'] === 'success' ? '#065f46' : '#991b1b';
            $msg = e($flash['message']);
            $flashHtml = "<div style='margin-bottom:16px;padding:12px 16px;border-radius:10px;background:{$bg};border:1px solid {$border};color:{$color};font-size:13px;font-weight:500'>{$msg}</div>";
        }

        $tokenRows = '';
        foreach ($tokens as $t) {
            $badgeColor = match ($t->status) {
                'active' => '#10b981',
                'cooldown' => '#f59e0b',
                default => '#ef4444',
            };
            $tName = e($t->name);
            $tEmail = e($t->email ?: 'Pending Sync');
            $tPlan = e(strtoupper((string) ($t->plan_type ?: 'free')));
            $tStatus = e(strtoupper((string) $t->status));
            $tExpires = e($t->access_token_expires_at ? $t->access_token_expires_at->diffForHumans() : 'Pending Refresh');
            $turnstileBadge = $t->getTurnstileToken() ? '<span style="margin-left:6px;padding:1px 6px;border-radius:99px;background:#e0e7ff;color:#3730a3;font-size:10px">TS</span>' : '';
            $errLine = $t->last_error ? '<div style="font-size:11px;color:#b91c1c;margin-top:2px">' . e(Str::limit($t->last_error, 80)) . '</div>' : '';

            $tokenRows .= "<tr>
                <td>#{$t->id} <strong>{$tName}</strong>{$turnstileBadge}{$errLine}</td>
                <td>{$tEmail}</td>
                <td><span style='padding:2px 8px;border-radius:99px;background:#f3f4f6;font-size:11px;font-weight:600'>{$tPlan}</span></td>
                <td><span style='color:{$badgeColor};font-weight:600'>{$tStatus}</span></td>
                <td>{$t->active_requests} / {$t->total_requests}</td>
                <td>{$tExpires}</td>
                <td style='display:flex;gap:6px'>
                    <form method='POST' action='{$safeAdminBase}/tokens/{$t->id}/refresh'><button type='submit' class='btn btn-sm'>Refresh</button></form>
                    <form method='POST' action='{$safeAdminBase}/tokens/{$t->id}/delete' onsubmit='return confirm(\"Delete account?\")'><button type='submit' class='btn btn-sm btn-danger'>Delete</button></form>
                </td>
            </tr>";
        }
        if ($tokenRows === '') {
            $tokenRows = '<tr><td colspan="7" style="text-align:center;color:#6b7280;padding:24px">No ChatGPT accounts added yet.</td></tr>';
        }

        $convRows = '';
        foreach ($conversations as $c) {
            $cId = e(Str::limit((string) ($c->public_id ?: $c->conversation_id), 22));
            $cModel = e((string) $c->model);
            $cTitle = e($c->title ?: 'Untitled Chat');
            $cActive = e($c->last_active_at ? $c->last_active_at->diffForHumans() : '-');
            $convRows .= "<tr><td><code>{$cId}</code></td><td>{$cModel}</td><td>{$cTitle}</td><td>{$cActive}</td></tr>";
        }
        if ($convRows === '') {
            $convRows = '<tr><td colspan="4" style="text-align:center;color:#6b7280;padding:18px">No conversations cached yet.</td></tr>';
        }

        $failureRows = '';
        foreach ($recentFailures as $f) {
            $failureRows .= '<tr><td>' . e((string) ($f['time'] ?? '')) . '</td><td><code>' . e((string) ($f['code'] ?? '')) . '</code></td><td>' . e((string) ($f['protocolCategory'] ?? '-')) . '</td><td><code>' . e((string) ($f['requestId'] ?? '')) . '</code></td></tr>';
        }
        if ($failureRows === '') {
            $failureRows = '<tr><td colspan="4" style="text-align:center;color:#6b7280;padding:14px">No recent protocol/upstream failures recorded.</td></tr>';
        }

        $safeKey = e($maskedKey);
        $safeBase = e($baseUrl);
        $safePrefix = e($paths['openai']);
        $safePlaygroundUrl = e($originUrl . $paths['playground']);
        $safeDocsUrl = e($originUrl . $paths['api_docs']);
        $safeHelpUrl = e($originUrl . $paths['help']);
        $safeHomeUrl = e($baseUrl . '/');
        $safeDiagnosticsUrl = e($baseUrl . '/api/diagnostics');

        $redisMode = (string) ($cacheDiag['redis_mode'] ?? 'auto');
        if ($redisMode === 'disabled') {
            $redisBadge = '<span style="padding:2px 8px;border-radius:99px;background:#f3f4f6;color:#374151;font-size:11px;font-weight:600">Redis Disabled (Using ' . e((string) ($cacheDiag['driver'] ?? 'database')) . ' store)</span>';
        } elseif ($cacheDiag['redis_active'] ?? false) {
            $redisBadge = '<span style="padding:2px 8px;border-radius:99px;background:#ecfdf5;color:#047857;font-size:11px;font-weight:600">Redis Active (' . e((string) ($cacheDiag['redis_ping'] ?? 'PONG')) . ($cacheDiag['latency_ms'] !== null ? ', ' . e((string) $cacheDiag['latency_ms']) . 'ms' : '') . ')</span>';
        } else {
            $redisBadge = '<span style="padding:2px 8px;border-radius:99px;background:#eff6ff;color:#1d4ed8;font-size:11px;font-weight:600">Fallback Store (' . e((string) ($cacheDiag['driver'] ?? 'array')) . ')</span>';
        }

        $safeServerPlatform = e((string) $runtimeDiag['detected_platform'] . ' (config: ' . (string) $runtimeDiag['configured_platform'] . ')');
        $safePhpSapi = e(PHP_VERSION . ' / ' . PHP_SAPI);
        $safeHttpsState = ($runtimeDiag['https_detected'] ?? false) ? 'HTTPS (Origin: ' . $safeBase . ')' : 'HTTP (Origin: ' . $safeBase . ')';
        $safeZlibState = ($runtimeDiag['zlib_output_compression'] ?? false) ? 'Enabled (Auto-disabled during SSE)' : 'Off (Unbuffered SSE Ready)';
        $safeLocksState = ($cacheDiag['locks_supported'] ?? false) ? 'Distributed Atomic Locks Ready' : 'Single-node Lock Fallback';
        $safeRedisExt = (($cacheDiag['phpredis_loaded'] ?? false) ? 'phpredis loaded' : 'no phpredis') . ' · ' . (($cacheDiag['predis_installed'] ?? false) ? 'predis installed' : 'no predis');

        $opt = static function (string $current, array $choices): string {
            $out = '';
            foreach ($choices as $val => $label) {
                $sel = $current === (string) $val ? ' selected' : '';
                $out .= '<option value="' . e((string) $val) . '"' . $sel . '>' . e($label) . '</option>';
            }
            return $out;
        };

        $redisState = $dynState['redis'] ?? [];
        $cacheState = $dynState['cache'] ?? [];
        $serverState = $dynState['server'] ?? [];
        $poolState = $dynState['pool'] ?? [];
        $httpState = $dynState['http'] ?? [];
        $corsState = $dynState['cors'] ?? [];
        $rateLimitState = $dynState['rate_limit'] ?? [];

        $redisModeOptions = $opt((string) ($redisState['enabled'] ?? 'auto'), [
            'auto' => 'Auto (Use Redis if configured & available)',
            'enabled' => 'Enabled (Activate Redis dynamically)',
            'disabled' => 'Disabled (Run cleanly without Redis)',
        ]);
        $redisClientOptions = $opt((string) ($redisState['client'] ?? 'auto'), [
            'auto' => 'Auto (Detect phpredis or predis)',
            'phpredis' => 'phpredis (PHP Extension)',
            'predis' => 'predis (Pure PHP)',
        ]);
        $platformOptions = $opt((string) ($serverState['platform'] ?? 'auto'), [
            'auto' => 'Auto-detect any server',
            'apache' => 'Apache (mod_php / PHP-FPM)',
            'webuzo' => 'Webuzo Panel (Apache + PHP-FPM)',
            'nginx' => 'Nginx + PHP-FPM',
            'litespeed' => 'LiteSpeed Enterprise',
            'openlitespeed' => 'OpenLiteSpeed',
            'caddy' => 'Caddy Web Server',
            'frankenphp' => 'FrankenPHP',
            'octane' => 'Laravel Octane (Swoole / RoadRunner)',
        ]);
        $forceHttpsOptions = $opt((string) ($serverState['force_https'] ?? 'auto'), [
            'auto' => 'Auto (Detect from TLS / X-Forwarded-Proto)',
            'true' => 'Force HTTPS URLs',
            'false' => 'Force HTTP URLs',
        ]);
        $poolStrategyOptions = $opt((string) ($poolState['strategy'] ?? 'least_busy'), [
            'least_busy' => 'least_busy',
            'round_robin' => 'round_robin',
            'random' => 'random',
        ]);

        $safeRedisHost = e((string) ($redisState['host'] ?? ''));
        $safeRedisPort = e((string) ($redisState['port'] ?? 6379));
        $safeRedisDb = e((string) ($redisState['database'] ?? 0));
        $safeRedisUrl = e((string) ($redisState['url'] ?? ''));
        $redisPwPlaceholder = ($redisState['has_password'] ?? false)
            ? 'Saved securely (leave blank to keep)'
            : 'Optional Redis AUTH password';
        $safeCacheStore = e((string) ($cacheState['store'] ?? ''));
        $safeModelsTtl = e((string) ($cacheState['models_ttl'] ?? 60));
        $safeTrustedProxies = e((string) ($serverState['trusted_proxies'] ?? '*'));
        $safeStreamTimeout = e((string) ($serverState['stream_max_execution_time'] ?? 300));
        $safeMaxConcurrent = e((string) ($poolState['max_concurrent_per_token'] ?? 5));
        $safeCooldownSec = e((string) ($poolState['cooldown_seconds'] ?? 60));
        $safeReadTimeout = e((string) ($httpState['read_timeout'] ?? 120));
        $safeCorsOrigins = e((string) ($corsState['allowed_origins'] ?? '*'));
        $safeRlMax = e((string) ($rateLimitState['max_attempts'] ?? 180));
        $safeRlDecay = e((string) ($rateLimitState['decay_seconds'] ?? 60));

        $chkPersist = ($redisState['persistent'] ?? true) ? ' checked' : '';
        $chkAssetsRedis = ($cacheState['assets_in_redis'] ?? true) ? ' checked' : '';
        $chkTrustProxies = ($serverState['trust_proxies'] ?? true) ? ' checked' : '';
        $chkDisableZlib = ($serverState['disable_output_compression'] ?? true) ? ' checked' : '';
        $chkCorsEnabled = ($corsState['enabled'] ?? true) ? ' checked' : '';
        $chkRateLimitEnabled = ($rateLimitState['enabled'] ?? false) ? ' checked' : '';
        $overridesBadge = ((int) ($dynState['overrides_active'] ?? 0)) > 0
            ? '<span style="padding:2px 8px;border-radius:99px;background:#fef3c7;color:#92400e;font-size:11px;font-weight:600">' . (int) $dynState['overrides_active'] . ' Live DB Overrides Active</span>'
            : '<span style="padding:2px 8px;border-radius:99px;background:#f3f4f6;color:#374151;font-size:11px;font-weight:600">Using .env / Config Defaults</span>';

        return <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width,initial-scale=1.0" />
<title>Laravel ChatGPT Mirror - Settings & Admin Dashboard</title>
<style>
  :root { --bg:#fafafa; --card:#fff; --text:#111827; --muted:#6b7280; --border:#e5e7eb; --accent:#111827; }
  * { box-sizing:border-box; }
  body { margin:0; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif; background:var(--bg); color:var(--text); }
  .nav { background:#fff; border-bottom:1px solid var(--border); padding:0 24px; height:56px; display:flex; align-items:center; justify-content:space-between; }
  .nav-brand { font-weight:700; font-size:15px; display:flex; align-items:center; gap:10px; }
  .nav-links { display:flex; gap:18px; font-size:13px; }
  .nav-links a { color:#374151; text-decoration:none; font-weight:500; }
  .nav-links a.active { color:#000; font-weight:700; }
  .container { max-width:1160px; margin:24px auto; padding:0 20px; }
  .stats { display:grid; grid-template-columns:repeat(4,1fr); gap:14px; margin-bottom:20px; }
  .card { background:var(--card); border:1px solid var(--border); border-radius:12px; padding:18px; margin-bottom:18px; }
  .stat-val { font-size:26px; font-weight:700; margin-top:4px; }
  .stat-lbl { font-size:12px; color:var(--muted); text-transform:uppercase; letter-spacing:.04em; }
  h2 { font-size:16px; margin:0 0 12px 0; display:flex; align-items:center; justify-content:space-between; gap:8px; flex-wrap:wrap; }
  table { width:100%; border-collapse:collapse; font-size:13px; }
  th,td { text-align:left; padding:10px 12px; border-bottom:1px solid var(--border); vertical-align:middle; }
  th { font-size:11px; text-transform:uppercase; color:var(--muted); background:#f9fafb; }
  .btn { padding:8px 14px; border-radius:8px; border:1px solid var(--border); background:#111827; color:#fff; font-weight:600; font-size:12px; cursor:pointer; }
  .btn-sm { padding:4px 9px; font-size:11px; }
  .btn-danger { background:#dc2626; border-color:#dc2626; }
  .btn-light { background:#fff; color:#111827; }
  .input { width:100%; padding:9px 11px; border:1px solid var(--border); border-radius:8px; font-size:13px; margin-bottom:10px; background:#fff; color:var(--text); }
  .field-label { display:block; font-size:11px; font-weight:600; color:var(--muted); margin-bottom:4px; text-transform:uppercase; letter-spacing:.03em; }
  .chk-row { display:flex; align-items:center; gap:8px; font-size:13px; margin:6px 0; cursor:pointer; }
  .grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:18px; }
  .grid-3 { display:grid; grid-template-columns:repeat(3,1fr); gap:14px; }
  pre { background:#0f172a; color:#f8fafc; padding:12px; border-radius:8px; font-size:12px; overflow:auto; margin:0; }
  @media(max-width:768px) { .stats,.grid-2,.grid-3 { grid-template-columns:1fr; } }
</style>
</head>
<body>
<nav class="nav">
  <div class="nav-brand">Laravel ChatGPT Mirror <span style="font-size:11px;color:#059669;background:#ecfdf5;padding:2px 8px;border-radius:99px">Direct OpenAI Gateway</span></div>
  <div class="nav-links">
    <a href="{$safeHomeUrl}">ChatGPT Web</a>
    <a href="{$safePlaygroundUrl}">Playground</a>
    <a href="{$safeDocsUrl}">API Docs</a>
    <a href="{$safeAdminBase}" class="active">Settings &amp; Admin Dashboard</a>
    <a href="{$safeHelpUrl}">Help &amp; About</a>
  </div>
</nav>
<div class="container">
  {$flashHtml}
  <div class="stats">
    <div class="card"><div class="stat-lbl">Active Accounts</div><div class="stat-val">{$stats['active_tokens']} / {$stats['total_tokens']}</div></div>
    <div class="card"><div class="stat-lbl">In Cooldown</div><div class="stat-val">{$stats['cooldown_tokens']}</div></div>
    <div class="card"><div class="stat-lbl">Active Requests</div><div class="stat-val">{$stats['total_active_requests']}</div></div>
    <div class="card"><div class="stat-lbl">Lifetime Requests</div><div class="stat-val">{$stats['total_lifetime_requests']}</div></div>
  </div>

  <div class="grid-2">
    <div class="card">
      <h2>Add ChatGPT Account Token</h2>
      <form method="POST" action="{$safeAdminBase}/tokens" enctype="multipart/form-data">
        <input type="text" name="name" class="input" placeholder="Account label (optional, auto-detected if empty)" />
        <textarea id="token_paste_input" name="session_token" rows="4" class="input" placeholder="Paste entire token.txt (/api/auth/session JSON), Cookie header, cURL, or raw __Secure-next-auth.session-token value here..."></textarea>
        <div style="font-size:11px;color:var(--muted);margin:-4px 0 8px 0">Supports full <code>chatgpt.com/api/auth/session</code> JSON (<code>token.txt</code>), browser cookies JSON, Netscape cookies, HTTP <code>Cookie:</code> headers, or raw token — extra fields are extracted automatically.</div>
        <input type="file" id="token_file_input" name="token_file" accept=".txt,.json,.cookie,.cookies,text/plain,application/json" style="font-size:12px;margin-bottom:10px;display:block" />
        <input type="text" name="turnstile_token" class="input" placeholder="Optional Turnstile token (if Sentinel requires it)" />
        <button type="submit" class="btn">Extract, Verify &amp; Save Account</button>
      </form>
    </div>
    <div class="card">
      <h2>OpenAI API Gateway Configuration</h2>
      <p style="font-size:13px;color:var(--muted);margin-top:0">Use any OpenAI SDK pointed cleanly at this Mirror server:</p>
      <pre>Base URL: {$safeBase}{$safePrefix}
API Key:  {$safeKey}
Routes:   {$safePrefix}/chat/completions, {$safePrefix}/responses, {$safePrefix}/models</pre>
      <div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap">
        <form method="POST" action="{$safeAdminBase}/tokens/reset-counters"><button type="submit" class="btn btn-light">Reset Pool Counters</button></form>
        <a href="{$safeDiagnosticsUrl}" class="btn btn-light" style="text-decoration:none;display:inline-flex;align-items:center">JSON Diagnostics</a>
      </div>
    </div>
  </div>

  <div class="card">
    <h2>Runtime, Web Server &amp; Redis Readiness {$redisBadge}</h2>
    <table>
      <tbody>
        <tr><th>Web Server / SAPI</th><td>{$safeServerPlatform} · {$safePhpSapi}</td><th>Proxy / HTTPS Origin</th><td>{$safeHttpsState}</td></tr>
        <tr><th>SSE Buffering</th><td>{$safeZlibState}</td><th>Concurrency &amp; Locks</th><td>{$safeLocksState}</td></tr>
        <tr><th>Redis Extensions</th><td>{$safeRedisExt}</td><th>Production Setup CLI</th><td><code>php artisan mirror:server --write</code> / <code>--vhost</code></td></tr>
      </tbody>
    </table>
  </div>

  <div class="card">
    <h2>Dynamic Server, Proxy &amp; Redis Configuration {$overridesBadge}</h2>
    <p style="font-size:13px;color:var(--muted);margin-top:0">Adjust web server behavior, Redis caching, or pool routing dynamically from the database—nothing stays hardcoded, and if Redis is unavailable or disabled the app runs seamlessly on fallback storage.</p>
    <form method="POST" action="{$safeAdminBase}/runtime" id="dynamic-runtime-form">
      <input type="hidden" name="_runtime_form" value="1" />
      <input type="hidden" name="_has_gateway_controls" value="1" />
      <div class="grid-3">
        <div>
          <label class="field-label" for="rt_redis_enabled">Redis Mode</label>
          <select id="rt_redis_enabled" name="redis_enabled" class="input">{$redisModeOptions}</select>

          <label class="field-label" for="rt_redis_client">Redis Client Driver</label>
          <select id="rt_redis_client" name="redis_client" class="input">{$redisClientOptions}</select>

          <label class="field-label" for="rt_redis_host">Redis Host / Socket</label>
          <input id="rt_redis_host" type="text" name="redis_host" class="input" value="{$safeRedisHost}" placeholder="127.0.0.1 or /var/run/redis/redis.sock" />

          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
            <div>
              <label class="field-label" for="rt_redis_port">Redis Port</label>
              <input id="rt_redis_port" type="number" name="redis_port" class="input" value="{$safeRedisPort}" min="1" max="65535" />
            </div>
            <div>
              <label class="field-label" for="rt_redis_db">Redis DB</label>
              <input id="rt_redis_db" type="number" name="redis_database" class="input" value="{$safeRedisDb}" min="0" max="15" />
            </div>
          </div>

          <label class="field-label" for="rt_redis_url">Redis URL (Optional DSN)</label>
          <input id="rt_redis_url" type="text" name="redis_url" class="input" value="{$safeRedisUrl}" placeholder="redis://127.0.0.1:6379/0" />

          <label class="field-label" for="rt_redis_pw">Redis Password</label>
          <input id="rt_redis_pw" type="password" name="redis_password" class="input" value="" placeholder="{$redisPwPlaceholder}" autocomplete="new-password" />
          <label class="chk-row"><input type="checkbox" name="clear_redis_password" value="1" /> Clear saved Redis password</label>
        </div>

        <div>
          <label class="field-label" for="rt_server_platform">Web Server Platform</label>
          <select id="rt_server_platform" name="server_platform" class="input">{$platformOptions}</select>

          <label class="field-label" for="rt_force_https">HTTPS Scheme Handling</label>
          <select id="rt_force_https" name="server_force_https" class="input">{$forceHttpsOptions}</select>

          <label class="field-label" for="rt_trusted_proxies">Trusted Proxies (* or CIDRs)</label>
          <input id="rt_trusted_proxies" type="text" name="server_trusted_proxies" class="input" value="{$safeTrustedProxies}" placeholder="*" />

          <label class="field-label" for="rt_stream_timeout">SSE Stream Max Execution (sec, 0=unlimited)</label>
          <input id="rt_stream_timeout" type="number" name="server_stream_max_execution_time" class="input" value="{$safeStreamTimeout}" min="0" max="3600" />

          <label class="field-label" for="rt_read_timeout">Upstream SSE Idle Read Timeout (sec)</label>
          <input id="rt_read_timeout" type="number" name="http_read_timeout" class="input" value="{$safeReadTimeout}" min="5" max="3600" />

          <label class="field-label" for="rt_cache_store">Laravel Cache Store Override</label>
          <input id="rt_cache_store" type="text" name="cache_store" class="input" value="{$safeCacheStore}" placeholder="Auto (leave blank unless pinning store)" />

          <label class="field-label" for="rt_models_ttl">Models Cache TTL (seconds)</label>
          <input id="rt_models_ttl" type="number" name="cache_models_ttl" class="input" value="{$safeModelsTtl}" min="0" max="86400" />
        </div>

        <div>
          <label class="field-label" for="rt_pool_strategy">Token Pool Strategy</label>
          <select id="rt_pool_strategy" name="pool_strategy" class="input">{$poolStrategyOptions}</select>

          <label class="field-label" for="rt_pool_conc">Max Concurrent Requests / Account</label>
          <input id="rt_pool_conc" type="number" name="pool_max_concurrent_per_token" class="input" value="{$safeMaxConcurrent}" min="1" max="100" />

          <label class="field-label" for="rt_pool_cool">Rate-Limit Cooldown (seconds)</label>
          <input id="rt_pool_cool" type="number" name="pool_cooldown_seconds" class="input" value="{$safeCooldownSec}" min="1" max="3600" />

          <label class="field-label" for="rt_cors_origins">Allowed CORS Origins (* or comma-separated)</label>
          <input id="rt_cors_origins" type="text" name="cors_allowed_origins" class="input" value="{$safeCorsOrigins}" placeholder="* or https://app.example.com" />

          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
            <div>
              <label class="field-label" for="rt_rl_max">Rate Limit Max Req</label>
              <input id="rt_rl_max" type="number" name="rate_limit_max_attempts" class="input" value="{$safeRlMax}" min="1" max="100000" />
            </div>
            <div>
              <label class="field-label" for="rt_rl_decay">Window (sec)</label>
              <input id="rt_rl_decay" type="number" name="rate_limit_decay_seconds" class="input" value="{$safeRlDecay}" min="1" max="86400" />
            </div>
          </div>

          <div style="margin-top:10px;padding-top:10px;border-top:1px solid var(--border)">
            <label class="chk-row"><input type="checkbox" name="redis_persistent" value="1"{$chkPersist} /> Persistent Redis connections</label>
            <label class="chk-row"><input type="checkbox" name="cache_assets_in_redis" value="1"{$chkAssetsRedis} /> Store generated media in Redis when active</label>
            <label class="chk-row"><input type="checkbox" name="server_trust_proxies" value="1"{$chkTrustProxies} /> Trust reverse-proxy headers (X-Forwarded-*)</label>
            <label class="chk-row"><input type="checkbox" name="server_disable_output_compression" value="1"{$chkDisableZlib} /> Disable zlib output compression during SSE</label>
            <label class="chk-row"><input type="checkbox" name="cors_enabled" value="1"{$chkCorsEnabled} /> Enable dynamic API CORS &amp; preflight headers</label>
            <label class="chk-row"><input type="checkbox" name="rate_limit_enabled" value="1"{$chkRateLimitEnabled} /> Enable optional gateway rate limiting</label>
          </div>
        </div>
      </div>

      <div style="margin-top:14px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <button type="submit" class="btn">Save &amp; Apply Live Settings</button>
        <button type="button" id="test-redis-btn" class="btn btn-light">Test Redis Connection</button>
        <span id="redis-test-result" style="font-size:12px;color:var(--muted)"></span>
      </div>
    </form>
    <form method="POST" action="{$safeAdminBase}/runtime/reset" style="margin-top:10px">
      <button type="submit" class="btn btn-light btn-sm">Reset Overrides to .env Defaults</button>
    </form>
  </div>

  <div class="card">
    <h2>Connected ChatGPT Accounts Pool</h2>
    <table>
      <thead><tr><th>Account</th><th>Email</th><th>Plan</th><th>Status</th><th>Active / Total</th><th>JWT Expiry</th><th>Actions</th></tr></thead>
      <tbody>{$tokenRows}</tbody>
    </table>
  </div>

  <div class="grid-2">
    <div class="card">
      <h2>Recent Tracked Conversations</h2>
      <table>
        <thead><tr><th>ID</th><th>Model</th><th>Title</th><th>Last Active</th></tr></thead>
        <tbody>{$convRows}</tbody>
      </table>
    </div>
    <div class="card">
      <h2>Recent Protocol Failures (Sanitized)</h2>
      <table>
        <thead><tr><th>Time</th><th>Code</th><th>Category</th><th>Request ID</th></tr></thead>
        <tbody>{$failureRows}</tbody>
      </table>
    </div>
  </div>
</div>
<script>
(function () {
  var fileInput = document.getElementById('token_file_input');
  var pasteInput = document.getElementById('token_paste_input');
  if (fileInput && pasteInput && window.FileReader) {
    fileInput.addEventListener('change', function () {
      var f = fileInput.files && fileInput.files[0];
      if (!f) return;
      var reader = new FileReader();
      reader.onload = function (ev) {
        if (ev && ev.target && typeof ev.target.result === 'string') {
          pasteInput.value = ev.target.result;
        }
      };
      reader.readAsText(f);
    });
  }

  var btn = document.getElementById('test-redis-btn');
  var out = document.getElementById('redis-test-result');
  if (!btn || !out) return;
  btn.addEventListener('click', async function () {
    out.style.color = '#6b7280';
    out.textContent = 'Testing Redis connection...';
    var payload = {
      redis_client: document.getElementById('rt_redis_client').value,
      redis_host: document.getElementById('rt_redis_host').value,
      redis_port: parseInt(document.getElementById('rt_redis_port').value || '6379', 10),
      redis_database: parseInt(document.getElementById('rt_redis_db').value || '0', 10),
      redis_url: document.getElementById('rt_redis_url').value
    };
    var pw = document.getElementById('rt_redis_pw').value;
    if (pw) payload.redis_password = pw;
    try {
      var res = await fetch('{$safeAdminBase}/runtime/test-redis', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify(payload)
      });
      var data = await res.json();
      if (data && data.ok) {
        out.style.color = '#047857';
        out.textContent = 'Connected (' + (data.client || 'auto') + '): ' + (data.ping || 'PONG') + ' in ' + data.latency_ms + 'ms';
      } else {
        out.style.color = '#b91c1c';
        out.textContent = 'Failed (' + ((data && data.client) || 'auto') + '): ' + ((data && data.error) || 'Connection refused');
      }
    } catch (e) {
      out.style.color = '#b91c1c';
      out.textContent = 'Request failed: ' + (e && e.message ? e.message : 'network error');
    }
  });
})();
</script>
</body>
</html>
HTML;
    }
}
