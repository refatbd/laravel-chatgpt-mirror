<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror;

use Refatbd\LaravelChatGptMirror\Contracts\StreamTransformerInterface;
use Refatbd\LaravelChatGptMirror\Exceptions\TokenPoolExhaustedException;
use Refatbd\LaravelChatGptMirror\Exceptions\UpstreamRequestException;
use Refatbd\LaravelChatGptMirror\Services\ChatGptClient;
use Refatbd\LaravelChatGptMirror\Services\ConversationService;
use Refatbd\LaravelChatGptMirror\Services\DynamicConfigService;
use Refatbd\LaravelChatGptMirror\Services\MirrorCache;
use Refatbd\LaravelChatGptMirror\Services\SecurityService;
use Refatbd\LaravelChatGptMirror\Services\StreamTransformer;
use Refatbd\LaravelChatGptMirror\Services\TokenPoolManager;
use Refatbd\LaravelChatGptMirror\Services\TokenRefresher;

class Mirror
{
    public static bool $runsMigrations = true;

    public static bool $registersRoutes = true;

    /** @var (callable(string, \Illuminate\Http\Request): mixed)|null */
    protected static $apiKeyAuthenticator = null;

    /** @var (callable(\Illuminate\Http\Request): bool)|null */
    protected static $adminAuthorizer = null;

    /** @var (callable(\Illuminate\Http\Request): ?bool)|null */
    protected static $proxyRequestGuard = null;

    /** @var (callable(\Illuminate\Http\Request): ?array)|null */
    protected static $webContextResolver = null;

    /** @var (callable(array, \Illuminate\Http\Request, mixed): array)|null */
    protected static $browserSessionCustomizer = null;

    /** @var (callable(\Illuminate\Http\Request, string, array): mixed)|null */
    protected static $backendRequestInterceptor = null;

    /** @var (callable(string, array, \Illuminate\Http\Request, string, array): void)|null */
    protected static $backendStreamObserver = null;

    /** @var (callable(\Symfony\Component\HttpFoundation\Response, \Illuminate\Http\Request, string, array): \Symfony\Component\HttpFoundation\Response)|null */
    protected static $backendResponseFilter = null;

    /** @var (callable(string, \Illuminate\Http\Request, array): string)|null */
    protected static $browserHtmlInjector = null;

    /** @var (callable(array, \Illuminate\Http\Request, array): array)|null */
    protected static $browserResponseHeadersFilter = null;

    public function __construct(
        protected TokenPoolManager $poolManager,
        protected ChatGptClient $client,
        protected TokenRefresher $refresher,
        protected ?StreamTransformerInterface $transformer = null,
        protected ?ConversationService $conversations = null,
        protected ?MirrorCache $cache = null,
        protected ?SecurityService $security = null,
        protected ?DynamicConfigService $dynamicConfig = null
    ) {}

    public static function ignoreMigrations(): void
    {
        static::$runsMigrations = false;
    }

    public static function useMigrations(): void
    {
        static::$runsMigrations = true;
    }

    public static function ignoreRoutes(): void
    {
        static::$registersRoutes = false;
    }

    public static function useRoutes(): void
    {
        static::$registersRoutes = true;
    }

    /**
     * Register a custom SaaS API key validator for `/v1/*` routes.
     * Callback receives ($apiKey, Request $request) and returns truthy/user/tenant context or false/null.
     *
     * @param (callable(string, \Illuminate\Http\Request): mixed)|null $callback
     */
    public static function authenticateApiKeyUsing(?callable $callback): void
    {
        static::$apiKeyAuthenticator = $callback;
    }

    /**
     * @return (callable(string, \Illuminate\Http\Request): mixed)|null
     */
    public static function resolveApiKeyAuthenticator(): ?callable
    {
        return static::$apiKeyAuthenticator;
    }

    /**
     * Register a custom SaaS gate callback for Admin Dashboard & runtime control routes.
     * Mirrors Laravel Horizon::auth() ergonomics.
     *
     * @param (callable(\Illuminate\Http\Request): bool)|null $callback
     */
    public static function authorizeAdminUsing(?callable $callback): void
    {
        static::$adminAuthorizer = $callback;
    }

