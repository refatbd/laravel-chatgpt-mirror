# Changelog

All notable changes to `refatbd/laravel-chatgpt-mirror` will be documented in this file.

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
