<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Refatbd\LaravelChatGptMirror\Events\MirrorCompletionCompleted;
use Refatbd\LaravelChatGptMirror\Events\MirrorCompletionFailed;
use Refatbd\LaravelChatGptMirror\Exceptions\TokenPoolExhaustedException;
use Refatbd\LaravelChatGptMirror\Facades\Mirror as MirrorFacade;
use Refatbd\LaravelChatGptMirror\Mirror;
use Refatbd\LaravelChatGptMirror\Models\MirrorMessage;
use Refatbd\LaravelChatGptMirror\Models\MirrorToken;
use Refatbd\LaravelChatGptMirror\Services\ChatGptClient;
use Refatbd\LaravelChatGptMirror\Tests\TestCase;

class PackageAndSaasIntegrationTest extends TestCase
{
    protected function tearDown(): void
    {
        Mirror::resetConfigurationHooks();
        MirrorFacade::clearResolvedInstances();
        parent::tearDown();
    }

    public function test_mirror_facade_resolves_from_container_and_executes_programmatic_chat(): void
    {
        $this->assertInstanceOf(Mirror::class, MirrorFacade::getFacadeRoot());

        MirrorToken::create([
            'name' => 'Facade Test Token',
            'email' => 'facade@example.com',
            'session_token' => 'facade-session-token-123456',
            'access_token' => 'facade-access-token',
            'access_token_expires_at' => now()->addHours(2),
            'status' => 'active',
        ]);

        $client = $this->createMock(ChatGptClient::class);
        $client->method('routeModel')->willReturn(['model' => 'gpt-4o', 'gizmoId' => null, 'private' => null]);
        $client->method('buildConversationPayload')->willReturn(['model' => 'gpt-4o', 'messages' => []]);
        $client->expects($this->once())
            ->method('streamConversation')
            ->willReturnCallback(function ($token, $payload, callable $onEvent): void {
                $patch = json_encode([
                    'v' => [
                        'message' => [
                            'id' => 'msg-facade-1',
                            'author' => ['role' => 'assistant'],
                            'content' => ['content_type' => 'text', 'parts' => ['Hello from Mirror Facade!']],
                            'status' => 'finished_successfully',
                        ],
                        'conversation_id' => 'up-conv-facade',
                    ],
                ]);
                $onEvent('delta', (string) $patch);
            });
        $this->app->instance(ChatGptClient::class, $client);
        // Rebind Mirror singleton cleanly and flush Facade static cache before exercising mocked chat().
        $this->app->forgetInstance(Mirror::class);
        $this->app->forgetInstance('laravel-chatgpt-mirror');
        MirrorFacade::clearResolvedInstance('laravel-chatgpt-mirror');

        $streamedDeltas = [];
        $reply = MirrorFacade::chat(
            [['role' => 'user', 'content' => 'Hi']],
            'gpt-4o',
            true,
            static function (string $delta) use (&$streamedDeltas): void {
                $streamedDeltas[] = $delta;
            }
        );

        $this->assertSame('Hello from Mirror Facade!', $reply);
        $this->assertSame(['Hello from Mirror Facade!'], $streamedDeltas);
        $this->assertSame(0, MirrorToken::first()->active_requests);
    }

    public function test_mirror_facade_chat_throws_when_token_pool_is_empty(): void
    {
        $this->expectException(TokenPoolExhaustedException::class);
        MirrorFacade::chat([['role' => 'user', 'content' => 'Hello']]);
    }

