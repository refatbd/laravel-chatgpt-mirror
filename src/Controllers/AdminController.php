<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Refatbd\LaravelChatGptMirror\Models\MirrorConversation;
use Refatbd\LaravelChatGptMirror\Models\MirrorToken;
use Refatbd\LaravelChatGptMirror\Services\FailureTracker;
use Refatbd\LaravelChatGptMirror\Services\SecurityService;
use Refatbd\LaravelChatGptMirror\Services\TokenPoolManager;
use Refatbd\LaravelChatGptMirror\Services\TokenRefresher;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Provides Admin UI dashboard, pool metrics, health/diagnostics/capabilities APIs,
 * and programmatic /api/session token lifecycle management.
 */
class AdminController extends Controller
{
    private const FLASH_COOKIE = 'mirror_admin_flash';

    public function __construct(
        protected TokenPoolManager $pool,
        protected TokenRefresher $refresher,
        protected FailureTracker $failures,
        protected SecurityService $security
    ) {}

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
        $sessionToken = trim((string) $request->input('session_token', ''));
        $turnstileToken = trim((string) $request->input('turnstile_token', ''));
        $customName = trim((string) $request->input('name', ''));

        if (strlen($sessionToken) < 20) {
            return $this->redirectWithFlash('error', 'Session token must be at least 20 characters.');
        }

        $verified = $this->refresher->verifyCandidateSessionToken($sessionToken);
        if (!$verified) {
            $this->failures->record('invalid_session_token', $request->header('x-request-id'), 'session_verification_failed');
            return $this->redirectWithFlash('error', 'ChatGPT upstream rejected this __Secure-next-auth.session-token.');
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

        if ($turnstileToken !== '') {
            $token->setTurnstileToken($turnstileToken);
            $token->save();
        }

        $label = $token->email ?: $token->name;
        return $this->redirectWithFlash('success', "Verified and saved account: {$label}");
    }

    public function refreshToken(int|string $id): Response
    {
        $token = MirrorToken::findOrFail($id);
        $ok = $this->refresher->refresh($token);

        return $ok
            ? $this->redirectWithFlash('success', "Token #{$token->id} ({$token->name}) refreshed successfully.")
            : $this->redirectWithFlash('error', "Failed to refresh token #{$token->id}: " . ($token->last_error ?: 'upstream rejected session.'));
    }

    public function deleteToken(int|string $id): Response
    {
        $token = MirrorToken::findOrFail($id);
        $label = $token->name;
        $token->delete();
        $this->security->rotateAssetLinkGeneration();

        return $this->redirectWithFlash('success', "Removed account '{$label}' and rotated asset tickets.");
    }