    /**
     * @return (callable(\Illuminate\Http\Request): bool)|null
     */
    public static function resolveAdminAuthorizer(): ?callable
    {
        return static::$adminAuthorizer;
    }

    /**
     * Dynamic host/path matcher callback compatible with compiled `route:cache`.
     *
     * @param (callable(\Illuminate\Http\Request): ?bool)|null $callback
     */
    public static function shouldProxyRequestUsing(?callable $callback): void
    {
        static::$proxyRequestGuard = $callback;
    }

    /**
     * @return (callable(\Illuminate\Http\Request): ?bool)|null
     */
    public static function resolveProxyRequestGuard(): ?callable
    {
        return static::$proxyRequestGuard;
    }

    /**
     * Resolve per-request server/tenant proxy context (scoped MirrorToken, remote upstream_url, proxy, base_path).
     *
     * @param (callable(\Illuminate\Http\Request): ?array)|null $callback
     */
    public static function resolveWebContextUsing(?callable $callback): void
    {
        static::$webContextResolver = $callback;
    }

    /**
     * @return (callable(\Illuminate\Http\Request): ?array)|null
     */
    public static function resolveWebContextResolver(): ?callable
    {
        return static::$webContextResolver;
    }

    /**
     * Customize `/api/auth/session` JSON returned to ChatGPT Web UI (e.g. mask shared account identity).
     *
     * @param (callable(array, \Illuminate\Http\Request, mixed): array)|null $callback
     */
    public static function customizeBrowserSessionUsing(?callable $callback): void
    {
        static::$browserSessionCustomizer = $callback;
    }

    /**
     * @return (callable(array, \Illuminate\Http\Request, mixed): array)|null
     */
    public static function resolveBrowserSessionCustomizer(): ?callable
    {
        return static::$browserSessionCustomizer;
    }

    /**
     * Pre-flight request interceptor before `/backend-api/*` is forwarded upstream.
     * Return a Response to short-circuit (e.g. quota exceeded or blocked settings path).
     *
     * @param (callable(\Illuminate\Http\Request, string, array): mixed)|null $callback
     */
    public static function interceptBackendRequestUsing(?callable $callback): void
    {
        static::$backendRequestInterceptor = $callback;
    }

    /**
     * @return (callable(\Illuminate\Http\Request, string, array): mixed)|null
     */
    public static function resolveBackendRequestInterceptor(): ?callable
    {
        return static::$backendRequestInterceptor;
    }

    /**
     * Real-time observer for parsed SSE lifecycle events during `/backend-api/*` streaming.
     *
     * @param (callable(string, array, \Illuminate\Http\Request, string, array): void)|null $callback
     */
    public static function observeBackendStreamUsing(?callable $callback): void
    {
        static::$backendStreamObserver = $callback;
    }

    /**
     * @return (callable(string, array, \Illuminate\Http\Request, string, array): void)|null
     */
    public static function resolveBackendStreamObserver(): ?callable
    {
        return static::$backendStreamObserver;
    }

    /**
     * Post-process or filter non-streaming `/backend-api/*` responses (e.g. conversation isolation).
     *
     * @param (callable(\Symfony\Component\HttpFoundation\Response, \Illuminate\Http\Request, string, array): \Symfony\Component\HttpFoundation\Response)|null $callback
     */
    public static function filterBackendResponseUsing(?callable $callback): void
    {
        static::$backendResponseFilter = $callback;
    }

    /**
     * @return (callable(\Symfony\Component\HttpFoundation\Response, \Illuminate\Http\Request, string, array): \Symfony\Component\HttpFoundation\Response)|null
     */
    public static function resolveBackendResponseFilter(): ?callable
    {
        return static::$backendResponseFilter;
    }

    /**
     * Inject isolated HTML/JS (such as a Shadow-DOM floating quota pill) into proxied `text/html` pages.
     *
     * @param (callable(string, \Illuminate\Http\Request, array): string)|null $callback
     */
    public static function injectBrowserHtmlUsing(?callable $callback): void
    {
        static::$browserHtmlInjector = $callback;
    }

