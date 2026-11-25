<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Tests\Feature;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response as Psr7Response;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Request;
use Refatbd\LaravelChatGptMirror\Controllers\BackendApiController;
use Refatbd\LaravelChatGptMirror\Controllers\BrowserProxyController;
use Refatbd\LaravelChatGptMirror\Mirror;
use Refatbd\LaravelChatGptMirror\Models\MirrorToken;
use Refatbd\LaravelChatGptMirror\Services\BrowserPatchManager;
use Refatbd\LaravelChatGptMirror\Services\ChatGptClient;
use Refatbd\LaravelChatGptMirror\Services\MirrorCache;
use Refatbd\LaravelChatGptMirror\Services\SecurityService;
use Refatbd\LaravelChatGptMirror\Services\TokenPoolManager;
use Refatbd\LaravelChatGptMirror\Services\UrlRewriter;
use Refatbd\LaravelChatGptMirror\Tests\TestCase;
use Symfony\Component\HttpFoundation\Response;

class V22SaaSHooksTest extends TestCase
{
    public function test_fingerprinted_cdn_script_is_fetched_once_and_rewritten_per_host(): void
    {
        $history = [];
        $handler = HandlerStack::create(new MockHandler([
            new Psr7Response(200, [
                'Content-Type' => 'application/javascript',
                'Cache-Control' => 'public, max-age=3600',
            ], 'const endpoint = "https://chatgpt.com/backend-api/conversations";'),
        ]));
        $handler->push(Middleware::history($history));
        $this->app->instance(Client::class, new Client(['handler' => $handler]));
        Mirror::resolveWebContextUsing(fn () => ['skip_token_pool' => true]);
        $name = 'async/cdn-qa.' . bin2hex(random_bytes(5)) . '.js';
        foreach (['localhost:8100', 'localhost:8101'] as $host) {
            $request = Request::create("http://{$host}/cdn/v2/assets/{$name}");
            $response = app(BrowserProxyController::class)->proxy($request, "v2/assets/{$name}");
            $this->assertSame(200, $response->getStatusCode());
            $this->assertStringContainsString("http://{$host}/backend-api/conversations", $response->getContent());
        }
        $this->assertCount(1, $history);
    }

    public function test_cdn_module_retry_preserves_other_query_fields_without_caching_an_upstream_error(): void
    {
        $history = [];
        $handler = HandlerStack::create(new MockHandler([
            new Psr7Response(503, ['Content-Type' => 'application/javascript'], 'temporarily unavailable'),
        ]));
        $handler->push(Middleware::history($history));
        $this->app->instance(Client::class, new Client(['handler' => $handler, 'http_errors' => false]));
        Mirror::resolveWebContextUsing(fn () => ['skip_token_pool' => true]);

        $request = Request::create('http://localhost/cdn/v2/assets/unique-module.1234abcd.js?v=2&__codex_import_retry=123-1');
        $response = app(BrowserProxyController::class)->proxy($request, 'v2/assets/unique-module.1234abcd.js');

        $this->assertSame(503, $response->getStatusCode());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame('https://cdn.oaistatic.com/assets/unique-module.1234abcd.js?v=2', (string) $history[0]['request']->getUri());
    }

    public function test_saturated_workspace_exposes_a_retryable_busy_response(): void
    {
        Mirror::resolveWebContextUsing(fn () => ['meta' => ['pool_status' => 'busy']]);

        $this->getJson('/backend-api/gizmos/snorlax/sidebar')
            ->assertStatus(503)
            ->assertHeader('Retry-After', '1')
            ->assertHeader('X-UniGPT-Pool-Status', 'busy')
            ->assertJsonPath('code', 'unigpt_pool_busy');
    }

    public function test_saturated_workspace_exposes_a_retry_page_for_browser_navigation(): void
    {
        Mirror::resolveWebContextUsing(fn () => ['meta' => [
            'fail_on_missing_token' => true,
            'pool_status' => 'busy',
            'pool_busy_wait_seconds' => 12,
        ]]);

        $controller = app(BrowserProxyController::class);
        $request = Request::create('http://localhost/c/sample', server: ['HTTP_ACCEPT' => 'text/html']);
        $page = $controller->proxy($request, 'c/sample');

        $this->assertSame(503, $page->getStatusCode());
        $this->assertSame('1', $page->headers->get('Retry-After'));
        $this->assertSame('busy', $page->headers->get('X-UniGPT-Pool-Status'));
        $this->assertStringContainsString('data-wait="12"', $page->getContent());
        $this->assertStringContainsString('Try again', $page->getContent());

        $request->headers->set('Accept', 'application/json');
        $json = $controller->proxy($request, 'c/sample');

        $this->assertSame(503, $json->getStatusCode());
        $this->assertSame('unigpt_pool_busy', json_decode($json->getContent(), true)['code']);
    }

