<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Refatbd\LaravelChatGptMirror\Services\DynamicConfigService;
use Refatbd\LaravelChatGptMirror\Services\MirrorCache;
use Refatbd\LaravelChatGptMirror\Services\SecurityService;
use Refatbd\LaravelChatGptMirror\Services\TokenPoolManager;
use Symfony\Component\HttpFoundation\Response;

/**
 * Renders the dynamic, searchable Help, About, Features, and Configuration Guide
 * at `/mirror/help` (or custom `MIRROR_HELP_PREFIX`) with live environment awareness
 * and zero secret disclosure.
 */
class HelpController extends Controller
{
    public function __construct(
        protected TokenPoolManager $pool,
        protected SecurityService $security,
        protected ?MirrorCache $cache = null,
        protected ?DynamicConfigService $dynamicConfig = null
    ) {
        $this->cache ??= app()->bound(MirrorCache::class) ? app(MirrorCache::class) : new MirrorCache();
        $this->dynamicConfig ??= app()->bound(DynamicConfigService::class) ? app(DynamicConfigService::class) : new DynamicConfigService();
    }

    protected function cache(): MirrorCache
    {
        return $this->cache ??= app()->bound(MirrorCache::class) ? app(MirrorCache::class) : new MirrorCache();
    }

    protected function dynamicConfig(): DynamicConfigService
    {
        return $this->dynamicConfig ??= app()->bound(DynamicConfigService::class)
            ? app(DynamicConfigService::class)
            : new DynamicConfigService();
    }

