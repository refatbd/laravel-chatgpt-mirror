# Changelog

All notable changes to `refatbd/laravel-chatgpt-mirror` will be documented in this file.

## [2.2.1] - 2026-11-13

### Added
- **Complete ChatGPT Web UI Route & Endpoint Coverage:**
  - `BackendApiController::publicApiProxy()` — Proxies `/public-api/*` discovery endpoints for Explore GPTs / Gizmo catalog feeds.
  - `BackendApiController::apiProxy()` — Proxies upstream `/api/*` endpoints (including `/api/library/files/{id}/content` for library thumbnails and modal previews).
  - `BackendApiController::codexProxy()` — Explicit dedicated proxy handler for `/__codex-api/*`.
- **Automatic Reverse Proxy Redirect & Path Rewriting:**
  - Upstream 301/302/307/308 redirect `Location` header host rewriting from `https://chatgpt.com` to the current local proxy host origin.
  - Transparent rewrite of `/api/library/files/{id}/thumbnail` and `/libfile` directly to `/content` to eliminate intermediate 404s before client fallback.
  - Static route prefix preservation for `/images/*`, `/assets/*`, `/fonts/*`, and `/_next/*` in `BrowserProxyController`.
  - Added `/public-api/` to the early browser client `fetch` interception in `BrowserPatchManager`.

## [2.2.0] - 2026-11-12

### Added
- **Multi-Tenant SaaS Web UI & Backend Proxy Hooks on `Mirror`:**
  - `Mirror::shouldProxyRequestUsing()` — Dynamic host/path proxy guard compatible with compiled `route:cache`.
  - `Mirror::resolveWebContextUsing()` — Per-request token pool / remote mirror node relay resolution (`upstream_url`, `upstream_headers`, `http_proxy`, `base_path`, scoped `release`).
  - `Mirror::customizeBrowserSessionUsing()` — Identity masking for `/api/auth/session` on shared ChatGPT accounts.
  - `Mirror::interceptBackendRequestUsing()` — Pre-flight quota gate and sensitive ChatGPT settings/billing path blocker.
  - `Mirror::observeBackendStreamUsing()` — Real-time incremental SSE line inspector accurately detecting assistant/tool `image_gen`/`dalle` outputs (`image_asset_pointer`) while ignoring user upload echoes.
  - `Mirror::filterBackendResponseUsing()` — Non-streaming response filtering (e.g. strict vs shared conversation sidebar isolation).
  - `Mirror::injectBrowserHtmlUsing()` & `Mirror::filterBrowserResponseHeadersUsing()` — Clean HTML widget injection and header customization.
- **Browser & Relay Enhancements:**
  - `ResolvesWebProxyContext` shared controller trait across `BrowserProxyController` and `BackendApiController`.
  - Upstream remote-mirror origin rewriting in `UrlRewriter` and duplicate early-patch stripping + `history.pushState`/`replaceState` subpath handling in `BrowserPatchManager`.
  - Optional Guzzle `Client` dependency injection on `BrowserProxyController` and `BackendApiController`.

## [2.1.0] - 2026-11-11

### Added
- **SaaS Integration & Multi-Tenant Extensibility:**
  - Feature flags in `config/mirror.php` (`features.web_proxy`, `features.root_aliases`, `features.openai_api`, `features.mirror_api`, `features.backend_proxy`, `features.admin`, `features.playground`, `features.help`, `features.docs`) and master `MIRROR_ENABLED` switch so host SaaS apps keep `/`, `/admin`, `/about`, and `/help` collision-free.
  - Configurable route domain (`routes.domain`) and middleware stacks (`routes.api_middleware`, `routes.web_middleware`, `routes.admin_middleware`) plus middleware aliases `mirror.runtime`, `mirror.api_key`, `mirror.control`, and `mirror.admin`.
  - Programmatic SaaS hooks on `Mirror`: `Mirror::authenticateApiKeyUsing()`, `Mirror::authorizeAdminUsing()`, `Mirror::ignoreMigrations()`, and `Mirror::ignoreRoutes()`.
  - Metering & billing Laravel events: `MirrorCompletionCompleted` and `MirrorCompletionFailed`.
  - Publishable `mirror-migrations` tag and configurable table names under `config('mirror.storage.*')`.
- **Full Light-Mode Web Portal:**
  - Unified crisp Light Mode across Admin Dashboard (`/mirror/admin`), searchable Help & About portal (`/mirror/help`), and local Swagger UI API Docs (`/mirror/api-docs`).
- **Universal Web Server & Dynamic Runtime Control:**
  - Apache/Webuzo, Nginx, LiteSpeed/OpenLiteSpeed, Caddy, FrankenPHP, and Laravel Octane support via `php artisan mirror:server`.
  - Dynamic live Redis (`auto | enabled | disabled`, `phpredis` / `predis`) and database-backed runtime overrides without editing `.env`.

### Fixed
- Scoped catch-all `/{path}` proxy inside `browser_prefix` so non-root browser prefixes never hijack host application routes.
- Fixed MySQL 8 / MariaDB strict-mode migration compatibility on `mirror_messages.content` (`longText()->nullable()`).
- Fixed `Mirror` Facade container alias (`laravel-chatgpt-mirror`) and `Mirror::chat()` parameter ordering.