    public function test_stream_releases_its_slot_when_the_start_observer_throws(): void
    {
        $mock = new MockHandler([new Psr7Response(200, ['Content-Type' => 'text/event-stream'], "data: [DONE]\n\n")]);
        $this->app->instance(BackendApiController::class, new BackendApiController(
            app(TokenPoolManager::class),
            app(ChatGptClient::class),
            app(BrowserPatchManager::class),
            new Client(['handler' => HandlerStack::create($mock)]),
        ));

        $releases = [];
        Mirror::resolveWebContextUsing(function () use (&$releases): array {
            return [
                'skip_token_pool' => true,
                'release' => function (bool $success) use (&$releases): void {
                    $releases[] = $success;
                },
            ];
        });
        Mirror::observeBackendStreamUsing(function (): void {
            throw new \RuntimeException('Fixture observer failed');
        });

        $response = $this->postJson('/backend-api/f/conversation')->assertOk();
        $this->assertNotNull($response->headers->get('Server-Timing'));
        try {
            ($response->baseResponse->getCallback())();
            $this->fail('The start observer should throw.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Fixture observer failed', $error->getMessage());
        }

        $this->assertSame([false], $releases);
    }

    protected function tearDown(): void
    {
        Mirror::resetConfigurationHooks();
        parent::tearDown();
    }

    public function test_session_customizer_masks_upstream_account_identity(): void
    {
        $poolToken = MirrorToken::create([
            'name' => 'Secret Owner Account',
            'email' => 'owner-private@example.com',
            'account_id' => 'acct-real-99',
            'plan_type' => 'pro',
            'session_token' => 'secret-session-jwt',
            'access_token' => 'secret-access-jwt',
            'access_token_expires_at' => now()->addHour(),
            'status' => 'active',
        ]);

        Mirror::resolveWebContextUsing(fn (Request $req) => [
            'token' => $poolToken,
            'meta' => ['user_id' => 42],
        ]);

        Mirror::customizeBrowserSessionUsing(function (array $payload, Request $req, $token, array $ctx): array {
            $payload['user']['name'] = 'UniGPT Member';
            $payload['user']['email'] = 'member42@unigpt.local';
            $payload['user']['id'] = 'unigpt-u-' . ($ctx['meta']['user_id'] ?? '0');
            return $payload;
        });

        $response = $this->getJson('/api/auth/session');
        $response->assertOk()
            ->assertJsonPath('user.name', 'UniGPT Member')
            ->assertJsonPath('user.email', 'member42@unigpt.local')
            ->assertJsonPath('user.id', 'unigpt-u-42')
            ->assertJsonMissing(['email' => 'owner-private@example.com']);

        $this->assertSame(0, MirrorToken::find($poolToken->id)->active_requests);
    }

    public function test_scoped_empty_pool_does_not_leak_global_pool_tokens(): void
    {
        MirrorToken::create([
            'name' => 'Server 1 Token',
            'email' => 'srv1@example.com',
            'session_token' => 'srv1-session',
            'access_token' => 'srv1-access',
            'access_token_expires_at' => now()->addHour(),
            'status' => 'active',
        ]);

        // Simulate Server #2 explicitly returning null token (pool empty for Server #2).
        Mirror::resolveWebContextUsing(fn (Request $req) => [
            'token' => null,
            'skip_token_pool' => false,
            'meta' => ['server_id' => 2],
        ]);

        $this->postJson('/backend-api/conversation', ['messages' => []])
            ->assertStatus(503);
    }