    public function index(Request $request): Response
    {
        $this->dynamicConfig()->ensureApplied();

        $originUrl = $this->security->resolveOrigin($request);
        $basePath = $this->security->resolveBasePath($request);
        $baseUrl = $this->security->resolveBaseUrl($request);
        $paths = $this->resolvePaths($basePath);

        $stats = $this->pool->getPoolStats();
        $cacheDiag = $this->cache()->diagnostics();
        $dynState = $this->dynamicConfig()->publicState();
        $authEnabled = $this->security->configuredApiKeys() !== [];

        $serverSoftware = (string) ($request->server('SERVER_SOFTWARE') ?: ($_SERVER['SERVER_SOFTWARE'] ?? PHP_SAPI));
        $httpsDetected = $this->security->isSecureRequest($request);
        $powReady = in_array('sha3-512', hash_algos(), true);
        $gdReady = extension_loaded('gd');

        $html = $this->renderHtml([
            'originUrl' => $originUrl,
            'basePath' => $basePath,
            'baseUrl' => $baseUrl,
            'paths' => $paths,
            'stats' => $stats,
            'cacheDiag' => $cacheDiag,
            'dynState' => $dynState,
            'authEnabled' => $authEnabled,
            'serverSoftware' => $serverSoftware,
            'httpsDetected' => $httpsDetected,
            'powReady' => $powReady,
            'gdReady' => $gdReady,
        ]);

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * @return array<string, string>
     */
    protected function resolvePaths(string $basePath = ''): array
    {
        $sub = $basePath !== '' ? '/' . trim($basePath, '/') : '';
        $clean = static fn (string $val, string $fallback): string => '/' . (trim($val, '/') !== '' ? trim($val, '/') : $fallback);

        $docsRaw = trim((string) config('mirror.routes.docs_prefix', 'mirror'), '/');
        $docsBase = $docsRaw !== '' ? '/' . $docsRaw : '';

        return [
            'openai' => $sub . $clean((string) config('mirror.routes.openai_prefix', 'v1'), 'v1'),
            'backend' => $sub . $clean((string) config('mirror.routes.backend_prefix', 'backend-api'), 'backend-api'),
            'admin' => $sub . $clean((string) config('mirror.routes.admin_prefix', 'mirror/admin'), 'mirror/admin'),
            'playground' => $sub . $clean((string) config('mirror.routes.playground_prefix', 'mirror/playground'), 'mirror/playground'),
            'help' => $sub . $clean((string) config('mirror.routes.help_prefix', 'mirror/help'), 'mirror/help'),
            'assets' => $sub . $clean((string) config('mirror.routes.assets_prefix', 'mirror/assets'), 'mirror/assets'),
            'api_docs' => $sub . $docsBase . '/api-docs',
            'openapi' => $sub . $docsBase . '/openapi',
            'health' => $sub . '/api/health',
            'diagnostics' => $sub . '/api/diagnostics',
            'runtime_settings' => $sub . '/api/settings/runtime',
        ];
    }

    /**
     * @param array<string, mixed> $ctx
     */
    protected function renderHtml(array $ctx): string
    {
        $originUrl = (string) $ctx['originUrl'];
        $baseUrl = (string) $ctx['baseUrl'];
        /** @var array<string, string> $paths */
        $paths = $ctx['paths'];
        /** @var array<string, mixed> $stats */
        $stats = $ctx['stats'];
        /** @var array<string, mixed> $cacheDiag */
        $cacheDiag = $ctx['cacheDiag'];
        /** @var array<string, mixed> $dynState */
        $dynState = $ctx['dynState'];

        $safeHomeUrl = e($baseUrl . '/');
        $safePlaygroundUrl = e($originUrl . $paths['playground']);
        $safeDocsUrl = e($originUrl . $paths['api_docs']);
        $safeOpenApiUrl = e($originUrl . $paths['openapi']);
        $safeAdminUrl = e($originUrl . $paths['admin']);
        $safeHelpUrl = e($originUrl . $paths['help']);
        $safeOpenAiBase = e($originUrl . $paths['openai']);
        $safeBaseUrl = e($baseUrl);

        $activeTokens = (int) ($stats['active_tokens'] ?? 0);
        $totalTokens = (int) ($stats['total_tokens'] ?? 0);
        $cooldownTokens = (int) ($stats['cooldown_tokens'] ?? 0);
        $redisActive = (bool) ($cacheDiag['redis_active'] ?? false);
        $redisMode = (string) ($dynState['redis']['enabled'] ?? 'auto');
        $cacheDriver = (string) ($cacheDiag['driver'] ?? 'fallback');
        $serverPlatform = (string) ($dynState['server']['platform'] ?? 'auto');
        $poolStrategy = (string) ($dynState['pool']['strategy'] ?? 'least_busy');
        $readTimeout = (int) ($dynState['http']['read_timeout'] ?? 120);
        $streamTimeout = (int) ($dynState['server']['stream_max_execution_time'] ?? 300);
        $corsEnabled = (bool) ($dynState['cors']['enabled'] ?? true);
        $corsOrigins = (string) ($dynState['cors']['allowed_origins'] ?? '*');
        $rlEnabled = (bool) ($dynState['rate_limit']['enabled'] ?? false);
        $rlMax = (int) ($dynState['rate_limit']['max_attempts'] ?? 180);
        $rlDecay = (int) ($dynState['rate_limit']['decay_seconds'] ?? 60);

        $safeOriginBadge = e($baseUrl);
        $safeServerInfo = e(PHP_VERSION . ' / ' . PHP_SAPI . ' (' . $serverPlatform . ')');
        $safeCacheBadge = $redisActive
            ? e('Redis Active (' . ($cacheDiag['redis_ping'] ?? 'PONG') . ')')
            : e('Fallback (' . $cacheDriver . ', mode: ' . $redisMode . ')');
        $safeAuthMode = $ctx['authEnabled'] ? 'Bearer Key / Control Cookie Required' : 'Open (Set MIRROR_API_KEY for Public Servers)';
        $safeHttpsBadge = $ctx['httpsDetected'] ? 'HTTPS Active' : 'HTTP / Behind TLS Proxy';
        $safePowBadge = $ctx['powReady'] ? 'SHA3-512 Ready' : 'Unavailable';
        $safeGdBadge = $ctx['gdReady'] ? 'GD Loaded (Codecs Ready)' : 'Missing GD Extension';

        $parsedHost = (string) (parse_url($originUrl, PHP_URL_HOST) ?: 'YOUR_DOMAIN.COM');
        $safeCliDomain = e($parsedHost);

        $safeOpenAiPath = e($paths['openai']);
        $safeAdminPath = e($paths['admin']);
        $safePlaygroundPath = e($paths['playground']);
        $safeHelpPath = e($paths['help']);
        $safeApiDocsPath = e($paths['api_docs']);
        $safeOpenApiPath = e($paths['openapi']);
        $safeHealthPath = e($paths['health']);
        $safeDiagnosticsPath = e($paths['diagnostics']);
        $safeRuntimePath = e($paths['runtime_settings']);
        $safeCorsOrigins = e($corsOrigins);
        $safeCorsBadge = e($corsEnabled ? "Enabled ({$corsOrigins})" : 'Disabled');
        $safeRlBadge = e($rlEnabled ? "Enabled ({$rlMax} req / {$rlDecay}s)" : "Disabled (Optional {$rlMax} / {$rlDecay}s)");

        $curlSample = e(<<<SH
curl -N "{$originUrl}{$paths['openai']}/chat/completions" \\
  -H "Authorization: Bearer YOUR_MIRROR_API_KEY" \\
  -H "Content-Type: application/json" \\
  -d '{
    "model": "gpt-4o",
    "stream": true,
    "messages": [
      {"role": "system", "content": "Reply concisely."},
      {"role": "user", "content": "Explain Laravel Mirror in 2 sentences."}
    ]
  }'
SH);

        $pythonSample = e(<<<PY
from openai import OpenAI

client = OpenAI(
    base_url="{$originUrl}{$paths['openai']}",
    api_key="YOUR_MIRROR_API_KEY",
)

resp = client.chat.completions.create(
    model="gpt-4o",  # or "g-..." Custom GPT / Project ID
    messages=[{"role": "user", "content": "Hello from Python SDK!"}],
    extra_body={"metadata": {"private": True}},
)
print(resp.choices[0].message.content)
print("Conversation ID:", resp.metadata["conversation_id"])
PY);

        $jsSample = e(<<<JS
import OpenAI from "openai";

const client = new OpenAI({
  baseURL: "{$originUrl}{$paths['openai']}",
  apiKey: "YOUR_MIRROR_API_KEY",
});

const stream = await client.chat.completions.create({
  model: "auto",
  stream: true,
  tools: [{
    type: "function",
    function: { name: "get_weather", parameters: { type: "object" } },
  }],
  messages: [{ role: "user", content: "What is the weather in Dhaka?" }],
});

for await (const chunk of stream) {
  process.stdout.write(chunk.choices?.[0]?.delta?.content ?? "");
}
JS);

        $envSample = e(<<<ENV
# Core Authentication & Upstream
MIRROR_API_KEY=replace-with-strong-secret-key
MIRROR_UPSTREAM_URL=https://chatgpt.com
MIRROR_VERIFY_SSL=true

# Dynamic Route Prefixes (Optional — Customize Anytime)
MIRROR_OPENAI_PREFIX=v1
MIRROR_ADMIN_PREFIX=mirror/admin
MIRROR_PLAYGROUND_PREFIX=mirror/playground
MIRROR_HELP_PREFIX=mirror/help
MIRROR_DOCS_PREFIX=mirror
MIRROR_ASSETS_PREFIX=mirror/assets
MIRROR_BACKEND_PREFIX=backend-api

# Pool & Timeouts
MIRROR_POOL_STRATEGY=least_busy
MIRROR_MAX_CONCURRENT_PER_TOKEN=5
MIRROR_COOLDOWN_SECONDS=60
MIRROR_REQUEST_TIMEOUT=120
MIRROR_READ_TIMEOUT=120
MIRROR_STREAM_MAX_EXECUTION_TIME=300
MIRROR_DISABLE_OUTPUT_COMPRESSION=true

# Universal Web Server & Proxy (Apache / Webuzo / Nginx / LiteSpeed / Caddy / Octane)
MIRROR_SERVER_PLATFORM=auto
MIRROR_TRUST_PROXIES=true
MIRROR_TRUSTED_PROXIES=*
MIRROR_FORCE_HTTPS=

# Optional Redis (auto uses Redis only if active; falls back cleanly otherwise)
MIRROR_REDIS_ENABLED=auto
MIRROR_REDIS_CLIENT=auto
MIRROR_REDIS_HOST=127.0.0.1
MIRROR_REDIS_PORT=6379
MIRROR_REDIS_PASSWORD=
MIRROR_REDIS_DB=0
MIRROR_CACHE_ASSETS_IN_REDIS=true

# CORS & Optional Rate Limiting
MIRROR_CORS_ENABLED=true
MIRROR_CORS_ALLOWED_ORIGINS=*
MIRROR_RATE_LIMIT_ENABLED=false
MIRROR_RATE_LIMIT_MAX_ATTEMPTS=180
MIRROR_RATE_LIMIT_DECAY_SECONDS=60
ENV);

        return <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width,initial-scale=1.0" />
<meta name="color-scheme" content="light" />
<title>Help, About &amp; Configuration Guide - Laravel ChatGPT Mirror</title>
<style>
  :root {
    color-scheme:light;
    --bg:#f8fafc; --card:#ffffff; --text:#0f172a; --muted:#475569; --soft:#64748b;
    --border:#e2e8f0; --accent:#2563eb; --brand:#059669; --brand-bg:#ecfdf5;
    --info:#2563eb; --info-bg:#eff6ff; --warn:#b45309; --warn-bg:#fef3c7;
    --code-bg:#f8fafc; --code-fg:#0f172a;
  }
  * { box-sizing:border-box; }
  html { scroll-behavior:smooth; color-scheme:light; }
  body { margin:0; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Inter,sans-serif; background:var(--bg); color:var(--text); line-height:1.58; }
  .nav { position:sticky; top:0; z-index:40; background:rgba(255,255,255,0.96); backdrop-filter:blur(8px); border-bottom:1px solid var(--border); padding:0 24px; min-height:56px; display:flex; align-items:center; justify-content:space-between; gap:14px; flex-wrap:wrap; }
  .nav-brand { font-weight:700; font-size:15px; color:var(--text); display:flex; align-items:center; gap:10px; }
  .nav-pill { font-size:11px; color:var(--brand); background:var(--brand-bg); padding:2px 9px; border-radius:99px; font-weight:600; }
  .nav-links { display:flex; gap:18px; font-size:13px; flex-wrap:wrap; align-items:center; }
  .nav-links a { color:#475569; text-decoration:none; font-weight:500; padding:6px 2px; border-bottom:2px solid transparent; }
  .nav-links a:hover { color:#0f172a; }
  .nav-links a.active { color:#1d4ed8; font-weight:700; border-bottom-color:#2563eb; }

  .layout { max-width:1280px; margin:24px auto 56px; padding:0 20px; display:grid; grid-template-columns:250px minmax(0,1fr); gap:24px; align-items:start; }
  .toc { position:sticky; top:76px; background:var(--card); border:1px solid var(--border); border-radius:14px; padding:16px; }
  .toc-title { font-size:11px; text-transform:uppercase; letter-spacing:.06em; color:var(--soft); font-weight:700; margin:0 0 10px 0; }
  .toc a { display:block; font-size:13px; color:var(--muted); text-decoration:none; padding:6px 8px; border-radius:8px; margin-bottom:2px; font-weight:500; }
  .toc a:hover, .toc a.active-link { background:#eff6ff; color:#1d4ed8; font-weight:600; }
  .toc-search { width:100%; padding:8px 10px; border:1px solid #cbd5e1; border-radius:8px; font-size:12px; margin-bottom:12px; background:#ffffff; color:var(--text); color-scheme:light; }

  .hero { background:linear-gradient(135deg,#ffffff 0%,#eff6ff 55%,#ecfdf5 100%); color:var(--text); border:1px solid #dbeafe; border-radius:16px; padding:26px 28px; margin-bottom:20px; box-shadow:0 4px 18px rgba(37,99,235,.06); }
  .hero-badge { display:inline-block; font-size:11px; font-weight:700; letter-spacing:.05em; text-transform:uppercase; padding:3px 10px; border-radius:99px; background:#ecfdf5; color:#047857; border:1px solid #a7f3d0; margin-bottom:10px; }
  .hero h1 { margin:0 0 8px 0; font-size:24px; line-height:1.25; color:#0f172a; }
  .hero p { margin:0; color:#475569; font-size:14px; max-width:860px; }
  .hero-actions { margin-top:16px; display:flex; gap:10px; flex-wrap:wrap; }
  .hero-btn { display:inline-flex; align-items:center; gap:6px; padding:8px 14px; border-radius:8px; font-size:12px; font-weight:600; text-decoration:none; background:#2563eb; color:#ffffff; border:1px solid #2563eb; }
  .hero-btn:hover { background:#1d4ed8; border-color:#1d4ed8; }
  .hero-btn.secondary { background:#ffffff; color:#0f172a; border:1px solid #cbd5e1; }
  .hero-btn.secondary:hover { background:#f8fafc; border-color:#94a3b8; }

  .status-strip { display:grid; grid-template-columns:repeat(4,1fr); gap:12px; margin-bottom:20px; }
  .stat-card { background:var(--card); border:1px solid var(--border); border-radius:12px; padding:13px 15px; }
  .stat-lbl { font-size:11px; text-transform:uppercase; letter-spacing:.04em; color:var(--soft); font-weight:600; }
  .stat-val { font-size:14px; font-weight:700; color:var(--text); margin-top:4px; word-break:break-word; }

  .section-card { background:var(--card); border:1px solid var(--border); border-radius:14px; padding:22px; margin-bottom:20px; }
  .section-card h2 { margin:0 0 6px 0; font-size:18px; color:var(--text); display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px; }
  .section-sub { margin:0 0 16px 0; font-size:13px; color:var(--muted); }
  .grid-2 { display:grid; grid-template-columns:repeat(2,1fr); gap:14px; }
  .grid-3 { display:grid; grid-template-columns:repeat(3,1fr); gap:14px; }

  .feature-box { border:1px solid var(--border); border-radius:11px; padding:14px 15px; background:#f8fafc; }
  .feature-box h3 { margin:0 0 6px 0; font-size:14px; color:var(--text); display:flex; align-items:center; justify-content:space-between; gap:6px; }
  .feature-box p, .feature-box li { font-size:13px; color:var(--muted); margin:0 0 6px 0; }
  .feature-box ul { margin:6px 0 0 0; padding-left:18px; }

  .tag { display:inline-block; padding:2px 8px; border-radius:99px; font-size:11px; font-weight:600; }
  .tag-green { background:var(--brand-bg); color:var(--brand); }
  .tag-blue { background:var(--info-bg); color:var(--info); }
  .tag-amber { background:var(--warn-bg); color:var(--warn); }
  .tag-slate { background:#f1f5f9; color:#334155; }

  table { width:100%; border-collapse:collapse; font-size:13px; margin-top:8px; background:#ffffff; }
  th, td { text-align:left; padding:10px 12px; border-bottom:1px solid var(--border); vertical-align:top; }
  th { font-size:11px; text-transform:uppercase; letter-spacing:.04em; color:var(--soft); background:#f8fafc; }
  code { font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace; font-size:12px; background:#f1f5f9; color:#0f172a; padding:2px 5px; border-radius:5px; }
  .code-wrap { position:relative; margin-top:8px; }
  pre { background:var(--code-bg); color:var(--code-fg); border:1px solid var(--border); padding:14px; border-radius:10px; font-size:12px; overflow:auto; margin:0; line-height:1.5; }
  .copy-btn { position:absolute; top:8px; right:8px; background:#ffffff; color:#334155; border:1px solid #cbd5e1; border-radius:6px; padding:4px 9px; font-size:11px; font-weight:600; cursor:pointer; }
  .copy-btn:hover { background:#f1f5f9; color:#0f172a; border-color:#94a3b8; }
  .inline-copy { margin-left:6px; border:1px solid var(--border); background:#fff; color:#334155; border-radius:6px; padding:2px 7px; font-size:11px; cursor:pointer; font-weight:600; }
  .inline-copy:hover { background:#f1f5f9; }
  .callout { padding:12px 14px; border-radius:10px; font-size:13px; margin:12px 0; border:1px solid var(--border); background:#f8fafc; }
  .callout.info { background:var(--info-bg); border-color:#bfdbfe; color:#1e40af; }
  .callout.warn { background:var(--warn-bg); border-color:#fde68a; color:#92400e; }

  .hidden-by-search { display:none !important; }
  #search-empty { display:none; padding:24px; text-align:center; background:#fff; border:1px dashed var(--border); border-radius:12px; color:var(--muted); margin-bottom:20px; }

  @media(max-width:980px) {
    .layout { grid-template-columns:1fr; }
    .toc { position:static; }
    .status-strip { grid-template-columns:repeat(2,1fr); }
    .grid-3 { grid-template-columns:1fr; }
  }
  @media(max-width:680px) {
    .status-strip, .grid-2 { grid-template-columns:1fr; }
  }
</style>
</head>
<body>
<nav class="nav">
  <div class="nav-brand">Laravel ChatGPT Mirror <span class="nav-pill">v2.1.0 Documentation</span></div>
  <div class="nav-links">
    <a href="{$safeHomeUrl}">ChatGPT Web</a>
    <a href="{$safePlaygroundUrl}">Playground</a>
    <a href="{$safeDocsUrl}">API Docs</a>
    <a href="{$safeAdminUrl}">Settings &amp; Admin Dashboard</a>
    <a href="{$safeHelpUrl}" class="active">Help &amp; About</a>
  </div>
</nav>

<div class="layout">
  <aside class="toc">
    <input id="help-search" class="toc-search" type="search" placeholder="Search features, env keys, errors..." aria-label="Search documentation" />
    <div class="toc-title">Documentation Index</div>
    <a href="#about">1. About &amp; Architecture</a>
    <a href="#where-is-what">2. Where Is Everything?</a>
    <a href="#features">3. All Features Matrix</a>
    <a href="#setup-steps">4. Setup &amp; Token Guide</a>
    <a href="#settings-reference">5. Settings &amp; .env Reference</a>
    <a href="#runtime-vs-env">6. Live Runtime vs .env</a>
    <a href="#deployment">7. Server &amp; Redis Deployment</a>
    <a href="#sdk-examples">8. SDK, Tools &amp; History API</a>
    <a href="#artisan-cli">9. Artisan CLI Commands</a>
    <a href="#troubleshooting">10. Troubleshooting Guide</a>
  </aside>

  <main>
    <section class="hero" id="about">
      <span class="hero-badge">Pure PHP Laravel Package · Dynamic &amp; Server-Agnostic · Direct PHP Upstream</span>
      <h1>Laravel ChatGPT Mirror — Complete Reference, Setup &amp; Operations Guide</h1>
      <p>A self-hosted, drop-in <strong>OpenAI-compatible API gateway</strong>, transparent <strong>ChatGPT Web UI reverse proxy</strong>, <strong>multi-account token pool</strong>, <strong>interactive Playground</strong>, <strong>Swagger OpenAPI 3.1 docs</strong>, and <strong>binary conversion / decoder challenge suite</strong> running directly inside Laravel on any web server (Apache/Webuzo, Nginx, LiteSpeed, Caddy, FrankenPHP, or Octane).</p>
      <div class="hero-actions">
        <a class="hero-btn" href="{$safeAdminUrl}">Open Admin &amp; Token Pool</a>
        <a class="hero-btn secondary" href="{$safePlaygroundUrl}">Launch Playground</a>
        <a class="hero-btn secondary" href="{$safeDocsUrl}">Open Swagger API Docs</a>
        <a class="hero-btn secondary" href="{$safeHomeUrl}">Open ChatGPT Web Proxy</a>
      </div>
    </section>

    <div class="status-strip">
      <div class="stat-card"><div class="stat-lbl">Active Origin</div><div class="stat-val">{$safeOriginBadge}</div></div>
      <div class="stat-card"><div class="stat-lbl">Runtime / Server</div><div class="stat-val">{$safeServerInfo}</div></div>
      <div class="stat-card"><div class="stat-lbl">Pool Accounts</div><div class="stat-val">{$activeTokens} / {$totalTokens} Active ({$cooldownTokens} Cooldown)</div></div>
      <div class="stat-card"><div class="stat-lbl">Cache &amp; Redis</div><div class="stat-val">{$safeCacheBadge}</div></div>
      <div class="stat-card"><div class="stat-lbl">API Security</div><div class="stat-val">{$safeAuthMode}</div></div>
      <div class="stat-card"><div class="stat-lbl">CORS &amp; Rate Limit</div><div class="stat-val">{$safeCorsBadge} · {$safeRlBadge}</div></div>
      <div class="stat-card"><div class="stat-lbl">PoW &amp; Codecs</div><div class="stat-val">{$safePowBadge} · {$safeGdBadge}</div></div>
      <div class="stat-card"><div class="stat-lbl">Transport &amp; HTTPS</div><div class="stat-val">{$safeHttpsBadge} · Strategy: {$poolStrategy}</div></div>
    </div>

    <div id="search-empty">No matching sections, settings, or endpoints found for your query. Clear the search box to show everything.</div>

    <!-- 1. Architecture Flow -->
    <section class="section-card searchable" data-keywords="about architecture flow sentinel pow conduit sse streamtransformer tokenpool dynamic">
      <h2>1. How Laravel ChatGPT Mirror Works <span class="tag tag-green">Architecture</span></h2>
      <p class="section-sub">Understand how client requests, browser traffic, tokens, and caching flow smoothly inside any PHP/Laravel deployment.</p>
      <div class="grid-3">
        <div class="feature-box">
          <h3>Request Preparation Layer</h3>
          <p><code>PrepareMirrorRuntime</code> runs dynamically on every Mirror route: applies DB runtime overrides, restores Apache <code>REDIRECT_HTTP_AUTHORIZATION</code> Bearer headers, scopes trusted reverse-proxy headers per request without leaking static state, applies dynamic CORS + preflight <code>204</code>, enforces optional rate limits, and disables <code>zlib.output_compression</code> for live SSE.</p>
        </div>
        <div class="feature-box">
          <h3>Upstream Protocol Engine</h3>
          <p><code>TokenPoolManager</code> picks healthy ChatGPT accounts using distributed atomic slots. <code>ChatGptClient</code> runs Sentinel PoW (<code>SHA3-512</code>) and Conduit follow-ups, while <code>StreamTransformer</code> / <code>ModernStreamTransformer</code> convert ChatGPT v1 JSON patches into clean OpenAI SSE deltas without exposing hidden reasoning tokens.</p>
        </div>
        <div class="feature-box">
          <h3>State, Continuity &amp; Resilience</h3>
          <p><code>ConversationService</code> tracks upstream node IDs, instruction hashes, and full transcript hashes (`openai_transcript_hash`) to auto-infer conversations, detect edits, snapshot backup branches, and roll back failures cleanly. <code>MirrorCache</code> uses Redis automatically when active and falls back silently when Redis is off or unreachable.</p>
        </div>
      </div>
    </section>

    <!-- 2. Where Is Everything? -->
    <section class="section-card searchable" data-keywords="where find routes urls files admin playground swagger openapi health config deploy migrations">
      <h2>2. Where Is Everything? (Pages, Endpoints &amp; Project Files) <span class="tag tag-blue">Quick Locator</span></h2>
      <p class="section-sub">All URLs below adapt automatically to your live domain, subpath (<code>X-Forwarded-Prefix</code>), and custom route prefix settings.</p>

      <h3 style="font-size:14px;margin:14px 0 6px 0">Live Web Pages &amp; API Entrypoints</h3>
      <table>
        <thead><tr><th>Feature / Portal</th><th>Active Route / URL (Live Server)</th><th>Purpose &amp; Notes</th></tr></thead>
        <tbody>
          <tr class="searchable-row"><td><strong>ChatGPT Web UI Proxy</strong></td><td><a href="{$safeHomeUrl}"><code>{$safeBaseUrl}/</code></a> <button class="inline-copy" data-copy="{$safeHomeUrl}">Copy</button></td><td>Full proxied ChatGPT interface with URL rewriting &amp; early-injected browser patch (no floating settings overlay). Aliases: <code>/c/{id}</code>.</td></tr>
          <tr class="searchable-row"><td><strong>Admin &amp; Pool Dashboard</strong></td><td><a href="{$safeAdminUrl}"><code>{$safeAdminPath}</code></a> <button class="inline-copy" data-copy="{$safeAdminUrl}">Copy</button></td><td>Upload/paste <code>token.txt</code>, manage pool accounts, test Redis, and toggle server/proxy/CORS/rate-limit settings live. Aliases: <code>/admin</code>, <code>/settings/mirror</code>.</td></tr>
          <tr class="searchable-row"><td><strong>Playground SPA</strong></td><td><a href="{$safePlaygroundUrl}"><code>{$safePlaygroundPath}</code></a> <button class="inline-copy" data-copy="{$safePlaygroundUrl}">Copy</button></td><td>Interactive UI for Chat Completions, Responses API, custom instructions, hotkeys, and Binary Codecs lab. Alias: <code>/playground</code>.</td></tr>
          <tr class="searchable-row"><td><strong>Swagger UI API Docs</strong></td><td><a href="{$safeDocsUrl}"><code>{$safeApiDocsPath}</code></a> <button class="inline-copy" data-copy="{$safeDocsUrl}">Copy</button></td><td>Local offline-ready Swagger UI rendering the live OpenAPI 3.1.0 schema. Schema: <a href="{$safeOpenApiUrl}"><code>{$safeOpenApiPath}</code></a> (append <code>?format=yaml</code> for YAML).</td></tr>
          <tr class="searchable-row"><td><strong>Help &amp; About Portal</strong></td><td><a href="{$safeHelpUrl}"><code>{$safeHelpPath}</code></a> <button class="inline-copy" data-copy="{$safeHelpUrl}">Copy</button></td><td>This complete documentation portal. Friendly aliases: <code>/help</code>, <code>/about</code>, <code>/mirror/about</code>.</td></tr>
          <tr class="searchable-row"><td><strong>OpenAI Gateway Base URL</strong></td><td><code>{$safeOpenAiBase}</code> <button class="inline-copy" data-copy="{$safeOpenAiBase}">Copy</button></td><td>Point OpenAI SDKs here: <code>{$safeOpenAiPath}/models</code>, <code>{$safeOpenAiPath}/chat/completions</code>, <code>{$safeOpenAiPath}/responses</code>, <code>{$safeOpenAiPath}/capabilities</code>.</td></tr>
          <tr class="searchable-row"><td><strong>Health &amp; Diagnostics</strong></td><td><code>{$safeHealthPath}</code> &middot; <code>{$safeDiagnosticsPath}</code></td><td>Public pool/runtime readiness JSON (<code>/api/health</code>) and detailed diagnostics + recent failures (<code>/api/diagnostics</code>, auth protected).</td></tr>
          <tr class="searchable-row"><td><strong>Runtime Settings API</strong></td><td><code>{$safeRuntimePath}</code></td><td><code>GET</code> / <code>PUT</code> / <code>DELETE</code> live settings overrides + <code>POST {$safeRuntimePath}/test-redis</code>.</td></tr>
        </tbody>
      </table>

      <h3 style="font-size:14px;margin:18px 0 6px 0">Where Source &amp; Configuration Files Live in the Package / App</h3>
      <table>
        <thead><tr><th>Path in Project / Package</th><th>What It Controls</th></tr></thead>
        <tbody>
          <tr class="searchable-row"><td><code>config/mirror.php</code> &amp; <code>.env</code></td><td>Default package configuration (publish with <code>php artisan vendor:publish --tag=mirror-config</code>). Holds upstream URL, API keys, pool, routes, HTTP timeouts, Redis, server, CORS, and rate-limit baselines.</td></tr>
          <tr class="searchable-row"><td><code>routes/api.php</code> &amp; <code>routes/web.php</code></td><td>Dynamic prefix resolution for OpenAI, Backend proxy, Mirror APIs, Swagger Docs, Admin, Playground, Help &amp; About, and safe catch-all ChatGPT web proxy exclusions.</td></tr>
          <tr class="searchable-row"><td><code>src/Services/DynamicConfigService.php</code></td><td>Reads/writes <code>runtime.overrides</code> from <code>mirror_settings</code> table and applies live config overrides per request without editing <code>.env</code>.</td></tr>
          <tr class="searchable-row"><td><code>src/Services/TokenInputParser.php</code></td><td>Universal credential extractor supporting full <code>token.txt</code> (<code>/api/auth/session</code> JSON), raw cookies, Netscape cookies, cURL commands, and bulk arrays.</td></tr>
          <tr class="searchable-row"><td><code>src/Controllers/OpenAiApiController.php</code></td><td>OpenAI <code>/chat/completions</code> &amp; <code>/responses</code>, tool streaming, transcript auto-inference, history auto-rebase, backup branches &amp; failure rollback.</td></tr>
          <tr class="searchable-row"><td><code>resources/deploy/apache|nginx|caddy/</code></td><td>Production server snippets (<code>.htaccess.mirror</code>, <code>webuzo-vhost.conf</code>, <code>mirror.conf</code>, <code>Caddyfile.mirror</code>); publish via <code>--tag=mirror-deploy</code>.</td></tr>
          <tr class="searchable-row"><td><code>resources/web/dist/</code> &amp; <code>swagger-ui/</code></td><td>Prebuilt Vite React Playground bundle and local static Swagger UI assets (zero external CDN required).</td></tr>
          <tr class="searchable-row"><td><code>database/migrations/</code></td><td>Creates <code>mirror_tokens</code>, <code>mirror_conversations</code>, <code>mirror_messages</code>, <code>mirror_files</code>, <code>mirror_assets</code>, and <code>mirror_settings</code> tables cleanly.</td></tr>
        </tbody>
      </table>
    </section>

    <!-- 3. All Features Matrix -->
    <section class="section-card searchable" data-keywords="features openai streaming tools responses models gpt project gizmo proxy codecs loaf pngspeak gptgif gif89a onion challenge conversations search branch export assets">
      <h2>3. Complete Features Inventory <span class="tag tag-green">Full Parity</span></h2>
      <p class="section-sub">Every major capability built into Laravel ChatGPT Mirror across APIs, Proxy UI, Codecs, Concurrency, and Server Portability.</p>
      <div class="grid-2">
        <div class="feature-box searchable-row">
          <h3>OpenAI Chat Completions Gateway <span class="tag tag-blue">{$safeOpenAiPath}/chat/completions</span></h3>
          <ul>
            <li>Sync JSON responses &amp; low-latency unbuffered SSE streaming (<code>stream: true</code>).</li>
            <li>Suppresses hidden internal analysis channels cleanly so end-users see final assistant answers.</li>
            <li>Supports <code>gpt-4o</code>, <code>gpt-5-*</code>, <code>auto</code>, <code>o1/o3/o4</code> reasoning slugs, plus Custom GPT / Project routing via <code>g-...</code> / <code>g-p-...</code> models or <code>metadata.mirror_model</code>.</li>
            <li>Supports <code>metadata.private</code> (temporary chat), <code>metadata.conversation_id</code>, <code>cm</code> continuation, and rejects <code>-wm</code> Work Mode slugs cleanly (<code>400 unsupported_parameter</code>).</li>
          </ul>
        </div>
        <div class="feature-box searchable-row">
          <h3>Function / Tool Calling (Sync &amp; Streaming) <span class="tag tag-blue">tools &amp; role=tool</span></h3>
          <ul>
            <li>Accepts up to 128 function tool definitions validated against OpenAI identifier rules (<code>/^[a-zA-Z0-9_-]{1,64}$/</code>).</li>
            <li>Supports <code>tool_choice</code> (<code>auto</code>, <code>none</code>, <code>required</code>, or forced function object).</li>
            <li>Works in BOTH sync responses (<code>message.tool_calls</code>) and SSE streams (<code>delta.tool_calls</code> + <code>finish_reason: "tool_calls"</code>).</li>
            <li>Processes multi-turn follow-ups carrying assistant <code>tool_calls</code> and <code>role: "tool"</code> (<code>tool_call_id</code>) messages seamlessly.</li>
          </ul>
        </div>
        <div class="feature-box searchable-row">
          <h3>Smart History Inference, Rebase &amp; Rollback <span class="tag tag-blue">Transcript Hash</span></h3>
          <ul>
            <li>Full-history requests without <code>conversation_id</code> automatically match stored conversations using canonical <code>openai_transcript_hash</code>.</li>
            <li>Protects assistant integrity: editing or deleting prior assistant responses returns HTTP <code>400</code>.</li>
            <li>Editing earlier user turns or system instructions creates a <code>Before edit: ...</code> backup branch, rewinds upstream <code>current_node_id</code> cleanly, and automatically rolls back local history if upstream fails mid-turn.</li>
          </ul>
        </div>
        <div class="feature-box searchable-row">
          <h3>OpenAI Responses API &amp; Discovery <span class="tag tag-blue">{$safeOpenAiPath}/responses</span></h3>
          <ul>
            <li>Full sync &amp; SSE streaming lifecycle events (<code>response.created</code>, <code>response.in_progress</code>, <code>response.output_text.delta</code>, <code>response.completed</code>).</li>
            <li>Supports string or structured array <code>input</code>, <code>instructions</code>, attachments, and metadata.</li>
            <li>Normalized OpenAI model listing on <code>{$safeOpenAiPath}/models</code> plus rich UI metadata on <code>/api/models</code> and <code>/api/gpts</code>.</li>
          </ul>
        </div>
        <div class="feature-box searchable-row">
          <h3>Transparent ChatGPT Web Proxy <span class="tag tag-green">Browser Proxy</span></h3>
          <ul>
            <li>Proxies full ChatGPT frontend on <code>{$safeBaseUrl}/</code> cleanly with zero intrusive floating settings panel overlay.</li>
            <li>Synthesizes safe NextAuth session JSON on <code>/api/auth/session</code> without ever leaking raw upstream session cookies/JWTs.</li>
            <li>Strips strict CSP frames, rewrites origin URLs dynamically across reverse proxies &amp; sub-paths, and scrubs SSR inline access tokens.</li>
          </ul>
        </div>
        <div class="feature-box searchable-row">
          <h3>Multi-Account Pool &amp; Smart <code>token.txt</code> Parser <span class="tag tag-green">Session Pool</span></h3>
          <ul>
            <li>Paste whole <code>token.txt</code> (<code>chatgpt.com/api/auth/session</code> JSON), browser cookies JSON, Netscape export, <code>Cookie:</code> headers, cURL snippets, or raw session tokens—auto-extracts everything automatically.</li>
            <li>Fallback preservation: if upstream Cloudflare/WAF transiently blocks refresh while a valid embedded JWT <code>accessToken</code> exists in <code>token.txt</code>, saves safely without losing access.</li>
            <li><code>least_busy</code>, <code>round_robin</code>, and <code>random</code> rotation with atomic concurrency caps, <code>429</code> cooldowns, and distributed refresh locks.</li>
          </ul>
        </div>
        <div class="feature-box searchable-row">
          <h3>Conversations, Files &amp; Signed Asset Links <span class="tag tag-slate">Native Mirror API</span></h3>
          <ul>
            <li>Full <code>/api/conversations</code> CRUD, local + upstream search (<code>/search?q=</code>), branch creation/listing, JSON &amp; Markdown export, and generation stop.</li>
            <li><code>/api/files</code> upload bridge (images, audio, documents) for ChatGPT multimodal prompts and API requests.</li>
            <li>HMAC-signed 7-day <code>/api/asset-content</code> links with automatic token-rotation link invalidation and SSRF host protection.</li>
          </ul>
        </div>
        <div class="feature-box searchable-row">
          <h3>Binary Codecs &amp; Decoder Challenges <span class="tag tag-slate">/api/convert/*</span></h3>
          <ul>
            <li>Includes <strong>LoaF</strong> (<code>SHA-256</code> + tar.gz), <strong>PngSpeak</strong> (RGBA PNG + iTXt metadata), <strong>Gif89a</strong>, <strong>GptGif</strong>, and <strong>GptGif v4</strong> (self-calibrating palette &amp; font seeds).</li>
            <li>Supports <strong>Onion</strong> multi-layer chaining (1–6 codecs), <code>roundtrip</code>, <code>gauntlet</code>, <code>gpt-prompt</code> transport wrapper, and Mystery / Fortune generators.</li>
            <li>Built-in <code>/api/decoder-challenges</code> generator, exact hex verifier, and downloadable <code>.tar.gz</code> challenge kits.</li>
          </ul>
        </div>
      </div>
    </section>

    <!-- 4. Step-by-Step Setup & Token Guide -->
    <section class="section-card searchable" data-keywords="setup install token.txt turnstile session add account import auth api key">
      <h2>4. Step-by-Step Setup &amp; Token Configuration Guide <span class="tag tag-green">Getting Started</span></h2>
      <p class="section-sub">Follow these steps to take a fresh Laravel installation (or Webuzo/VPS server) from zero to production.</p>
      <div class="grid-2">
        <div class="feature-box">
          <h3>Step A — Bootstrap Package &amp; Security Key</h3>
          <ol style="margin:6px 0 0 0;padding-left:18px;font-size:13px;color:var(--muted)">
            <li>Install package &amp; run migrations: <code>php artisan mirror:install</code> (or <code>php artisan migrate</code>).</li>
            <li>Set a strong <code>MIRROR_API_KEY</code> in <code>.env</code> (comma-separate multiple keys if issuing per-client keys). Keep empty only for private localhost use.</li>
            <li>Never commit <code>token.txt</code>, <code>.env</code>, or SQLite files to Git.</li>
          </ol>
        </div>
        <div class="feature-box">
          <h3>Step B — Add Accounts from <code>token.txt</code></h3>
          <ol style="margin:6px 0 0 0;padding-left:18px;font-size:13px;color:var(--muted)">
            <li>Sign in at ChatGPT and copy the full JSON from <code>/api/auth/session</code> (or export browser cookies / paste entire <code>token.txt</code>).</li>
            <li>Open <a href="{$safeAdminUrl}"><strong>Admin Dashboard</strong></a> &rarr; paste raw content or upload <code>token.txt</code> &rarr; click <em>Extract, Verify &amp; Save Account</em>.</li>
            <li>Or automate via CLI: <code>php artisan mirror:token:import-token token.txt</code> (or <code>POST /api/session</code> with <code>{"sessionToken":"..."}</code>).</li>
          </ol>
        </div>
      </div>
      <div class="callout info">
        <strong>How Authentication Works Across Routes:</strong> OpenAI endpoints (<code>{$safeOpenAiPath}/*</code>) expect <code>Authorization: Bearer &lt;MIRROR_API_KEY&gt;</code>. Browser Admin/Playground/API UI routes accept either Bearer auth <em>or</em> the signed <code>HttpOnly</code> control cookie automatically issued when opening Admin or Playground in a trusted same-origin browser. Spoofed <code>Referer</code> headers are never trusted.
      </div>
    </section>

    <!-- 5. Exhaustive Settings & .env Reference -->
    <section class="section-card searchable" data-keywords="settings config env MIRROR_API_KEY MIRROR_REDIS MIRROR_CORS MIRROR_RATE_LIMIT MIRROR_READ_TIMEOUT MIRROR_POOL MIRROR_PREFIX">
      <h2>5. Every Setting &amp; <code>.env</code> Key Explained <span class="tag tag-amber">Complete Config Map</span></h2>
      <p class="section-sub">Settings marked <span class="tag tag-green">Live UI/API</span> can also be changed instantaneously in <a href="{$safeAdminUrl}">Admin Dashboard</a> or via <code>PUT {$safeRuntimePath}</code> without restarting PHP or editing <code>.env</code>.</p>

      <table>
        <thead><tr><th><code>.env</code> Key (<code>config/mirror.php</code> path)</th><th>Default &amp; Mode</th><th>What It Configures &amp; Recommended Value</th></tr></thead>
        <tbody>
          <tr class="searchable-row"><td><code>MIRROR_API_KEY</code><br><small>(<code>mirror.api_key</code>)</small></td><td><code>null</code><br><span class="tag tag-slate">.env / Config</span></td><td>Comma-separated Bearer key(s) protecting <code>{$safeOpenAiPath}/*</code> and control endpoints. Leave empty only on private localhost; always set in production.</td></tr>
          <tr class="searchable-row"><td><code>MIRROR_UPSTREAM_URL</code><br><small>(<code>mirror.upstream</code>)</small></td><td><code>https://chatgpt.com</code><br><span class="tag tag-slate">.env / Config</span></td><td>Upstream ChatGPT origin. Keep default unless routing through a verified internal mirror/proxy endpoint.</td></tr>
          <tr class="searchable-row"><td><code>MIRROR_VERIFY_SSL</code> / <code>MIRROR_HTTP_PROXY</code><br><small>(<code>mirror.http.*</code>)</small></td><td><code>true</code> / <code>null</code><br><span class="tag tag-slate">.env / Config</span></td><td>Enforces TLS verification and optional outbound HTTP(S) proxy URL. Never disable SSL in production.</td></tr>
          <tr class="searchable-row"><td><code>MIRROR_REQUEST_TIMEOUT</code> / <code>MIRROR_READ_TIMEOUT</code><br><small>(<code>mirror.http.timeout</code>, <code>http.read_timeout</code>)</small></td><td><code>120</code>s / <code>{$readTimeout}</code>s<br><span class="tag tag-green">Live UI/API</span></td><td><code>MIRROR_REQUEST_TIMEOUT</code> limits standard HTTP calls; <code>MIRROR_READ_TIMEOUT</code> controls upstream SSE idle-stall timeout (<code>5–3600</code>s). Increase to <code>180–300</code>s for long thinking models.</td></tr>
          <tr class="searchable-row"><td><code>MIRROR_STREAM_MAX_EXECUTION_TIME</code><br><small>(<code>mirror.server.stream_max_execution_time</code>)</small></td><td><code>{$streamTimeout}</code>s<br><span class="tag tag-green">Live UI/API</span></td><td>PHP <code>set_time_limit()</code> applied to SSE streams (<code>0</code> = unlimited). Also match Apache <code>Timeout</code> / Nginx <code>fastcgi_read_timeout</code>.</td></tr>
          <tr class="searchable-row"><td><code>MIRROR_DISABLE_OUTPUT_COMPRESSION</code><br><small>(<code>mirror.server.disable_output_compression</code>)</small></td><td><code>true</code><br><span class="tag tag-green">Live UI/API</span></td><td>Disables PHP <code>zlib.output_compression</code> during SSE so tokens flush token-by-token instead of buffering until completion.</td></tr>
          <tr class="searchable-row"><td><code>MIRROR_POOL_STRATEGY</code><br><small>(<code>mirror.pool.strategy</code>)</small></td><td><code>{$poolStrategy}</code><br><span class="tag tag-green">Live UI/API</span></td><td>Account selection across multiple ChatGPT tokens: <code>least_busy</code> (recommended), <code>round_robin</code>, or <code>random</code>.</td></tr>
          <tr class="searchable-row"><td><code>MIRROR_MAX_CONCURRENT_PER_TOKEN</code><br><small>(<code>mirror.pool.max_concurrent_per_token</code>)</small></td><td><code>5</code><br><span class="tag tag-green">Live UI/API</span></td><td>Atomic active-slot ceiling per account (<code>1–100</code>). Prevents triggering ChatGPT per-account rate bans under load.</td></tr>
          <tr class="searchable-row"><td><code>MIRROR_COOLDOWN_SECONDS</code><br><small>(<code>mirror.pool.cooldown_seconds</code>)</small></td><td><code>60</code>s<br><span class="tag tag-green">Live UI/API</span></td><td>Duration (<code>1–3600</code>s) an account rests automatically after receiving upstream HTTP <code>429</code> while other accounts take over.</td></tr>
          <tr class="searchable-row"><td><code>MIRROR_REFRESH_BUFFER_SECONDS</code><br><small>(<code>mirror.pool.refresh_buffer_seconds</code>)</small></td><td><code>300</code>s<br><span class="tag tag-slate">.env / Config</span></td><td>Proactively refreshes JWT <code>accessToken</code> before expiry inside distributed atomic locks so concurrent requests do not stampede.</td></tr>
          <tr class="searchable-row"><td><code>MIRROR_SESSION_TOKENS</code><br><small>(<code>mirror.pool.static_tokens</code>)</small></td><td><code>""</code><br><span class="tag tag-slate">.env / Config</span></td><td>Optional comma-separated bootstrap session tokens synced automatically if DB pool is empty. Prefer Admin/CLI import for clean rotation.</td></tr>
          <tr class="searchable-row"><td><code>MIRROR_REDIS_ENABLED</code> / <code>MIRROR_REDIS_CLIENT</code><br><small>(<code>mirror.redis.enabled</code>, <code>redis.client</code>)</small></td><td><code>auto</code> / <code>auto</code><br><span class="tag tag-green">Live UI/API</span></td><td><code>auto</code> enables Redis when host/URL &amp; driver (<code>phpredis</code> or <code>predis</code>) respond, or runs seamlessly on DB/file/array fallback. Toggle <code>enabled</code> or <code>disabled</code> live anytime.</td></tr>
          <tr class="searchable-row"><td><code>MIRROR_REDIS_HOST</code> / <code>PORT</code> / <code>PASSWORD</code> / <code>DB</code> / <code>URL</code><br><small>(<code>mirror.redis.*</code>)</small></td><td><code>127.0.0.1:6379</code><br><span class="tag tag-green">Live UI/API</span></td><td>Supports TCP hosts, Unix sockets (e.g. <code>/var/run/redis/redis.sock</code>), DSN URLs (<code>redis://...</code>), AUTH passwords, DB index (<code>0–15</code>), and persistent connections.</td></tr>
          <tr class="searchable-row"><td><code>MIRROR_CACHE_STORE</code> / <code>MODELS_CACHE_TTL</code> / <code>ASSETS_IN_REDIS</code><br><small>(<code>mirror.cache.*</code>)</small></td><td>Auto / <code>60</code>s / <code>true</code><br><span class="tag tag-green">Live UI/API</span></td><td>Override Laravel cache store, model list cache TTL (<code>0–86400</code>s), and storing generated media/decoder artifacts in Redis up to <code>MIRROR_ASSETS_REDIS_MAX_BYTES</code> (1MB).</td></tr>
          <tr class="searchable-row"><td><code>MIRROR_SERVER_PLATFORM</code><br><small>(<code>mirror.server.platform</code>)</small></td><td><code>auto</code><br><span class="tag tag-green">Live UI/API</span></td><td><code>auto</code>, <code>apache</code>, <code>webuzo</code>, <code>nginx</code>, <code>litespeed</code>, <code>openlitespeed</code>, <code>caddy</code>, <code>frankenphp</code>, or <code>octane</code>. Adapts server tips &amp; diagnostics dynamically.</td></tr>
          <tr class="searchable-row"><td><code>MIRROR_TRUST_PROXIES</code> / <code>TRUSTED_PROXIES</code> / <code>FORCE_HTTPS</code><br><small>(<code>mirror.server.*</code>)</small></td><td><code>true</code> / <code>*</code> / <code>auto</code><br><span class="tag tag-green">Live UI/API</span></td><td>Honors <code>X-Forwarded-*</code>, <code>Forwarded</code>, and Cloudflare <code>CF-Visitor</code> headers cleanly per request. Use explicit proxy CIDRs or <code>*</code> (safely resolved to local/proxy remote address).</td></tr>
          <tr class="searchable-row"><td><code>MIRROR_CORS_ENABLED</code> / <code>MIRROR_CORS_ALLOWED_ORIGINS</code><br><small>(<code>mirror.cors.*</code>)</small></td><td><code>true</code> / <code>{$safeCorsOrigins}</code><br><span class="tag tag-green">Live UI/API</span></td><td>Handles <code>OPTIONS</code> <code>204</code> preflights and exposes <code>X-Request-Id</code>, <code>X-Mirror-Conversation-Id</code> &amp; rate-limit headers. Comma-separate origins for strict browser allowlists.</td></tr>
          <tr class="searchable-row"><td><code>MIRROR_RATE_LIMIT_ENABLED</code> / <code>MAX_ATTEMPTS</code> / <code>DECAY_SECONDS</code><br><small>(<code>mirror.rate_limit.*</code>)</small></td><td><code>false</code> / <code>{$rlMax}</code> / <code>{$rlDecay}</code>s<br><span class="tag tag-green">Live UI/API</span></td><td>Optional fixed-window limiter using <code>MirrorCache</code> (Redis atomic or fallback store). When enabled, emits OpenAI-standard <code>429 rate_limit_exceeded</code> + <code>Retry-After</code>.</td></tr>
          <tr class="searchable-row"><td><code>MIRROR_*_PREFIX</code> Routes<br><small>(<code>openai</code>, <code>admin</code>, <code>playground</code>, <code>help</code>, <code>docs</code>, <code>assets</code>, <code>backend</code>, <code>browser</code>)</small></td><td><code>v1</code>, <code>mirror/*</code><br><span class="tag tag-slate">.env / Config</span></td><td>Move any endpoint group anywhere (e.g. <code>MIRROR_OPENAI_PREFIX=llm/v2</code>, <code>MIRROR_HELP_PREFIX=docs/guide</code>). Remember to run <code>php artisan route:clear</code> if routes are cached.</td></tr>
        </tbody>
      </table>

      <div class="code-wrap" style="margin-top:14px">
        <button class="copy-btn" data-copy-target="env-template-pre">Copy .env Template</button>
        <pre id="env-template-pre">{$envSample}</pre>
      </div>
    </section>

    <!-- 6. Live Runtime Overrides vs .env -->
    <section class="section-card searchable" data-keywords="runtime overrides priority reset database settings api">
      <h2>6. Live Database Overrides vs <code>.env</code> Priority <span class="tag tag-blue">Zero Hardcoding</span></h2>
      <p class="section-sub">How Laravel ChatGPT Mirror stays completely dynamic without forcing SSH/file edits for routine changes.</p>
      <div class="grid-3">
        <div class="feature-box">
          <h3>Layer 1: <code>.env</code> &amp; <code>config/mirror.php</code></h3>
          <p>Acts as your baseline deployment configuration whenever Laravel boots. Route prefixes and <code>MIRROR_API_KEY</code> live here.</p>
        </div>
        <div class="feature-box">
          <h3>Layer 2: Live DB Overrides</h3>
          <p>Saving changes in <a href="{$safeAdminUrl}">Admin</a> or <code>PUT {$safeRuntimePath}</code> persists JSON under <code>runtime.overrides</code> and updates live Redis, Proxy, Pool, Timeout, CORS, and Rate Limit behavior immediately.</p>
        </div>
        <div class="feature-box">
          <h3>Instant Rollback</h3>
          <p>Click <em>Reset Overrides to .env Defaults</em> in Admin (or call <code>DELETE {$safeRuntimePath}</code>) anytime to clear DB overrides and revert cleanly to <code>.env</code>.</p>
        </div>
      </div>
    </section>

    <!-- 7. Universal Server & Redis Deployment -->
    <section class="section-card searchable" data-keywords="deploy webuzo apache nginx litespeed caddy frankenphp octane redis phpredis predis websocket htaccess vhost">
      <h2>7. Production Server Deployment (Webuzo, Nginx, Caddy, Octane &amp; Redis) <span class="tag tag-green">Server Agnostic</span></h2>
      <p class="section-sub">Generate ready-to-paste server configs dynamically tailored for your domain and PHP version using one command.</p>
      <div class="grid-2">
        <div class="feature-box">
          <h3>Generate Server Config Snippets</h3>
          <p>Run inside your Laravel project root (works on Apache, Webuzo, Nginx, LiteSpeed, Caddy, Octane):</p>
          <div class="code-wrap">
            <button class="copy-btn" data-copy-target="cli-server-pre">Copy</button>
            <pre id="cli-server-pre"># Write universal Apache/LiteSpeed public/.htaccess safely:
php artisan mirror:server apache --write

# Print Webuzo Apache Extra VirtualHost Include:
php artisan mirror:server webuzo --vhost --domain={$safeCliDomain} --root=/home/USER/public_html/public

# Print Nginx, Caddy, or Octane configs:
php artisan mirror:server nginx --vhost --domain={$safeCliDomain} --php-version=8.3
php artisan mirror:server caddy --vhost --domain={$safeCliDomain}
php artisan mirror:server octane --vhost</pre>
          </div>
        </div>
        <div class="feature-box">
          <h3>Webuzo / VPS Redis Enablement Checklist</h3>
          <ul>
            <li><strong>No Redis installed?</strong> Leave <code>MIRROR_REDIS_ENABLED=auto</code> or set <code>disabled</code> &mdash; every feature (cache, locks, rate limits, decoder challenges) automatically runs on your DB/file fallback cache without error.</li>
            <li><strong>Redis installed?</strong> Enable <code>phpredis</code> in Webuzo PHP extensions OR install <code>composer require predis/predis</code> (works without root PHP extension compile!).</li>
            <li>Open <a href="{$safeAdminUrl}">Admin Dashboard</a> &rarr; set Redis Mode to <code>Enabled</code> (or <code>auto</code>), verify with <strong>Test Redis Connection</strong>, and click <strong>Save &amp; Apply Live Settings</strong>.</li>
            <li>For heavy streaming + WebSocket sidecars, uncomment the optional WebSocket proxy block inside <code>resources/deploy/*</code>.</li>
          </ul>
        </div>
      </div>
    </section>

    <!-- 8. SDK, Tools & History API Examples -->
    <section class="section-card searchable" data-keywords="sdk curl python javascript openai tools streaming history continuation custom gpt g- metadata">
      <h2>8. Client SDK, Tools &amp; Continuation Examples <span class="tag tag-blue">Copy &amp; Run</span></h2>
      <p class="section-sub">Point any OpenAI-compatible client directly to <code>{$safeOpenAiBase}</code>.</p>
      <div class="grid-2">
        <div class="feature-box">
          <h3>cURL Streaming Chat Completions</h3>
          <div class="code-wrap">
            <button class="copy-btn" data-copy-target="sdk-curl">Copy</button>
            <pre id="sdk-curl">{$curlSample}</pre>
          </div>
        </div>
        <div class="feature-box">
          <h3>Python OpenAI SDK + Custom GPT &amp; Private Mode</h3>
          <div class="code-wrap">
            <button class="copy-btn" data-copy-target="sdk-python">Copy</button>
            <pre id="sdk-python">{$pythonSample}</pre>
          </div>
        </div>
      </div>
      <div class="feature-box" style="margin-top:14px">
        <h3>Node.js / TypeScript SDK Streaming + Client Function Tools</h3>
        <div class="code-wrap">
          <button class="copy-btn" data-copy-target="sdk-js">Copy</button>
          <pre id="sdk-js">{$jsSample}</pre>
        </div>
      </div>
      <div class="callout">
        <strong>Multi-turn Conversation Continuity Tips:</strong>
        (1) Pass <code>"metadata": {"conversation_id": "&lt;public_id&gt;"}</code> with just the next prompt for single-turn <code>cm</code> continuation; OR
        (2) Send the full OpenAI <code>messages</code> array&mdash;Mirror hashes the prior transcript automatically to continue the exact ChatGPT node. If you edit an earlier <code>user</code> turn, Mirror snapshots a branch and rewinds cleanly; editing an earlier <code>assistant</code> turn is safely rejected with HTTP <code>400</code>.
      </div>
    </section>

    <!-- 9. Artisan CLI Commands -->
    <section class="section-card searchable" data-keywords="artisan cli commands mirror:install mirror:health mirror:token mirror:server">
      <h2>9. Artisan CLI Quick Reference <span class="tag tag-slate">Terminal Tools</span></h2>
      <table>
        <thead><tr><th>Command</th><th>Description &amp; Useful Options</th></tr></thead>
        <tbody>
          <tr class="searchable-row"><td><code>php artisan mirror:install</code></td><td>Publishes configuration/deployment assets cleanly and bootstraps Mirror database migrations.</td></tr>
          <tr class="searchable-row"><td><code>php artisan mirror:health</code></td><td>Audits live reachability to <code>chatgpt.com</code>, PHP <code>sha3-512</code> PoW, GD extension, Redis / fallback driver, DB tables, and active pool accounts.</td></tr>
          <tr class="searchable-row"><td><code>php artisan mirror:token:import-token &lt;path&gt;</code></td><td>Imports accounts directly from a saved <code>token.txt</code>, cookies JSON, Netscape file, or multiline cookie dump.</td></tr>
          <tr class="searchable-row"><td><code>php artisan mirror:token:add</code></td><td>Interactive/argument token extraction &amp; upstream session verification into the pool.</td></tr>
          <tr class="searchable-row"><td><code>php artisan mirror:token:list</code></td><td>Displays table of all accounts, plans, statuses, in-flight counts, lifetime served counts, and cooldowns.</td></tr>
          <tr class="searchable-row"><td><code>php artisan mirror:token:refresh</code></td><td>Acquires per-token distributed refresh locks and refreshes access tokens for active accounts.</td></tr>
          <tr class="searchable-row"><td><code>php artisan mirror:server &lt;target&gt;</code></td><td>Generates/writes <code>.htaccess</code> (<code>--write</code>) and VirtualHost templates (<code>--vhost</code>) for <code>apache</code>, <code>webuzo</code>, <code>nginx</code>, <code>litespeed</code>, <code>caddy</code>, or <code>octane</code>.</td></tr>
        </tbody>
      </table>
    </section>

    <!-- 10. Troubleshooting Matrix -->
    <section class="section-card searchable" data-keywords="troubleshooting errors 401 403 429 400 buffering sse timeout swagger redis turnstile work mode -wm">
      <h2>10. Troubleshooting &amp; Error Diagnostics <span class="tag tag-amber">FAQ</span></h2>
      <table>
        <thead><tr><th>Symptom / Error</th><th>Cause</th><th>Exact Resolution</th></tr></thead>
        <tbody>
          <tr class="searchable-row"><td><strong><code>401 Unauthorized</code> even with valid Bearer key</strong></td><td>Apache / CGI / FastCGI stripped the <code>Authorization</code> header before PHP received it.</td><td>Run <code>php artisan mirror:server apache --write</code> to keep <code>RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]</code>, and in Webuzo/Apache VirtualHost include <code>CGIPassAuth On</code>. <code>PrepareMirrorRuntime</code> reads both <code>HTTP_AUTHORIZATION</code> &amp; <code>REDIRECT_HTTP_AUTHORIZATION</code> automatically.</td></tr>
          <tr class="searchable-row"><td><strong>Streaming chunks arrive all at once or time out at 60s</strong></td><td>Reverse proxy buffering, gzip/brotli compression, or low FastCGI/Apache/idle timeout.</td><td>Ensure <code>MIRROR_DISABLE_OUTPUT_COMPRESSION=true</code>, increase <code>Upstream SSE Idle Read Timeout</code> &amp; <code>Stream Max Execution</code> in Admin, set Apache <code>Timeout 300</code> / <code>no-gzip</code> or Nginx <code>fastcgi_buffering off</code> via <code>mirror:server</code>.</td></tr>
          <tr class="searchable-row"><td><strong><code>token.txt</code> rejected or upstream returns 401/403</strong></td><td>ChatGPT session expired, Cloudflare WAF blocked refresh, or Turnstile challenge token is required.</td><td>Grab a fresh <code>/api/auth/session</code> JSON in your browser and paste into Admin. If WAF transiently blocks refresh while a valid <code>accessToken</code> exists inside <code>token.txt</code>, Mirror saves via session bundle fallback cleanly.</td></tr>
          <tr class="searchable-row"><td><strong><code>Test Redis Connection</code> says neither phpredis nor predis installed</strong></td><td>PHP lacks the <code>redis</code> extension and Composer does not yet include <code>predis/predis</code>.</td><td>Enable <code>phpredis</code> in Webuzo/panel PHP extensions or run <code>composer require predis/predis</code>, OR leave Redis in <code>auto</code>/<code>disabled</code> mode to use fallback cache safely.</td></tr>
          <tr class="searchable-row"><td><strong><code>400</code>: &ldquo;Assistant messages are read-only...&rdquo;</strong></td><td>Client modified or deleted an earlier assistant reply inside an existing conversation thread.</td><td>Only edit earlier <code>user</code> prompts (which triggers clean auto-rebase) or start a fresh conversation without reusing the previous <code>conversation_id</code>.</td></tr>
          <tr class="searchable-row"><td><strong><code>400 unsupported_parameter</code> on <code>-wm</code> model</strong></td><td>Chat Completions Work Mode (<code>*-wm</code>) is intentionally unsupported on OpenAI gateway routes.</td><td>Use standard ChatGPT slugs (<code>auto</code>, <code>gpt-4o</code>, <code>gpt-5-2</code>, etc.) or Custom GPT / Project IDs (<code>g-...</code>).</td></tr>
          <tr class="searchable-row"><td><strong><code>429 rate_limit_exceeded</code> with <code>Retry-After</code></strong></td><td>Either an upstream ChatGPT account entered pool cooldown OR optional Mirror gateway rate limiting is turned on.</td><td>Add more accounts to the pool for rotation, or adjust/disable Gateway Rate Limiting in <a href="{$safeAdminUrl}">Admin Dashboard</a> &rarr; <code>rate_limit_enabled</code>.</td></tr>
          <tr class="searchable-row"><td><strong><code>OPTIONS</code> preflight or Browser SDK fails CORS</strong></td><td><code>cors_allowed_origins</code> is restricted or disabled.</td><td>In Admin or <code>PUT {$safeRuntimePath}</code>, enable <code>cors_enabled</code> and include your frontend origin in <code>cors_allowed_origins</code> (or <code>*</code>).</td></tr>
        </tbody>
      </table>
    </section>
  </main>
</div>

<script>
(function () {
  var search = document.getElementById('help-search');
  var emptyNotice = document.getElementById('search-empty');
  var sections = Array.prototype.slice.call(document.querySelectorAll('main .searchable'));

  function runFilter() {
    var q = (search && search.value || '').trim().toLowerCase();
    var anyVisible = false;

    sections.forEach(function (sec) {
      if (!q) {
        sec.classList.remove('hidden-by-search');
        var rows = sec.querySelectorAll('.searchable-row');
        rows.forEach(function (r) { r.classList.remove('hidden-by-search'); });
        anyVisible = true;
        return;
      }

      var secMeta = (sec.getAttribute('data-keywords') || '') + ' ' + (sec.querySelector('h2') ? sec.querySelector('h2').textContent : '');
      var rows = Array.prototype.slice.call(sec.querySelectorAll('.searchable-row'));

      if (rows.length === 0) {
        var matchedSec = (secMeta + ' ' + sec.textContent).toLowerCase().indexOf(q) !== -1;
        sec.classList.toggle('hidden-by-search', !matchedSec);
        if (matchedSec) anyVisible = true;
        return;
      }

      var headerHit = secMeta.toLowerCase().indexOf(q) !== -1;
      var matchedAnyRow = false;
      rows.forEach(function (r) {
        var hit = headerHit || r.textContent.toLowerCase().indexOf(q) !== -1;
        r.classList.toggle('hidden-by-search', !hit);
        if (hit) matchedAnyRow = true;
      });
      sec.classList.toggle('hidden-by-search', !matchedAnyRow);
      if (matchedAnyRow) anyVisible = true;
    });

    if (emptyNotice) {
      emptyNotice.style.display = anyVisible ? 'none' : 'block';
    }
  }

  if (search) {
    search.addEventListener('input', runFilter);
  }

  function copyText(text, btn) {
    if (!text) return;
    var done = function () {
      var prev = btn.textContent;
      btn.textContent = 'Copied!';
      setTimeout(function () { btn.textContent = prev; }, 1400);
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(done).catch(function () {
        fallbackCopy(text, done);
      });
    } else {
      fallbackCopy(text, done);
    }
  }

  function fallbackCopy(text, cb) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); } catch (e) {}
    document.body.removeChild(ta);
    cb();
  }

  document.querySelectorAll('[data-copy-target]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var target = document.getElementById(btn.getAttribute('data-copy-target'));
      if (target) copyText(target.textContent || '', btn);
    });
  });

  document.querySelectorAll('[data-copy]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      copyText(btn.getAttribute('data-copy') || '', btn);
    });
  });
})();
</script>
</body>
</html>
HTML;
    }
}
