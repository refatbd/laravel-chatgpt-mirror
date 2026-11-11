# Laravel ChatGPT Mirror

[![Tests](https://github.com/refatbd/laravel-chatgpt-mirror/actions/workflows/tests.yml/badge.svg)](https://github.com/refatbd/laravel-chatgpt-mirror/actions/workflows/tests.yml)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/refatbd/laravel-chatgpt-mirror.svg?style=flat-square)](https://packagist.org/packages/refatbd/laravel-chatgpt-mirror)
[![PHP Version](https://img.shields.io/packagist/php-v/refatbd/laravel-chatgpt-mirror.svg?style=flat-square)](composer.json)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE)

A production-ready Laravel Composer package providing an **OpenAI-compatible API Gateway** (`/v1/chat/completions`, `/v1/responses`, `/v1/models`), **SaaS Multi-Tenant Auth & Usage Metering Hooks**, **Multi-Account Token Pooling**, **Interactive Light-Mode Admin & Playground Portals**, and an optional **ChatGPT Web UI Reverse Proxy** — built for **universal web servers (Apache/Webuzo, Nginx, LiteSpeed, Caddy, FrankenPHP, Laravel Octane)** and **dynamic Redis (`auto | enabled | disabled`)** with direct upstream HTTP transport (no Cloudflare WARP or Node sidecar required).

Developed by [Mostafizur Rahman Refat](https://github.com/refatbd).

---

## Navigation & Built-in Interfaces

| Interface | Default URL | Description |
| :--- | :--- | :--- |
| **Settings & Admin Dashboard** | `/mirror/admin` | **Clean Light-Mode Control Panel.** Paste/upload `token.txt`, monitor active accounts, configure pool/proxy/CORS/rate-limit/Redis settings live in DB, and inspect sanitized failure logs. |
| **Help & About Reference** | `/mirror/help` | Searchable setup guide, feature matrix, dynamic `.env` reference, server deployment recipes, SDK snippets, and troubleshooting guide (aliases: `/help`, `/about`). |
| **Mirror API Playground** | `/mirror/playground` | Interactive browser UI for testing `/v1/chat/completions`, `/v1/responses`, custom system instructions, hotkeys, and binary format codecs. |
| **Swagger UI API Docs** | `/mirror/api-docs` | Local offline-capable OpenAPI `3.1.0` documentation rendered from `/mirror/openapi` (`?format=json` or `?format=yaml`). |
| **OpenAI Gateway Endpoints** | `/v1/*` | Drop-in `/v1/models`, `/v1/chat/completions` (sync, SSE streaming, client tools, history continuation & rebase), `/v1/responses`, `/v1/capabilities`. |
| **Native Mirror & Codecs APIs** | `/api/*` | `/api/health`, `/api/chat`, `/api/conversations/*`, `/api/files`, `/api/asset-content` (HMAC signed), `/api/convert/*` (`loaf`, `pngspeak`, `gptgif`, `gptgif-v4`, `gif89a`), `/api/decoder-challenges`. |
| **ChatGPT Web Proxy** *(Optional)* | `/` | Transparent ChatGPT Web UI proxy with URL rewriting and zero floating overlay (can be prefixed via `MIRROR_BROWSER_PREFIX` or disabled via `MIRROR_WEB_PROXY_ENABLED=false` for SaaS apps). |

---

## Key Capabilities

* **Drop-In OpenAI Compatibility:** Supports standard OpenAI Python/Node/PHP SDKs with SSE token streaming, hidden reasoning suppression, tool calling (`tools` + `role: tool` follow-ups), auto-inferred conversation continuation, user-prompt auto-rebase, and signed 7-day media download links.
* **Smart Token Extraction (`token.txt` & Cookies):** Paste an entire `chatgpt.com/api/auth/session` JSON dump, browser cookies JSON, Netscape `cookies.txt`, cURL snippet, HTTP `Cookie:` header, or raw token — extra metadata (`email`, `account_id`, `plan_type`, `accessToken`) is extracted automatically.
* **High-Concurrency Multi-Account Pool:** `least_busy`, `round_robin`, and `random` token balancing with atomic SQL slot claims, distributed Redis refresh locks, and automatic HTTP `429` cooldown circuit-breaking.
* **AES-256 Encrypted Storage:** `session_token`, `access_token`, and sensitive token metadata are encrypted at rest via Laravel `Crypt`, while DB tables work cleanly on **MySQL 8+**, **MariaDB**, **PostgreSQL**, and **SQLite**.
* **Dynamic Configuration Without Hardcoding:** Switch Redis modes (`auto`, `enabled`, `disabled`), web server platform (`apache`, `webuzo`, `nginx`, `litespeed`, `openlitespeed`, `caddy`, `frankenphp`, `octane`), HTTPS proxy trust, CORS origins, SSE idle timeouts, and optional rate limits live via `/mirror/admin` or `PUT /api/settings/runtime`.

---

## Installation

Install the package via Composer:

```bash
composer require refatbd/laravel-chatgpt-mirror
```

Run the installer to publish configuration and migrate tables:

```bash
php artisan mirror:install
```

Optional publish tags:

```bash
# Publish config/mirror.php
php artisan vendor:publish --tag=mirror-config

# Publish database migrations (if customizing schema for your SaaS)
php artisan vendor:publish --tag=mirror-migrations

# Publish Apache/Webuzo, Nginx, and Caddy production server templates
php artisan vendor:publish --tag=mirror-deploy
```

---

## Building a SaaS with This Package

`laravel-chatgpt-mirror` is designed to drop cleanly inside your existing or new Laravel SaaS application without hijacking your landing page, auth routes, or Filament/Nova admin panel.

### 1. Keep Your Host SaaS Routes Isolated (`.env`)

Disable the catch-all web proxy and root convenience aliases so your SaaS retains `/`, `/admin`, `/help`, `/about`, and `/pricing`:

```env
# Host SaaS route isolation
MIRROR_WEB_PROXY_ENABLED=false
MIRROR_ROOT_ALIASES=false

# Optional: mount everything on a dedicated subdomain or custom prefixes
# MIRROR_DOMAIN=api.yoursaas.com
MIRROR_OPENAI_PREFIX=v1
MIRROR_ADMIN_PREFIX=internal/mirror-admin
MIRROR_HELP_PREFIX=internal/mirror-help
MIRROR_PLAYGROUND_PREFIX=app/playground
```

### 2. Per-Customer SaaS API Keys & Admin Gate (`AppServiceProvider::boot()`)

Authenticate per-customer database API keys dynamically (and pass the resolved customer/tenant into completion events) and restrict `/mirror/admin` to your SaaS administrators — just like Laravel Horizon:

```php
use App\Models\CustomerApiKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Refatbd\LaravelChatGptMirror\Events\MirrorCompletionCompleted;
use Refatbd\LaravelChatGptMirror\Events\MirrorCompletionFailed;
use Refatbd\LaravelChatGptMirror\Facades\Mirror;

public function boot(): void
{
    // 1. Authenticate per-tenant API keys on /v1/* routes
    Mirror::authenticateApiKeyUsing(function (string $token, Request $request) {
        $record = CustomerApiKey::query()
            ->where('token_hash', hash('sha256', $token))
            ->where('active', true)
            ->first();

        if (! $record || $record->user->credits <= 0) {
            return false; // Returns OpenAI-standard 401 invalid_api_key
        }

        return [
            'user_id' => $record->user_id,
            'plan' => $record->user->plan,
            'key_id' => $record->id,
        ];
    });

    // 2. Restrict Admin Dashboard & /api/settings/runtime to SaaS admins
    Mirror::authorizeAdminUsing(function (Request $request): bool {
        return (bool) $request->user()?->is_admin;
    });

    // 3. Meter usage and deduct credits on completion (sync, streaming, and tool calls)
    Event::listen(MirrorCompletionCompleted::class, function (MirrorCompletionCompleted $event): void {
        if (! is_array($event->authContext) || empty($event->authContext['user_id'])) {
            return;
        }

        // Deduct $event->totalTokens or log $event->durationMs, $event->model, $event->conversationId
    });

    Event::listen(MirrorCompletionFailed::class, function (MirrorCompletionFailed $event): void {
        // Record upstream/pool failure telemetry for SLA monitoring
    });
}
```

You can also customize route middleware arrays in `config/mirror.php` (`routes.admin_middleware`, `routes.web_middleware`, `routes.api_middleware`), call `Mirror::ignoreMigrations()` / `Mirror::ignoreRoutes()` inside `AppServiceProvider::register()`, or call the engine directly from Laravel Jobs/Services:

```php
$reply = Mirror::chat(
    messages: [['role' => 'user', 'content' => 'Summarize this ticket.']],
    model: 'gpt-4o',
);
```

---

## Configuration (`.env`)

```env
# Master Switch & Feature Toggles
MIRROR_ENABLED=true
MIRROR_OPENAI_API_ENABLED=true
MIRROR_NATIVE_API_ENABLED=true
MIRROR_BACKEND_PROXY_ENABLED=true
MIRROR_WEB_PROXY_ENABLED=true
MIRROR_ADMIN_ENABLED=true
MIRROR_PLAYGROUND_ENABLED=true
MIRROR_HELP_ENABLED=true
MIRROR_DOCS_ENABLED=true
MIRROR_ROOT_ALIASES=true

# API Auth & Upstream
MIRROR_API_KEY=sk-mirror-your-secret-key-here
MIRROR_UPSTREAM=https://chatgpt.com
MIRROR_VERIFY_SSL=true

# Pool & Timeouts
MIRROR_POOL_STRATEGY=least_busy
MIRROR_MAX_CONCURRENT_PER_TOKEN=5
MIRROR_COOLDOWN_SECONDS=60
MIRROR_REFRESH_BUFFER_SECONDS=300
MIRROR_REQUEST_TIMEOUT=120
MIRROR_READ_TIMEOUT=120
MIRROR_CONNECT_TIMEOUT=15

# Route Prefixes
MIRROR_OPENAI_PREFIX=v1
MIRROR_ADMIN_PREFIX=mirror/admin
MIRROR_PLAYGROUND_PREFIX=mirror/playground
MIRROR_HELP_PREFIX=mirror/help
MIRROR_ASSETS_PREFIX=mirror/assets
MIRROR_DOCS_PREFIX=mirror
MIRROR_BACKEND_PREFIX=backend-api
MIRROR_BROWSER_PREFIX=

# Universal Web Server, Reverse Proxy & CORS (Live-editable in /mirror/admin)
MIRROR_SERVER_PLATFORM=auto
MIRROR_TRUST_PROXIES=true
MIRROR_TRUSTED_PROXIES=*
MIRROR_FORCE_HTTPS=
MIRROR_STREAM_MAX_EXECUTION_TIME=300
MIRROR_DISABLE_OUTPUT_COMPRESSION=true
MIRROR_CORS_ENABLED=true
MIRROR_CORS_ALLOWED_ORIGINS=*
MIRROR_RATE_LIMIT_ENABLED=false
MIRROR_RATE_LIMIT_MAX_ATTEMPTS=180
MIRROR_RATE_LIMIT_DECAY_SECONDS=60

# Optional Dynamic Redis (auto | enabled | disabled)
MIRROR_REDIS_ENABLED=auto
MIRROR_REDIS_CLIENT=auto
MIRROR_REDIS_HOST=127.0.0.1
MIRROR_REDIS_PORT=6379
MIRROR_REDIS_PASSWORD=
MIRROR_REDIS_DB=0
```

---

## Universal Production Deployment & Server CLI

Generate idempotent Apache `.htaccess` rules or print tailored VirtualHost snippets for Nginx, Webuzo Apache, LiteSpeed, Caddy, or Octane:

```bash
php artisan mirror:server apache --write --vhost --domain=api.example.com --root=/home/user/public_html/public
php artisan mirror:server nginx --vhost --domain=api.example.com --root=/var/www/app/public --php-version=8.4
php artisan mirror:server caddy --vhost --domain=api.example.com --root=/var/www/app/public
php artisan mirror:health
```

---

## Managing ChatGPT Accounts (`token.txt`)

### Via Admin Dashboard (`/mirror/admin`)
Paste the entire `chatgpt.com/api/auth/session` JSON dump (`token.txt`), Cookie header, Netscape cookies, cURL snippet, or select a `token.txt` file directly and click **Extract, Verify & Save Account**.

### Via Artisan CLI
```bash
php artisan mirror:token:add --file=token.txt
php artisan mirror:import-token token.txt
php artisan mirror:token:list
php artisan mirror:token:refresh
php artisan mirror:health
```

---

## Testing & Quality

```bash
composer validate --strict
composer test
# or
vendor/bin/phpunit --testdox
```

---

## Changelog & License

Please see [CHANGELOG](CHANGELOG.md) for recent changes and [License File](LICENSE) for MIT licensing details.