    /**
     * @return (callable(string, \Illuminate\Http\Request, array): string)|null
     */
    public static function resolveBrowserHtmlInjector(): ?callable
    {
        return static::$browserHtmlInjector;
    }

    /**
     * Customize cleaned response headers on proxied browser responses (e.g. framing policy).
     *
     * @param (callable(array, \Illuminate\Http\Request, array): array)|null $callback
     */
    public static function filterBrowserResponseHeadersUsing(?callable $callback): void
    {
        static::$browserResponseHeadersFilter = $callback;
    }

    /**
     * @return (callable(array, \Illuminate\Http\Request, array): array)|null
     */
    public static function resolveBrowserResponseHeadersFilter(): ?callable
    {
        return static::$browserResponseHeadersFilter;
    }

    public static function resetConfigurationHooks(): void
    {
        static::$runsMigrations = true;
        static::$registersRoutes = true;
        static::$apiKeyAuthenticator = null;
        static::$adminAuthorizer = null;
        static::$proxyRequestGuard = null;
        static::$webContextResolver = null;
        static::$browserSessionCustomizer = null;
        static::$backendRequestInterceptor = null;
        static::$backendStreamObserver = null;
        static::$backendResponseFilter = null;
        static::$browserHtmlInjector = null;
        static::$browserResponseHeadersFilter = null;
    }

    public function pool(): TokenPoolManager
    {
        return $this->poolManager;
    }

    public function client(): ChatGptClient
    {
        return $this->client;
    }

    public function refresher(): TokenRefresher
    {
        return $this->refresher;
    }

    public function conversations(): ConversationService
    {
        return $this->conversations ??= app()->bound(ConversationService::class)
            ? app(ConversationService::class)
            : new ConversationService($this->client, $this->poolManager);
    }

    public function cache(): MirrorCache
    {
        return $this->cache ??= app()->bound(MirrorCache::class)
            ? app(MirrorCache::class)
            : new MirrorCache();
    }

    public function security(): SecurityService
    {
        return $this->security ??= app()->bound(SecurityService::class)
            ? app(SecurityService::class)
            : new SecurityService();
    }

    public function config(): DynamicConfigService
    {
        return $this->dynamicConfig ??= app()->bound(DynamicConfigService::class)
            ? app(DynamicConfigService::class)
            : new DynamicConfigService();
    }

    /**
     * Programmatic helper to execute a ChatGPT completion cleanly from Laravel application code / jobs.
     *
     * @param array<int, array<string, mixed>> $messages
     * @param (callable(string): void)|null $onDelta
     */
    public function chat(array $messages, string $model = 'gpt-4o', bool $stream = false, ?callable $onDelta = null): string
    {
        $token = $this->poolManager->acquire();
        if (!$token) {
            throw new TokenPoolExhaustedException('No active ChatGPT accounts available in token pool.');
        }

        $route = $this->client->routeModel($model);
        $routedModel = (string) ($route['model'] ?? $model);
        $payload = $this->client->buildConversationPayload($messages, $routedModel, null, 'client-created-root', $route['gizmoId'] ?? null);

        $transformer = $this->transformer instanceof StreamTransformerInterface
            ? clone $this->transformer
            : new StreamTransformer();
        $transformer->reset();

        $success = false;
        try {
            $this->client->streamConversation($token, $payload, function (string $eventName, string $json) use ($transformer, $model, $stream, $onDelta): void {
                $events = $transformer->processEventPayload($eventName, $json, $model, 'mirror-sdk');
                if ($stream && $onDelta !== null) {
                    foreach ($events as $ev) {
                        if (($ev['kind'] ?? '') === 'assistant_text_delta' && !empty($ev['delta'])) {
                            $onDelta((string) $ev['delta']);
                        }
                    }
                }
            });

            $fullText = $transformer->getAccumulatedText();
            $success = true;
            return $fullText;
        } catch (\Throwable $e) {
            if ($e instanceof UpstreamRequestException && $e->getCode() === 429) {
                $this->poolManager->putInCooldown($token);
            }
            throw $e;
        } finally {
            $this->poolManager->release($token, $success, mb_strlen($transformer->getAccumulatedText()));
        }
    }
}