    public function test_browser_proxy_relays_remote_node_and_injects_floating_html(): void
    {
        $history = [];
        $mock = new MockHandler([
            new Psr7Response(
                200,
                ['Content-Type' => 'text/html; charset=utf-8', 'Content-Security-Policy' => "default-src 'self'"],
                '<!DOCTYPE html><html><head><script data-mirror-patch="early">stale();</script></head><body><div data-src="https://remote-node.example.com/assets/app.js">ChatGPT</div></body></html>'
            ),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $guzzle = new Client(['handler' => $stack]);

        $this->app->instance(BrowserProxyController::class, new BrowserProxyController(
            app(BrowserPatchManager::class),
            app(UrlRewriter::class),
            app(TokenPoolManager::class),
            app(SecurityService::class),
            app(MirrorCache::class),
            $guzzle
        ));

        Mirror::resolveWebContextUsing(fn (Request $req) => [
            'upstream_url' => 'https://remote-node.example.com',
            'upstream_headers' => ['X-Relay-Secret' => 'relay-key-77'],
            'skip_token_pool' => true,
            'base_path' => '/w/node-sg',
            'meta' => ['server_id' => 7, 'skip_control_cookie' => true],
        ]);

        Mirror::injectBrowserHtmlUsing(fn (string $html, Request $req, array $ctx) => str_replace(
            '</body>',
            '<div id="unigpt-floating-root" data-server="' . ($ctx['meta']['server_id'] ?? '') . '"></div></body>',
            $html
        ));

        $resp = $this->get('https://unigpt.test/');
        $resp->assertOk();
        $content = $resp->getContent();

        $this->assertStringContainsString('<div id="unigpt-floating-root" data-server="7"></div>', $content);
        $this->assertStringContainsString('https://unigpt.test/w/node-sg/assets/app.js', $content);
        $this->assertStringNotContainsString('stale();', $content);
        $this->assertStringContainsString('window.history', $content);
        $resp->assertCookieMissing('mirror_control');

        $this->assertCount(1, $history);
        $sentRequest = $history[0]['request'];
        $this->assertSame('remote-node.example.com', $sentRequest->getUri()->getHost());
        $this->assertSame('relay-key-77', $sentRequest->getHeaderLine('X-Relay-Secret'));
    }

    public function test_backend_interceptor_response_filter_and_sse_image_accounting(): void
    {
        $token = MirrorToken::create([
            'name' => 'Worker Token',
            'email' => 'worker@example.com',
            'session_token' => 'worker-sess',
            'access_token' => 'worker-acc',
            'access_token_expires_at' => now()->addHour(),
            'status' => 'active',
        ]);

        // Chunked SSE stream where image_gen + conversation_id arrive across split byte chunks + user upload echo must NOT count as image_gen.
        $sseBody = implode("\n\n", [
            'data: {"conversation_id":"conv-user-123","input_message":{"author":{"role":"user"},"content":{"content_type":"multimodal_text","parts":[{"content_type":"image_asset_pointer","asset_pointer":"file-service://file-user-upload"}]}}}',
            'data: {"v":{"message":{"id":"msg-a1","author":{"role":"tool","name":"image_gen.text2im"},"content":{"content_type":"multimodal_text","parts":[{"content_type":"image_asset_pointer","asset_pointer":"file-service://file-gen-img-1","width":1024}]}},"conversation_id":"conv-user-123"},"title":"Cyber City"}',
            'data: {"v":{"message":{"id":"msg-a1","author":{"role":"tool","name":"image_gen.text2im"},"content":{"content_type":"multimodal_text","parts":[{"content_type":"image_asset_pointer","asset_pointer":"file-service://file-gen-img-1","width":1024}]}}}}',
            'data: [DONE]',
            '',
        ]);

        $mock = new MockHandler([
            // 1. Conversation list response filtered for strict privacy
            new Psr7Response(200, ['Content-Type' => 'application/json'], json_encode([
                'items' => [
                    ['id' => 'conv-user-123', 'title' => 'Mine'],
                    ['id' => 'conv-other-999', 'title' => 'Someone Else'],
                ],
                'total' => 2,
            ])),
            // 2. Streaming conversation response
            new Psr7Response(200, ['Content-Type' => 'text/event-stream'], Utils::streamFor($sseBody)),
        ]);

        $guzzle = new Client(['handler' => HandlerStack::create($mock)]);
        $this->app->instance(BackendApiController::class, new BackendApiController(
            app(TokenPoolManager::class),
            app(ChatGptClient::class),
            app(BrowserPatchManager::class),
            $guzzle
        ));

        Mirror::resolveWebContextUsing(fn () => ['token' => $token]);

        Mirror::interceptBackendRequestUsing(function (Request $req, string $path): ?Response {
            if ($path === 'blocked/quota') {
                return response()->json(['error' => 'Daily limit reached'], 429);
            }
            return null;
        });

        Mirror::filterBackendResponseUsing(function (Response $resp, Request $req, string $path): Response {
            if ($path === 'conversations') {
                $data = json_decode((string) $resp->getContent(), true);
                $data['items'] = array_values(array_filter(
                    $data['items'] ?? [],
                    static fn (array $item): bool => $item['id'] === 'conv-user-123'
                ));
                $resp->setContent((string) json_encode($data));
            }
            return $resp;
        });

        $observedEvents = [];
        Mirror::observeBackendStreamUsing(function (string $eventType, array $telemetry) use (&$observedEvents): void {
            $observedEvents[$eventType] = $telemetry;
        });

        // 1. Interceptor short-circuits before hitting upstream mock queue!
        $this->postJson('/backend-api/blocked/quota')->assertStatus(429);

        // 2. Strict conversation list filtering
        $listResp = $this->getJson('/backend-api/conversations');
        $listResp->assertOk();
        $items = $listResp->json('items');
        $this->assertCount(1, $items);
        $this->assertSame('conv-user-123', $items[0]['id']);

        // 3. SSE stream accurately distinguishes generated image (1 unique tool asset) vs user uploaded image!
        $streamResp = $this->postJson('/backend-api/f/conversation', [
            'messages' => [['role' => 'user', 'content' => 'draw a city']],
        ]);
        $streamResp->assertOk();
        $streamResp->streamedContent();

        $this->assertArrayHasKey('completed', $observedEvents);
        $this->assertSame('conv-user-123', $observedEvents['completed']['conversation_id']);
        $this->assertSame('Cyber City', $observedEvents['completed']['title']);
        $this->assertTrue($observedEvents['completed']['saw_done']);
        $this->assertSame(1, $observedEvents['completed']['image_count']);
        $this->assertArrayNotHasKey('file-service://file-user-upload', $observedEvents['completed']['image_asset_ids']);
        $this->assertArrayHasKey('file-service://file-gen-img-1', $observedEvents['completed']['image_asset_ids']);
    }

    public function test_proxy_request_guard_blocks_non_proxy_host_when_registered_and_resets_cleanly(): void
    {
        Mirror::shouldProxyRequestUsing(fn (Request $req): bool => $req->getHost() === 'chat.uniselv.com');

        $this->get('https://unigpt.uniselv.com/')->assertStatus(404);

        Mirror::resetConfigurationHooks();
        $this->assertNull(Mirror::resolveProxyRequestGuard());
        $this->assertNull(Mirror::resolveWebContextResolver());
        $this->assertNull(Mirror::resolveBrowserSessionCustomizer());
        $this->assertNull(Mirror::resolveBackendRequestInterceptor());
        $this->assertNull(Mirror::resolveBackendStreamObserver());
        $this->assertNull(Mirror::resolveBackendResponseFilter());
        $this->assertNull(Mirror::resolveBrowserHtmlInjector());
        $this->assertNull(Mirror::resolveBrowserResponseHeadersFilter());
    }

    public function test_api_proxy_and_public_api_proxy_forward_with_thumbnail_rewrite(): void
    {
        $token = MirrorToken::create([
            'name' => 'API Proxy Token',
            'email' => 'api@example.com',
            'session_token' => 'sess-token-xyz',
            'access_token' => 'acc-token-123',
            'account_id' => 'acct-456',
            'access_token_expires_at' => now()->addHour(),
            'status' => 'active',
        ]);

        $history = [];
        $mock = new MockHandler([
            new Psr7Response(200, ['Content-Type' => 'image/png'], 'fake-png-bytes'),
            new Psr7Response(200, ['Content-Type' => 'image/png'], 'fake-thumbnail-bytes'),
            new Psr7Response(200, ['Content-Type' => 'application/json'], '{"cuts":[]}'),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $guzzle = new Client(['handler' => $stack]);

        $this->app->instance(BackendApiController::class, new BackendApiController(
            app(TokenPoolManager::class),
            app(ChatGptClient::class),
            app(BrowserPatchManager::class),
            $guzzle
        ));

        Mirror::resolveWebContextUsing(fn (Request $req) => [
            'token' => $token,
            'meta' => ['server_id' => 1],
        ]);

        // 1. /api/library/files/{id}/content forwards directly to /api/library/files/{id}/content
        $res1 = $this->get('/api/library/files/lib_test_1/content');
        $res1->assertOk();
        $this->assertSame('fake-png-bytes', $res1->getContent());
        $this->assertSame('/api/library/files/lib_test_1/content', $history[0]['request']->getUri()->getPath());
        $this->assertSame('Bearer acc-token-123', $history[0]['request']->getHeaderLine('Authorization'));
        $this->assertSame('acct-456', $history[0]['request']->getHeaderLine('ChatGPT-Account-ID'));
        $this->assertStringContainsString('__Secure-next-auth.session-token=sess-token-xyz', $history[0]['request']->getHeaderLine('Cookie'));

        // 2. /api/library/files/{id}/thumbnail automatically rewrites to /content to prevent 404
        $res2 = $this->get('/api/library/files/lib_test_2/thumbnail');
        $res2->assertOk();
        $this->assertSame('/api/library/files/lib_test_2/content', $history[1]['request']->getUri()->getPath());

        // 3. /public-api/gizmos/discovery forwards cleanly to /public-api/gizmos/discovery
        $res3 = $this->get('/public-api/gizmos/discovery');
        $res3->assertOk();
        $this->assertSame('/public-api/gizmos/discovery', $history[2]['request']->getUri()->getPath());
    }

    public function test_backend_api_rewrites_upstream_redirect_location_header(): void
    {
        $token = MirrorToken::create([
            'name' => 'Redirect Token',
            'email' => 'redir@example.com',
            'session_token' => 'sess-redir',
            'access_token' => 'acc-redir',
            'access_token_expires_at' => now()->addHour(),
            'status' => 'active',
        ]);

        $mock = new MockHandler([
            new Psr7Response(302, [
                'Location' => 'https://chatgpt.com/backend-api/estuary/content?id=file_999&sig=abc',
            ], ''),
        ]);
        $stack = HandlerStack::create($mock);
        $guzzle = new Client(['handler' => $stack, 'allow_redirects' => false]);

        $this->app->instance(BackendApiController::class, new BackendApiController(
            app(TokenPoolManager::class),
            app(ChatGptClient::class),
            app(BrowserPatchManager::class),
            $guzzle
        ));

        Mirror::resolveWebContextUsing(fn (Request $req) => [
            'token' => $token,
            'meta' => ['server_id' => 1],
        ]);

        $res = $this->get('/backend-api/files/download/test');
        $res->assertStatus(302);
        $location = $res->headers->get('Location');
        $this->assertStringStartsWith('http://localhost/backend-api/estuary/content', $location);
        $this->assertStringNotContainsString('https://chatgpt.com', $location);
    }

    public function test_browser_proxy_keeps_local_session_credentials_out_of_upstream_requests(): void
    {
        config(['session.cookie' => 'test_app_session']);
        $token = new MirrorToken(['session_token' => 'server-session']);
        Mirror::resolveWebContextUsing(fn () => ['token' => $token]);

        $mock = new MockHandler([new Psr7Response(200, ['Content-Type' => 'text/html'], '<html></html>')]);
        $controller = new BrowserProxyController(
            app(BrowserPatchManager::class),
            app(UrlRewriter::class),
            app(TokenPoolManager::class),
            app(SecurityService::class),
            app(MirrorCache::class),
            new Client(['handler' => HandlerStack::create($mock), 'base_uri' => 'https://chatgpt.com']),
        );

        $request = Request::create('http://localhost/chat', server: [
            'HTTP_COOKIE' => 'unigpt_proxy_session=local; test_app_session=local; XSRF-TOKEN=local; '
                . 'remember_web_hash=local; mirror_control=local; __Secure-next-auth.session-token.0=stale; oai-did=fixture',
            'HTTP_REFERER' => 'http://localhost/_unigpt/sso?token=local',
        ]);
        $this->assertSame(200, $controller->proxy($request, 'chat')->getStatusCode());
        $this->assertSame('__Secure-next-auth.session-token=server-session; oai-did=fixture', $mock->getLastRequest()->getHeaderLine('Cookie'));
        $this->assertSame('https://chatgpt.com/', $mock->getLastRequest()->getHeaderLine('Referer'));
    }

    public function test_browser_proxy_preserves_static_prefixes_for_images_and_assets(): void
    {
        $history = [];
        $mock = new MockHandler([
            new Psr7Response(200, ['Content-Type' => 'image/png'], 'png-data'),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $guzzle = new Client(['handler' => $stack]);

        $this->app->instance(BrowserProxyController::class, new BrowserProxyController(
            app(BrowserPatchManager::class),
            app(UrlRewriter::class),
            app(TokenPoolManager::class),
            app(SecurityService::class),
            app(MirrorCache::class),
            $guzzle
        ));

        Mirror::resolveWebContextUsing(fn (Request $req) => [
            'skip_token_pool' => true,
            'meta' => ['server_id' => 1],
        ]);

        $uniq = 'icon_' . bin2hex(random_bytes(6)) . '.png';
        $res = $this->get('/images/ecosystem/apps/appgarden/' . $uniq);
        $res->assertOk();
        $this->assertSame('/images/ecosystem/apps/appgarden/' . $uniq, $history[0]['request']->getUri()->getPath());
    }
}
