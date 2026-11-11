# Laravel ChatGPT Mirror

[![Latest Version on Packagist](https://img.shields.io/packagist/v/refatbd/laravel-chatgpt-mirror.svg?style=flat-square)](https://packagist.org/packages/refatbd/laravel-chatgpt-mirror)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE)
[![Tests Passing](https://img.shields.io/badge/tests-passing-brightgreen.svg?style=flat-square)]()

A high-performance **OpenAI-compatible API Gateway**, **Interactive Developer Playground**, and **ChatGPT Web UI Reverse Proxy** for Laravel applications. Designed for **high-concurrency production workloads (100–200+ simultaneous users)** with multi-token pooling, **Redis distributed locks & caching**, **universal web server support (Apache/Webuzo, Nginx, LiteSpeed, Caddy, FrankenPHP, Octane)**, and **100% Direct Gateway connectivity (No Cloudflare WARP required)**.

Developed by [Mostafizur Rahman Refat](https://github.com/refatbd).

---

## 🧭 Navigation & Available Interfaces

Your application exposes complementary interfaces designed for distinct purposes:

| Interface | URL Path | Description |
| :--- | :--- | :--- |
| **Clean ChatGPT Web UI** | `http://127.0.0.1:8000/` or `/` | **100% Clean & Native ChatGPT Interface.** Zero clutter, overlays, or buttons. Behaves and looks identically to official ChatGPT for your team or end users. |
| **Mirror API Playground** | `http://127.0.0.1:8000/mirror/playground` (Alias: `/playground`) | **Interactive Developer Testbench.** Test Chat Completions & Responses, run format conversions (`loaf`, `pngspeak`, `gptgif`, `gptgif-v4`), inspect raw JSON/SSE responses, and pick live models. |
| **Settings & Admin Pool Manager** | `http://127.0.0.1:8000/mirror/admin` (Alias: `/admin`) | **Concurrency Pool Dashboard & Settings.** Monitor live in-flight concurrency, add/delete ChatGPT accounts, rotate tokens with `least_busy` balancing, reset counters, and view API keys. |
| **Interactive API Docs (Swagger UI)** | `http://127.0.0.1:8000/mirror/api-docs` | Interactive OpenAPI 3.1.0 documentation rendered directly from `http://127.0.0.1:8000/mirror/openapi` (`?format=json` / `?format=yaml`). |
| **OpenAI Chat & Responses API** | `/v1/chat/completions` & `/v1/responses` | Standard OpenAI drop-in endpoints (sync & SSE streaming) with tool calling, multi-turn continuity, and rich citation/artifact extraction. |
| **Native Mirror & Convert APIs** | `/api/models`, `/api/gpts`, `/api/conversations*`, `/api/convert/*`, `/api/decoder-challenges` | Live ChatGPT model/GPT discovery, searchable persistent conversation history, codec round-trips/gauntlet, and decoder challenge APIs. |
| **Health, Diagnostics & Capabilities** | `/api/health`, `/api/diagnostics`, `/v1/capabilities` | Real-time health telemetry (Direct Egress), schema/build/failure diagnostics, and capability discovery. |

---

## 🌟 Key Features

* **100% Clean ChatGPT Interface (`/`):**
  * Serves the official ChatGPT Web UI without any third-party buttons, overlays, or injected launchers.
  * Deep Referer rewriting for full Estuary CDN and SVG/media download support.
  * Local SSD asset caching (`< 1ms` static load latency).
* **Interactive API Playground (`/mirror/playground`):**
  * Full developer interface replicating the original Mirror playground.
  * Model switching (`auto`, `gpt-4o`, `gpt-4o-mini`, `o1`).
  * Live SSE streaming viewer and raw response inspect panel.
* **High-Concurrency Multi-Token Pool (100–200+ Concurrent Users):**
  * **Least-Busy Load Balancing:** Automatically routes incoming requests to the ChatGPT account currently handling the fewest active in-flight requests.
  * **Round-Robin & Random Balancing** alternatives.
  * **Automated 429 Rate Limit Cooldown & Circuit Breaking:** Isolates rate-limited accounts and seamlessly dispatches queries to healthy pool accounts.
* **Direct OpenAI Gateway (No Cloudflare WARP Required):**
  * Operates directly over standard HTTP/2 network transport. No Docker containers, VPN tunnels, or WARP proxies needed.
* **AES-256 At-Rest Encryption:**
  * All session tokens, access tokens, and sensitive metadata are encrypted using Laravel's native `Crypt` facade.

---

## 📦 Installation

Install the package via Composer:

```bash
composer require refatbd/laravel-chatgpt-mirror
```

Run the automated installer:

```bash
php artisan mirror:install
```

This publishes `config/mirror.php`, runs database migrations for token pooling, and sets up your `MIRROR_API_KEY`.

---

## ⚙️ Configuration (`.env`)

```env
# Master switch and authentication
MIRROR_ENABLED=true
MIRROR_API_KEY=sk-mirror-your-secret-key-here

# Concurrency pool strategy: "least_busy" (recommended), "round_robin", or "random"
MIRROR_POOL_STRATEGY=least_busy
MIRROR_COOLDOWN_SECONDS=60

# HTTP Transport
MIRROR_TIMEOUT=90
MIRROR_CONNECT_TIMEOUT=10

# Route Prefixes (Everything configurable; never hardcoded)
MIRROR_OPENAI_PREFIX=v1
MIRROR_ADMIN_PREFIX=mirror/admin
MIRROR_PLAYGROUND_PREFIX=mirror/playground
MIRROR_ASSETS_PREFIX=mirror/assets
MIRROR_DOCS_PREFIX=mirror
MIRROR_BROWSER_PREFIX=
MIRROR_BACKEND_PREFIX=__codex-api

# Universal Web Server & Reverse Proxy Settings (Live-editable in /mirror/admin)
MIRROR_SERVER_PLATFORM=auto
MIRROR_TRUST_PROXIES=true
MIRROR_TRUSTED_PROXIES=*
MIRROR_FORCE_HTTPS=auto
MIRROR_STREAM_MAX_EXECUTION_TIME=0
MIRROR_DISABLE_OUTPUT_COMPRESSION=true

# Dynamic Redis Configuration (Optional — auto-detects or toggle live in /mirror/admin)
# Modes: auto (use when available) | enabled (prefer Redis, fallback if down) | disabled (no Redis)
MIRROR_REDIS_ENABLED=auto
MIRROR_CACHE_STORE=
MIRROR_REDIS_CLIENT=auto
MIRROR_REDIS_HOST=127.0.0.1
MIRROR_REDIS_PORT=6379
MIRROR_REDIS_PASSWORD=
MIRROR_REDIS_DB=0
```

---

## 🌐 Universal Production Deployment & Redis

`laravel-chatgpt-mirror` automatically adapts to whichever web server and PHP runtime you run in production, and dynamically adjusts at runtime via `.env` or the **Dynamic Server, Proxy & Redis Configuration** panel inside `/mirror/admin` (REST API: `GET/PUT/DELETE /api/settings/runtime` & `POST /api/settings/runtime/test-redis`):

* **Bearer Auth Header Normalization (`PrepareMirrorRuntime`):** Automatically restores `Authorization` from `REDIRECT_HTTP_AUTHORIZATION` / `HTTP_AUTHORIZATION` under Apache CGI / FastCGI / PHP-FPM, plus `X-API-Key` and `?api_key=`.
* **Reverse Proxy, Subdirectory & HTTPS Detection:** Detects TLS, `X-Forwarded-Prefix` subpaths, and public origin cleanly behind Cloudflare, Nginx, Caddy, Load Balancers, and Webuzo Apache via `X-Forwarded-Proto`, `X-Forwarded-Host`, `Front-End-Https`, `CF-Visitor`, and RFC 7239 `Forwarded` without leaking static Symfony trusted-proxy state in Octane/FrankenPHP workers.
* **Unbuffered SSE Across All Servers:** Sends `X-Accel-Buffering: no`, `X-LiteSpeed-Cache-Control: no-cache`, `Content-Encoding: none`, `Cache-Control: no-cache, no-store, must-revalidate, no-transform`, disables `zlib.output_compression` on stream routes, and lifts `max_execution_time` cleanly for long reasoning responses.
* **Optional / Dynamic Redis & Multi-Worker Concurrency:**
  * Enable, disable, or test Redis (`auto`, `enabled`, `disabled`, host/port/url/password/DB, `auto`/`phpredis`/`predis`) live from `/mirror/admin` without touching `.env` or restarting PHP-FPM.
  * When Redis is absent, disabled, or temporarily unreachable, the app runs seamlessly using Laravel's fallback cache store and atomic SQL `tryAcquireSlot()`.
  * When Redis is active, distributed locks guard `ensureTokenFreshness()` and cache `/v1/models`, `/api/models`, `/api/gpts`, runtime settings, decoder challenges, and origin-scoped static proxy assets.

### Server-Specific Setup CLI

Run the built-in server setup assistant with dynamic flags at any time:

```bash
# Publish Apache/Webuzo, Nginx, and Caddy templates to deploy/mirror/
php artisan vendor:publish --tag=mirror-deploy

# Webuzo / Apache / LiteSpeed: idempotently update public/.htaccess + inspect VirtualHost directives
php artisan mirror:server apache --write --vhost --domain=api.example.com --root=/home/user/public_html/public
# (Or using the alias:)
php artisan mirror:apache --write --vhost

# Nginx / Caddy / FrankenPHP / Octane: print production server snippet with your domain, root & PHP version
php artisan mirror:server nginx --vhost --domain=api.example.com --root=/var/www/app/public --php-version=8.4
php artisan mirror:server caddy --vhost --domain=api.example.com --root=/var/www/app/public
php artisan mirror:server octane --vhost --host=0.0.0.0 --port=8000

# Verify Redis mode/ping, dynamic overrides, locks, PHP runtime flags, and upstream reachability
php artisan mirror:health
```

> **Webuzo Panel + Apache Quick Note:** After running `php artisan mirror:server apache --write`, also paste the printed directives (`AllowEncodedSlashes NoDecode`, `CGIPassAuth On`, `Timeout 300`) into **Webuzo → Apache Extra Configuration / Custom VirtualHost Include** so `%2F` URLs and long SSE streams work cleanly.
>
> **Redis Client:** Install either PHP's `redis` extension (`phpredis`, enabled in PHP extensions in Webuzo/cPanel) or `composer require predis/predis`, then toggle Redis live in **Admin Dashboard (`/mirror/admin`) → Dynamic Server, Proxy & Redis Configuration** (`Save & Apply Live Settings` / `Test Redis Connection`). Even without Redis installed, the project runs cleanly out of the box.

---

## 🔑 Managing ChatGPT Accounts & Tokens

You can manage accounts either via the **Web Admin Dashboard** (`http://127.0.0.1:8000/mirror/admin`) or via the Artisan CLI.

### Option A: Via Web Admin Dashboard
1. Open `http://127.0.0.1:8000/mirror/admin` in your browser.
2. In the **Add ChatGPT Account Token** card, either paste the entire `token.txt` (`chatgpt.com/api/auth/session` JSON dump), browser Cookie JSON / `Cookie:` header / cURL / Netscape cookies, or choose the `token.txt` file directly — the extractor automatically pulls out `sessionToken`, `accessToken`, `email`, `name`, `accountId`, and `planType`.
3. Click **Extract, Verify & Save Account**.
4. To serve 100–200 concurrent users without rate limits, add 3 to 5 separate session tokens (you can even paste a JSON array or multi-line dump to bulk-import in one click). The `least_busy` load balancer will automatically distribute queries across healthy accounts.

### Option B: Via Artisan CLI

#### 1. Add a Token Interactively or from `token.txt`
```bash
php artisan mirror:token:add
# Or pass token.txt directly:
php artisan mirror:token:add --file=token.txt
```
Or pass inline token/header arguments:
```bash
php artisan mirror:token:add "your-session-token-here" --name="Team Account 1"
```

> **How to get your session token:**
> 1. Sign in to [chatgpt.com](https://chatgpt.com) in your browser.
> 2. Open `https://chatgpt.com/api/auth/session`, save/copy the entire JSON into `token.txt` (or paste directly into `/mirror/admin`), or copy `__Secure-next-auth.session-token` / DevTools `Cookie:` header.
> 3. Paste all of it directly — no manual trimming needed.

#### 2. Bulk Import Tokens from `token.txt` / `cookies.json`
```bash
php artisan mirror:import-token token.txt
```

#### 3. View Real-Time Pool Metrics
```bash
php artisan mirror:token:list
```

#### 4. Run System Health Check
```bash
php artisan mirror:health
```

---

## 🚀 Usage with OpenAI SDKs

### 1. Python (Official OpenAI SDK)
```python
from openai import OpenAI

client = OpenAI(
    api_key="sk-mirror-your-secret-key-here",
    base_url="http://127.0.0.1:8000/v1",
)

response = client.chat.completions.create(
    model="gpt-4o",
    messages=[
        {"role": "system", "content": "You are a helpful assistant."},
        {"role": "user", "content": "Explain concurrent pooling in Laravel."},
    ],
    stream=True,
)

for chunk in response:
    if chunk.choices[0].delta.content:
        print(chunk.choices[0].delta.content, end="", flush=True)
```

### 2. Laravel HTTP Client (PHP)
```php
use Illuminate\Support\Facades\Http;

$response = Http::withToken(config('mirror.api_key'))
    ->post('http://127.0.0.1:8000/v1/chat/completions', [
        'model' => 'gpt-4o',
        'messages' => [
            ['role' => 'user', 'content' => 'Hello!'],
        ],
        'stream' => false,
    ]);

$reply = $response->json('choices.0.message.content');
```

### 3. cURL
```bash
curl http://127.0.0.1:8000/v1/chat/completions \
  -H "Authorization: Bearer sk-mirror-your-secret-key-here" \
  -H "Content-Type: application/json" \
  -d '{
    "model": "gpt-4o",
    "messages": [{"role": "user", "content": "Hello!"}],
    "stream": true
  }'
```

---

## 🧪 Testing

Run the automated test suite:

```bash
composer test
```
Or directly with PHPUnit:
```bash
./vendor/bin/phpunit
```

---

## 📄 License

The MIT License (MIT). Please see [License File](LICENSE) for more information.