    public function test_custom_saas_api_key_authenticator_and_usage_events_work_for_sync_and_stream(): void
    {
        Event::fake([MirrorCompletionCompleted::class, MirrorCompletionFailed::class]);

        // Bind mocked ChatGptClient before dispatching the first HTTP request so Route::$controller captures the mock.
        $client = $this->createMock(ChatGptClient::class);
        $client->method('routeModel')->willReturn(['model' => 'gpt-4o', 'gizmoId' => null, 'private' => null]);
        $client->method('prepareFollowup')->willReturn('conduit-saas');
        $client->method('buildConversationPayload')->willReturn(['model' => 'gpt-4o', 'messages' => []]);
        $client->expects($this->exactly(2))
            ->method('streamConversation')
            ->willReturnCallback(function ($token, $payload, callable $onEvent): void {
                $patch = json_encode([
                    'v' => [
                        'message' => [
                            'id' => 'msg-saas-' . mt_rand(100, 999),
                            'author' => ['role' => 'assistant'],
                            'content' => ['content_type' => 'text', 'parts' => ['Metered SaaS answer']],
                            'status' => 'finished_successfully',
                        ],
                        'conversation_id' => 'up-conv-saas-' . mt_rand(100, 999),
                    ],
                ]);
                $onEvent('delta', (string) $patch);
            });
        $this->app->instance(ChatGptClient::class, $client);

        Config::set('mirror.api_key', '');

        Mirror::authenticateApiKeyUsing(function (string $token, Request $request): mixed {
            if ($token === 'sk-tenant-99') {
                return ['tenant_id' => 99, 'plan' => 'enterprise'];
            }
            return false;
        });

        // Unauthenticated or invalid SaaS key must be rejected even if MIRROR_API_KEY is empty.
        $this->postJson('/v1/chat/completions', [
            'model' => 'gpt-4o',
            'messages' => [['role' => 'user', 'content' => 'Hi']],
        ])->assertStatus(401);

        $this->withHeader('Authorization', 'Bearer sk-wrong-key')
            ->postJson('/v1/chat/completions', [
                'model' => 'gpt-4o',
                'messages' => [['role' => 'user', 'content' => 'Hi']],
            ])->assertStatus(401);

        // Pool exhaustion emits MirrorCompletionFailed with authContext.
        $this->withHeader('Authorization', 'Bearer sk-tenant-99')
            ->postJson('/v1/chat/completions', [
                'model' => 'gpt-4o',
                'messages' => [['role' => 'user', 'content' => 'No tokens yet']],
            ])->assertStatus(503);

        Event::assertDispatched(MirrorCompletionFailed::class, function (MirrorCompletionFailed $event): bool {
            return $event->statusCode === 503
                && $event->category === 'no_healthy_token'
                && ($event->authContext['tenant_id'] ?? null) === 99;
        });

        // Seed active token and verify both sync and stream completion metering events.
        MirrorToken::create([
            'name' => 'SaaS Worker 1',
            'email' => 'worker@example.com',
            'session_token' => 'saas-session-token-123456',
            'access_token' => 'saas-access-token',
            'access_token_expires_at' => now()->addHours(2),
            'status' => 'active',
        ]);

        $sync = $this->withHeader('Authorization', 'Bearer sk-tenant-99')
            ->postJson('/v1/chat/completions', [
                'model' => 'gpt-4o',
                'stream' => false,
                'messages' => [['role' => 'user', 'content' => 'Explain SaaS metering']],
            ]);
        $sync->assertStatus(200);

        $stream = $this->withHeader('Authorization', 'Bearer sk-tenant-99')
            ->postJson('/v1/chat/completions', [
                'model' => 'gpt-4o',
                'stream' => true,
                'messages' => [['role' => 'user', 'content' => 'Stream SaaS answer']],
            ]);
        $stream->assertStatus(200);
        $stream->streamedContent();

        Event::assertDispatchedTimes(MirrorCompletionCompleted::class, 2);
        Event::assertDispatched(MirrorCompletionCompleted::class, function (MirrorCompletionCompleted $event): bool {
            return $event->endpoint === 'chat.completions'
                && ($event->authContext['tenant_id'] ?? null) === 99
                && ($event->authContext['plan'] ?? null) === 'enterprise'
                && $event->totalTokens > 0;
        });
    }