    public function resetCounters(): Response
    {
        MirrorToken::query()->update([
            'active_requests' => 0,
            'consecutive_errors' => 0,
            'cooldown_until' => null,
            'status' => 'active',
            'last_error' => null,
        ]);

        return $this->redirectWithFlash('success', 'All token counters and cooldowns were reset.');
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

    public function health(): Response
    {
        $dbOk = true;
        try {
            DB::select('SELECT 1');
        } catch (\Throwable) {
            $dbOk = false;
        }

        $stats = $this->pool->getPoolStats();
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
        ], $healthy ? 200 : 503);
    }

    public function diagnostics(): Response
    {
        $dbOk = true;
        try {
            DB::select('SELECT 1');
        } catch (\Throwable) {
            $dbOk = false;
        }

        return response()->json([
            'schemaVersion' => '2026-11',
            'ok' => $dbOk,
            'version' => '2.1.0',
            'build' => [
                'package' => 'refatbd/laravel-chatgpt-mirror',
                'version' => '2.1.0',
                'framework' => 'laravel',
                'php' => PHP_VERSION,
            ],
            'storage' => [
                'engine' => (string) config('database.default'),
                'healthy' => $dbOk,
            ],
            'config' => [
                'upstream' => (string) config('mirror.upstream', 'https://chatgpt.com'),
                'egressMode' => 'direct',
                'openaiPrefix' => '/' . trim((string) config('mirror.routes.openai_prefix', 'v1'), '/'),
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
        $openaiPrefix = '/' . trim((string) config('mirror.routes.openai_prefix', 'v1'), '/');

        $endpoints = [
            'chat_completions' => "{$openaiPrefix}/chat/completions",
            'responses' => "{$openaiPrefix}/responses",
            'models' => "{$openaiPrefix}/models",
            'capabilities' => "{$openaiPrefix}/capabilities",
            'playground' => '/mirror/playground',
            'admin' => '/' . trim((string) config('mirror.routes.admin_prefix', 'mirror/admin'), '/'),
            'api_docs' => '/mirror/api-docs',
            'openapi' => '/mirror/openapi',
            'mirror_chat' => '/api/chat',
            'mirror_conversations' => '/api/conversations',
            'mirror_files' => '/api/files',
            'mirror_assets' => '/api/assets',
            'mirror_convert' => '/api/convert/formats',
            'mirror_decoder_challenges' => '/api/decoder-challenges',
        ];

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
            'browser_proxy' => true,
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
            'notes' => [
                'websocket_proxy' => 'PHP built-in development server does not terminate raw WebSocket upgrades; HTTP SSE and proxy fallback operate directly.',
            ],
        ]);
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
        $sessionToken = trim((string) ($request->input('sessionToken') ?? $request->input('session_token') ?? ''));
        $turnstileToken = trim((string) ($request->input('turnstileToken') ?? $request->input('turnstile_token') ?? ''));
        $name = trim((string) ($request->input('name') ?? ''));

        if (strlen($sessionToken) < 20) {
            return response()->json(['error' => 'That does not look like a valid sessionToken (minimum 20 characters).'], 400);
        }

        $verified = $this->refresher->verifyCandidateSessionToken($sessionToken);
        if (!$verified) {
            $this->failures->record('invalid_session_token', $request->header('x-request-id'), 'session_verification_failed');
            return response()->json(['error' => 'Failed to verify session token with ChatGPT upstream'], 401);
        }

        $hash = hash('sha256', $verified['session_token']);
        $token = MirrorToken::where('token_hash', $hash)->first();

        $attrs = [
            'name' => $name !== '' ? $name : ($verified['name'] ?: 'API Session Account'),
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
            $token->update($attrs);
        } else {
            $token = MirrorToken::create($attrs);
        }

        if ($turnstileToken !== '') {
            $token->setTurnstileToken($turnstileToken);
            $token->save();
        }

        $this->security->rotateAssetLinkGeneration();

        return response()->json([
            'ok' => true,
            'id' => $token->id,
            'accountId' => $token->account_id,
            'email' => $token->email,
            'plan_type' => $token->plan_type,
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

    public function deleteSession(): Response
    {
        MirrorToken::query()->delete();
        $this->security->rotateAssetLinkGeneration();

        return response()->json(['ok' => true, 'message' => 'All mirror sessions cleared']);
    }

    protected function redirectWithFlash(string $type, string $message): Response
    {
        $payload = json_encode(['type' => $type, 'message' => $message]);
        $sig = hash_hmac('sha256', (string) $payload, $this->security->hmacSecret());
        $encoded = base64_encode(json_encode(['p' => $payload, 's' => $sig]));

        $adminPath = '/' . trim((string) config('mirror.routes.admin_prefix', 'mirror/admin'), '/');
        $response = redirect($adminPath);
        $response->headers->setCookie(Cookie::create(
            self::FLASH_COOKIE,
            $encoded,
            now()->addMinutes(5)->getTimestamp(),
            '/',
            null,
            false,
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
        $baseUrl = $request->getSchemeAndHttpHost();
        $openaiPrefix = '/' . trim((string) config('mirror.routes.openai_prefix', 'v1'), '/');
        $adminBase = $baseUrl . '/' . trim((string) config('mirror.routes.admin_prefix', 'mirror/admin'), '/');
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
                    <form method='POST' action='{$adminBase}/tokens/{$t->id}/refresh'><button type='submit' class='btn btn-sm'>Refresh</button></form>
                    <form method='POST' action='{$adminBase}/tokens/{$t->id}/delete' onsubmit='return confirm(\"Delete account?\")'><button type='submit' class='btn btn-sm btn-danger'>Delete</button></form>
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
        $safePrefix = e($openaiPrefix);
        $safePlaygroundUrl = e($baseUrl . '/mirror/playground');
        $safeDocsUrl = e($baseUrl . '/mirror/api-docs');
        $safeHomeUrl = e($baseUrl . '/');

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
  h2 { font-size:16px; margin:0 0 12px 0; display:flex; align-items:center; justify-content:space-between; }
  table { width:100%; border-collapse:collapse; font-size:13px; }
  th,td { text-align:left; padding:10px 12px; border-bottom:1px solid var(--border); vertical-align:middle; }
  th { font-size:11px; text-transform:uppercase; color:var(--muted); background:#f9fafb; }
  .btn { padding:8px 14px; border-radius:8px; border:1px solid var(--border); background:#111827; color:#fff; font-weight:600; font-size:12px; cursor:pointer; }
  .btn-sm { padding:4px 9px; font-size:11px; }
  .btn-danger { background:#dc2626; border-color:#dc2626; }
  .btn-light { background:#fff; color:#111827; }
  .input { width:100%; padding:9px 11px; border:1px solid var(--border); border-radius:8px; font-size:13px; margin-bottom:10px; }
  .grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:18px; }
  pre { background:#0f172a; color:#f8fafc; padding:12px; border-radius:8px; font-size:12px; overflow:auto; margin:0; }
  @media(max-width:768px) { .stats,.grid-2 { grid-template-columns:1fr; } }
</style>
</head>
<body>
<nav class="nav">
  <div class="nav-brand">Laravel ChatGPT Mirror <span style="font-size:11px;color:#059669;background:#ecfdf5;padding:2px 8px;border-radius:99px">Direct OpenAI Gateway</span></div>
  <div class="nav-links">
    <a href="{$safeHomeUrl}">ChatGPT Web</a>
    <a href="{$safePlaygroundUrl}">Playground</a>
    <a href="{$safeDocsUrl}">API Docs</a>
    <a href="{$adminBase}" class="active">Settings &amp; Admin Dashboard</a>
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
      <form method="POST" action="{$adminBase}/tokens">
        <input type="text" name="name" class="input" placeholder="Account label (optional, auto-detected if empty)" />
        <textarea name="session_token" rows="3" class="input" placeholder="Paste __Secure-next-auth.session-token value here..." required></textarea>
        <input type="text" name="turnstile_token" class="input" placeholder="Optional Turnstile token (if Sentinel requires it)" />
        <button type="submit" class="btn">Verify &amp; Save Account</button>
      </form>
    </div>
    <div class="card">
      <h2>OpenAI API Gateway Configuration</h2>
      <p style="font-size:13px;color:var(--muted);margin-top:0">Use any OpenAI SDK pointed cleanly at this Mirror server:</p>
      <pre>Base URL: {$safeBase}{$safePrefix}
API Key:  {$safeKey}
Routes:   {$safePrefix}/chat/completions, {$safePrefix}/responses, {$safePrefix}/models</pre>
      <div style="margin-top:12px;display:flex;gap:8px">
        <form method="POST" action="{$adminBase}/tokens/reset-counters"><button type="submit" class="btn btn-light">Reset Pool Counters</button></form>
        <a href="{$safeBase}/api/diagnostics" class="btn btn-light" style="text-decoration:none;display:inline-flex;align-items:center">JSON Diagnostics</a>
      </div>
    </div>
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
</body>
</html>
HTML;
    }
}
