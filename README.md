# Laravel ChatGPT Mirror

[![Latest Version on Packagist](https://img.shields.io/packagist/v/refatbd/laravel-chatgpt-mirror.svg?style=flat-square)](https://packagist.org/packages/refatbd/laravel-chatgpt-mirror)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE)
[![Tests Passing](https://img.shields.io/badge/tests-17%20passed-brightgreen.svg?style=flat-square)]()

A high-performance **OpenAI-compatible API Gateway** and **ChatGPT Web UI Reverse Proxy** for Laravel applications. Designed from the ground up for **high-concurrency production workloads (100–200+ simultaneous users)** with multi-token pooling, automated load balancing, and **zero Cloudflare WARP dependency** (Direct Network Egress).

Developed by [Mostafizur Rahman Refat](https://github.com/refatbd).

---

## 🌟 Key Features

* **OpenAI API Drop-in Replacement (`/v1/chat/completions` & `/v1/models`):**
  * Fully compatible with official OpenAI SDKs (Python, Node.js, Go, PHP, etc.).
  * Real-time **Server-Sent Events (SSE)** token streaming (`stream: true`) with true low-latency chunk forwarding.
  * Standard non-streaming JSON responses (`stream: false`).
* **High Concurrency Token Pool (100–200+ Concurrent Users):**
  * **Least-Busy Load Balancing:** Dynamically routes each incoming prompt to the ChatGPT account currently handling the fewest in-flight requests.
  * **Round-Robin & Random Balancing** options.
  * **Automated Circuit Breaker & 429 Cooldown:** Automatically isolates throttled tokens and seamlessly fails over to healthy tokens without dropping user prompts.
* **Direct Network Egress (No Cloudflare WARP Required):**
  * Runs directly over your standard server network connection using optimized HTTP/2 transport.
  * Optional HTTP/SOCKS5 proxy rotation support.
* **ChatGPT Web UI Gateway (`/mirror/*`):**
  * Proxies the official ChatGPT web interface.
  * Automatically strips restrictive `Content-Security-Policy` (CSP) headers.
  * Injects runtime browser patches (`localStorage.setItem("codex.browser.apiBaseUrl", "/backend-api")`) to resolve the *"We couldn't load your account"* failure mode.
* **Security & Encryption at Rest:**
  * All session tokens and access tokens are encrypted with AES-256 via Laravel's native `Crypt` facade.
* **Built for Laravel Octane & PHP-FPM:**
  * Zero-copy buffer flushes (`X-Accel-Buffering: no`, output buffer cleanup).
  * Native support for **FrankenPHP / RoadRunner / Swoole** for handling thousands of concurrent SSE streams.

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

This will publish `config/mirror.php`, run database migrations for token pooling, and generate a secure `MIRROR_API_KEY` in your `.env` file.

---

## ⚙️ Configuration (`.env`)

Add or adjust the following environment variables in your `.env`:

```env
# Master switch and authentication
MIRROR_ENABLED=true
MIRROR_API_KEY=sk-mirror-your-secret-key-here

# Load balancing strategy: "least_busy" (recommended), "round_robin", or "random"
MIRROR_POOL_STRATEGY=least_busy
MIRROR_COOLDOWN_SECONDS=60

# Direct Egress (No WARP required)
MIRROR_EGRESS_MODE=direct
MIRROR_TIMEOUT=90

# Route Prefixes
MIRROR_OPENAI_PREFIX=v1
MIRROR_BROWSER_PREFIX=mirror
MIRROR_BACKEND_PREFIX=backend-api
```

---

## 🔑 Managing ChatGPT Accounts & Tokens

To handle 100–200 concurrent users without triggering OpenAI rate limits, add multiple ChatGPT session tokens into the pool.

### 1. Adding a Single Session Token
Run the interactive CLI command:
```bash
php artisan mirror:token:add
```
Or provide arguments:
```bash
php artisan mirror:token:add "your-session-token-here" --name="Team Account 1"
```

> **How to get your session token:**
> 1. Sign in to [chatgpt.com](https://chatgpt.com) in your browser.
> 2. Open `https://chatgpt.com/api/auth/session` or check DevTools Application Cookies for `__Secure-next-auth.session-token`.
> 3. Copy the token value.

### 2. Bulk Importing Tokens from a File
```bash
php artisan mirror:token:import token.txt
```
*(Supports line-separated tokens or JSON session dumps)*

### 3. Monitoring Real-Time Pool Metrics
Check token health, in-flight requests, and cooldown statuses:
```bash
php artisan mirror:token:list
```

### 4. Running System Diagnostics
```bash
php artisan mirror:health
```

---

## 🚀 Usage

### 1. Using with Official OpenAI Python / Node SDK

Simply point the `base_url` to your Laravel app:

#### Python
```python
from openai import OpenAI

client = OpenAI(
    api_key="sk-mirror-your-secret-key-here",
    base_url="https://your-laravel-domain.com/v1",
)

response = client.chat.completions.create(
    model="gpt-4o",
    messages=[
        {"role": "system", "content": "You are a senior engineer."},
        {"role": "user", "content": "Explain reactive concurrency in PHP."},
    ],
    stream=True,
)

for chunk in response:
    if chunk.choices[0].delta.content:
        print(chunk.choices[0].delta.content, end="", flush=True)
```

#### cURL
```bash
curl https://your-laravel-domain.com/v1/chat/completions \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer sk-mirror-your-secret-key-here" \
  -d '{
    "model": "gpt-4o",
    "messages": [{"role": "user", "content": "Hello!"}],
    "stream": true
  }'
```

### 2. Programmatic Usage in Laravel (Facade)

You can call the mirror directly inside your Laravel services, jobs, or controllers:

```php
use Refatbd\LaravelChatGptMirror\Facades\Mirror;

// Simple synchronous generation
$reply = Mirror::chat([
    ['role' => 'user', 'content' => 'Write a short poem about coding.'],
], model: 'gpt-4o');

echo $reply;
```

### 3. Accessing Proxied ChatGPT Web UI

Navigate to `https://your-laravel-domain.com/mirror` in your browser. The UI will load with all features enabled, patched and connected to your active token pool.

---

## ⚡ High-Concurrency Production Tuning (100–200 Users)

When serving 100–200 simultaneous users streaming AI responses:

### 1. Nginx Configuration
Ensure Nginx does not buffer Server-Sent Events:

```nginx
location / {
    proxy_pass http://127.0.0.1:8000;
    proxy_http_version 1.1;
    proxy_set_header Connection "";
    proxy_set_header Host $host;

    # Disable buffering for real-time SSE streaming
    proxy_buffering off;
    proxy_cache off;
    proxy_read_timeout 180s;
    proxy_send_timeout 180s;
}
```

### 2. Laravel Octane (FrankenPHP)
For peak performance and thousands of concurrent long-lived connections with minimal RAM:
```bash
composer require laravel/octane
php artisan octane:install --server=frankenphp
php artisan octane:start --workers=8 --max-requests=1000
```

---

## 🧪 Testing

Run the automated test suite with PHPUnit:

```bash
composer test
```
Or directly:
```bash
./vendor/bin/phpunit
```

---

## 📄 License

This package is open-sourced software licensed under the [MIT license](LICENSE).