    public function test_saas_admin_authorizer_blocks_unauthorized_admin_access_without_blocking_public_api(): void
    {
        Mirror::authorizeAdminUsing(fn (Request $request): bool => $request->header('X-Admin-User') === 'superadmin');

        $denied = $this->get('/mirror/admin');
        $denied->assertStatus(403);
        $denied->assertCookieMissing('mirror_control');

        $this->withHeader('Authorization', 'Bearer test-mirror-key-12345')
            ->getJson('/api/diagnostics')
            ->assertStatus(403);

        $this->withHeader('Authorization', 'Bearer test-mirror-key-12345')
            ->getJson('/api/settings/runtime')
            ->assertStatus(403);

        // Public OpenAI API still works normally for SaaS API callers.
        $this->withHeader('Authorization', 'Bearer test-mirror-key-12345')
            ->getJson('/v1/models')
            ->assertOk();

        // Admin access passes once authorizer returns true.
        $this->withHeader('X-Admin-User', 'superadmin')
            ->get('/mirror/admin')
            ->assertOk();
    }

    public function test_saas_feature_switches_and_prefixed_browser_proxy_avoid_host_route_collisions(): void
    {
        Config::set('mirror.features.web_proxy', false);
        Config::set('mirror.features.root_aliases', false);

        // Reload only routes in an isolated router test using the package route files.
        $router = app('router');
        $freshRoutes = new \Illuminate\Routing\RouteCollection();
        $router->setRoutes($freshRoutes);

        require __DIR__ . '/../../routes/api.php';
        require __DIR__ . '/../../routes/web.php';

        // Host SaaS routes '/', '/admin', '/help', '/about', '/pricing' are NOT hijacked!
        foreach (['/', '/pricing', '/admin', '/help', '/about'] as $hostPath) {
            try {
                $matched = $freshRoutes->match(Request::create($hostPath, 'GET'));
                $this->fail("Route {$hostPath} should not be registered by Mirror when web_proxy & root_aliases are false, matched: " . $matched->uri());
            } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
                $this->assertTrue(true);
            }
        }

        // OpenAI API & /mirror/admin remain active cleanly.
        $this->assertSame('v1/chat/completions', $freshRoutes->match(Request::create('/v1/chat/completions', 'POST'))->uri());
        $this->assertSame('mirror/admin', $freshRoutes->match(Request::create('/mirror/admin', 'GET'))->uri());
    }

    public function test_prefixed_browser_proxy_scopes_catch_all_without_stealing_root_paths(): void
    {
        Config::set('mirror.features.web_proxy', true);
        Config::set('mirror.features.root_aliases', false);
        Config::set('mirror.routes.browser_prefix', 'chatgpt');

        $router = app('router');
        $freshRoutes = new \Illuminate\Routing\RouteCollection();
        $router->setRoutes($freshRoutes);

        require __DIR__ . '/../../routes/web.php';

        // Prefixed path matches browser proxy, while root /pricing stays untouched.
        $this->assertSame('chatgpt/{path}', $freshRoutes->match(Request::create('/chatgpt/some/chat', 'GET'))->uri());
        try {
            $freshRoutes->match(Request::create('/pricing', 'GET'));
            $this->fail('Root /pricing should not be hijacked when browser_prefix is set.');
        } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
            $this->assertTrue(true);
        }
    }

    public function test_mysql_strict_safe_nullable_message_content_and_static_registration_toggles(): void
    {
        // Nullable content ensures MySQL 8 strict mode compatibility without TEXT default literals.
        $msg = MirrorMessage::create([
            'id' => 'msg-null-content',
            'conversation_public_id' => 'conv-test-null',
            'role' => 'user',
            'content' => null,
            'status' => 'done',
        ]);
        $this->assertSame('', $msg->toPlaygroundArray()['content']);
        $this->assertTrue(Schema::hasTable('mirror_messages'));

        Mirror::ignoreMigrations();
        Mirror::ignoreRoutes();
        $this->assertFalse(Mirror::$runsMigrations);
        $this->assertFalse(Mirror::$registersRoutes);

        Mirror::resetConfigurationHooks();
        $this->assertTrue(Mirror::$runsMigrations);
        $this->assertTrue(Mirror::$registersRoutes);
    }
}
