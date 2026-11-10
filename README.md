# Laravel ChatGPT Mirror

[![Latest Version on Packagist](https://img.shields.io/packagist/v/refatbd/laravel-chatgpt-mirror.svg?style=flat-square)](https://packagist.org/packages/refatbd/laravel-chatgpt-mirror)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE)
[![Tests Passing](https://img.shields.io/badge/tests-31%20passed-brightgreen.svg?style=flat-square)]()

A high-performance **OpenAI-compatible API Gateway**, **Interactive Developer Playground**, and **ChatGPT Web UI Reverse Proxy** for Laravel applications. Designed for **high-concurrency production workloads (100–200+ simultaneous users)** with multi-token pooling, automated load balancing, and **100% Direct Gateway connectivity (No Cloudflare WARP required)**.

Developed by [Mostafizur Rahman Refat](https://github.com/refatbd).

---

## 🧭 Navigation & Available Interfaces

Your application exposes three complementary interfaces designed for distinct purposes:

| Interface | URL Path | Description |
| :--- | :--- | :--- |
| **Clean ChatGPT Web UI** | `http://127.0.0.1:8000/` or `/` | **100% Clean & Native ChatGPT Interface.** Zero clutter, overlays, or buttons. Behaves and looks identically to official ChatGPT for your team or end users. |
| **Mirror API Playground** | `http://127.0.0.1:8000/mirror/playground` (Alias: `/playground`) | **Interactive Developer Testbench.** Test completions, build multi-turn prompt chains, customize default system prompts, inspect raw JSON/SSE responses, and pick models. |
| **Settings & Admin Pool Manager** | `http://127.0.0.1:8000/mirror/admin` (Alias: `/admin`) | **Concurrency Pool Dashboard & Settings.** Monitor live in-flight concurrency, add/delete ChatGPT accounts, rotate tokens with `least_busy` balancing, reset counters, and view API keys. |
| **OpenAI API Endpoint** | `http://127.0.0.1:8000/v1/chat/completions` | Standard OpenAI drop-in endpoint for Python, Node.js, Go, PHP, Cursor, or any OpenAI-compatible client. |
| **System Health API** | `http://127.0.0.1:8000/api/health` | Real-time JSON health check (active tokens, in-flight concurrency count, upstream gateway status). |
| **Diagnostics API** | `http://127.0.0.1:8000/api/diagnostics` | Complete system diagnostics including PHP version, Laravel environment, token pool metrics, and storage. |
| **API Capabilities** | `http://127.0.0.1:8000/v1/capabilities` | Supported OpenAI fields, model capabilities, and streaming feature matrix. |

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

# Route Prefixes
MIRROR_OPENAI_PREFIX=v1
MIRROR_BROWSER_PREFIX=mirror
MIRROR_BACKEND_PREFIX=backend-api
```

---

## 🔑 Managing ChatGPT Accounts & Tokens

You can manage accounts either via the **Web Admin Dashboard** (`http://127.0.0.1:8000/mirror/admin`) or via the Artisan CLI.

### Option A: Via Web Admin Dashboard
1. Open `http://127.0.0.1:8000/mirror/admin` in your browser.
2. In the **Add Account to Pool** box, paste your session token or the full session JSON from `chatgpt.com/api/auth/session`.
3. Click **+ Add Account to Concurrency Pool**.
4. To serve 100–200 concurrent users without rate limits, add 3 to 5 separate session tokens. The `least_busy` load balancer will automatically distribute queries across healthy accounts.

### Option B: Via Artisan CLI

#### 1. Add a Token Interactively
```bash
php artisan mirror:token:add
```
Or with inline arguments:
```bash
php artisan mirror:token:add "your-session-token-here" --name="Team Account 1"
```

> **How to get your session token:**
> 1. Sign in to [chatgpt.com](https://chatgpt.com) in your browser.
> 2. Open `https://chatgpt.com/api/auth/session` or check DevTools Application Cookies for `__Secure-next-auth.session-token`.
> 3. Copy the token value.

#### 2. Bulk Import Tokens from a File
```bash
php artisan mirror:token:import token.txt
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
