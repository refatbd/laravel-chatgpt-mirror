<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Symfony\Component\Yaml\Yaml;

class ApiDocsController extends Controller
{
    /**
     * Serve the interactive Swagger UI documentation page.
     */
    public function ui(): Response
    {
        $html = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Mirror API Documentation — Swagger UI</title>
    <link rel="stylesheet" type="text/css" href="/mirror/api-docs/swagger-ui.css" />
    <link rel="stylesheet" type="text/css" href="/mirror/api-docs/index.css" />
    <link rel="icon" type="image/png" href="/mirror/api-docs/favicon-32x32.png" sizes="32x32" />
    <link rel="icon" type="image/png" href="/mirror/api-docs/favicon-16x16.png" sizes="16x16" />
  </head>
  <body>
    <div id="swagger-ui"></div>
    <script src="/mirror/api-docs/swagger-ui-bundle.js" charset="UTF-8"></script>
    <script src="/mirror/api-docs/swagger-ui-standalone-preset.js" charset="UTF-8"></script>
    <script>
      window.onload = function () {
        window.ui = SwaggerUIBundle({
          url: "/mirror/openapi",
          dom_id: "#swagger-ui",
          deepLinking: true,
          presets: [
            SwaggerUIBundle.presets.apis,
            SwaggerUIStandalonePreset
          ],
          plugins: [
            SwaggerUIBundle.plugins.DownloadUrl
          ],
          layout: "StandaloneLayout",
          persistAuthorization: true,
          displayRequestDuration: true,
          filter: true,
          oauth2RedirectUrl: window.location.origin + "/mirror/api-docs/oauth2-redirect.html"
        });
      };
    </script>
  </body>
</html>
HTML;

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-cache, private',
        ]);
    }

    /**
     * Serve bundled local Swagger UI static assets safely.
     */
    public function asset(string $file): Response
    {
        $baseDir = realpath(__DIR__ . '/../../resources/web/swagger-ui');
        if ($baseDir === false) {
            abort(404, 'Swagger UI assets directory not found.');
        }

        $safeName = basename($file);
        $targetPath = realpath($baseDir . DIRECTORY_SEPARATOR . $safeName);

        if ($targetPath === false || !str_starts_with($targetPath, $baseDir) || !is_file($targetPath)) {
            abort(404, 'Swagger UI asset not found.');
        }

        $ext = strtolower(pathinfo($safeName, PATHINFO_EXTENSION));
        $contentType = match ($ext) {
            'js' => 'application/javascript; charset=utf-8',
            'css' => 'text/css; charset=utf-8',
            'html' => 'text/html; charset=utf-8',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'json' => 'application/json; charset=utf-8',
            'map' => 'application/json; charset=utf-8',
            default => 'application/octet-stream',
        };

        return response((string) file_get_contents($targetPath), 200, [
            'Content-Type' => $contentType,
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    /**
     * Serve the full OpenAPI 3.1.0 specification in JSON (default) or YAML (?format=yaml).
     */
    public function openapi(Request $request)
    {
        $format = strtolower((string) $request->query('format', 'json'));

        if (!in_array($format, ['json', 'yaml'], true)) {
            return response()->json([
                'error' => [
                    'message' => 'Unsupported format. Expected "json" or "yaml".',
                    'type' => 'invalid_request_error',
                    'code' => 'invalid_format',
                ],
            ], 400);
        }

        $document = $this->buildOpenApiDocument();

        if ($format === 'yaml') {
            return response($this->encodeYaml($document), 200, [
                'Content-Type' => 'application/yaml; charset=utf-8',
                'Cache-Control' => 'no-cache, private',
            ]);
        }

        return response()->json(
            $document,
            200,
            ['Cache-Control' => 'no-cache, private'],
            JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        );
    }

    /**
     * Build the complete OpenAPI 3.1.0 document describing implemented routes.
     */
    public function buildOpenApiDocument(): array
    {
        $openAiPrefix = '/' . ltrim((string) config('mirror.prefix.openai', 'v1'), '/');

        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'Mirror API',
                'version' => '1.0.0',
                'description' => 'Laravel ChatGPT Mirror exposes an OpenAI-compatible API surface under '
                    . $openAiPrefix
                    . ' (backed by a multi-token least-busy pool), Mirror operational & Playground endpoints under /api, '
                    . 'and pool monitoring endpoints under /mirror/admin. Pass ?format=yaml on /mirror/openapi for YAML output.',
            ],
            'servers' => [
                ['url' => '/', 'description' => 'This Mirror instance'],
            ],
            'tags' => [
                [
                    'name' => 'OpenAI-compatible',
                    'description' => 'Drop-in OpenAI-compatible API endpoints backed by the ChatGPT token pool.',
                ],
                [
                    'name' => 'Mirror',
                    'description' => 'Health, diagnostics, session lifecycle, conversation history, Playground settings, and OpenAPI schema.',
                ],
                [
                    'name' => 'Admin',
                    'description' => 'Token pool runtime statistics.',
                ],
            ],
            'security' => [
                ['mirrorApiKey' => []],
            ],
            'paths' => array_merge(
                $this->openAiPaths($openAiPrefix),
                $this->mirrorPaths(),
                $this->adminPaths()
            ),
            'components' => [
                'securitySchemes' => [
                    'mirrorApiKey' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'description' => 'Configured Mirror API key (MIRROR_API_KEY). Same-origin browser tabs opened on Mirror (e.g. Playground or Swagger UI) are automatically permitted.',
                    ],
                ],
                'schemas' => $this->componentSchemas(),
            ],
        ];
    }

    /**
     * OpenAI-compatible /v1/* path definitions.
     */
    protected function openAiPaths(string $prefix): array
    {
        return [
            "{$prefix}/models" => [
                'get' => [
                    'summary' => 'List available models',
                    'description' => 'Returns available ChatGPT model descriptors in OpenAI list format.',
                    'tags' => ['OpenAI-compatible'],
                    'responses' => [
                        '200' => [
                            'description' => 'OK',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/ModelsResponse'],
                                ],
                            ],
                        ],
                        '401' => $this->errorResponse('Missing or invalid Mirror API key'),
                    ],
                ],
            ],
            "{$prefix}/chat/completions" => [
                'post' => [
                    'summary' => 'Create a chat completion',
                    'description' => 'OpenAI Chat Completions endpoint backed by the least-busy ChatGPT token pool. Supports synchronous JSON responses and real-time Server-Sent Events when stream=true.',
                    'tags' => ['OpenAI-compatible'],
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => ['$ref' => '#/components/schemas/CompletionRequest'],
                            ],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Completion JSON object, or text/event-stream SSE chunks when stream=true.',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/CompletionResponse'],
                                ],
                                'text/event-stream' => [
                                    'schema' => ['type' => 'string'],
                                ],
                            ],
                        ],
                        '401' => $this->errorResponse('Missing or invalid Mirror API key'),
                        '422' => $this->errorResponse('Validation error in request payload'),
                        '503' => $this->errorResponse('All ChatGPT tokens in pool are exhausted or in cooldown'),
                    ],
                ],
            ],
            "{$prefix}/responses" => [
                'post' => [
                    'summary' => 'Create a text response',
                    'description' => 'OpenAI Responses API adapter backed by the Mirror Chat Completions pipeline. Accepts string or structured array input plus optional system instructions.',
                    'tags' => ['OpenAI-compatible'],
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => ['$ref' => '#/components/schemas/ResponsesRequest'],
                            ],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Completion JSON object or text/event-stream SSE stream when stream=true.',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/CompletionResponse'],
                                ],
                                'text/event-stream' => [
                                    'schema' => ['type' => 'string'],
                                ],
                            ],
                        ],
                        '401' => $this->errorResponse('Missing or invalid Mirror API key'),
                        '422' => $this->errorResponse('Validation error in converted chat payload'),
                    ],
                ],
            ],
            "{$prefix}/capabilities" => [
                'get' => [
                    'summary' => 'Describe supported OpenAI-compatible capabilities',
                    'description' => 'Machine-readable capability descriptor listing supported routes, request fields, and pool features.',
                    'tags' => ['OpenAI-compatible'],
                    'responses' => [
                        '200' => [
                            'description' => 'OK',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/CapabilitiesResponse'],
                                ],
                            ],
                        ],
                        '401' => $this->errorResponse('Missing or invalid Mirror API key'),
                    ],
                ],
            ],
        ];
    }

    /**
     * Native Mirror /api/* and /mirror/openapi path definitions.
     */
    protected function mirrorPaths(): array
    {
        return [
            '/api/health' => [
                'get' => [
                    'summary' => 'Health check',
                    'description' => 'Reports database connectivity, configured session status, active healthy token count, in-flight requests, and upstream URL.',
                    'tags' => ['Mirror'],
                    'security' => [],
                    'responses' => [
                        '200' => [
                            'description' => 'OK',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/HealthResponse'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '/api/diagnostics' => [
                'get' => [
                    'summary' => 'Server diagnostics snapshot',
                    'description' => 'Returns build versions, API key status, database storage details, token pool metrics, and Mirror configuration prefixes.',
                    'tags' => ['Mirror'],
                    'responses' => [
                        '200' => [
                            'description' => 'OK',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/DiagnosticsResponse'],
                                ],
                            ],
                        ],
                        '401' => $this->errorResponse('Missing or invalid Mirror API key'),
                    ],
                ],
            ],
            '/api/session' => [
                'get' => [
                    'summary' => 'Check active ChatGPT session status',
                    'tags' => ['Mirror'],
                    'responses' => [
                        '200' => [
                            'description' => 'OK',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/SessionStatusResponse'],
                                ],
                            ],
                        ],
                        '401' => $this->errorResponse('Missing or invalid Mirror API key'),
                    ],
                ],
                'post' => [
                    'summary' => 'Connect or update a ChatGPT session token',
                    'tags' => ['Mirror'],
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => ['$ref' => '#/components/schemas/SessionSetRequest'],
                            ],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Session stored and verified',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/OkResponse'],
                                ],
                            ],
                        ],
                        '400' => $this->errorResponse('Invalid or missing sessionToken'),
                        '401' => $this->errorResponse('Missing or invalid Mirror API key'),
                    ],
                ],
                'delete' => [
                    'summary' => 'Disconnect stored ChatGPT session tokens',
                    'tags' => ['Mirror'],
                    'responses' => [
                        '200' => [
                            'description' => 'OK',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/OkResponse'],
                                ],
                            ],
                        ],
                        '401' => $this->errorResponse('Missing or invalid Mirror API key'),
                    ],
                ],
            ],
            '/api/conversations' => [
                'get' => [
                    'summary' => 'List locally tracked conversations',
                    'tags' => ['Mirror'],
                    'parameters' => [
                        [
                            'name' => 'limit',
                            'in' => 'query',
                            'required' => false,
                            'schema' => ['type' => 'integer', 'default' => 50, 'minimum' => 1],
                        ],
                        [
                            'name' => 'offset',
                            'in' => 'query',
                            'required' => false,
                            'schema' => ['type' => 'integer', 'default' => 0, 'minimum' => 0],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'OK',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/ConversationsResponse'],
                                ],
                            ],
                        ],
                        '401' => $this->errorResponse('Missing or invalid Mirror API key'),
                    ],
                ],
            ],
            '/api/conversations/{id}' => [
                'get' => [
                    'summary' => 'Fetch a single conversation metadata record',
                    'tags' => ['Mirror'],
                    'parameters' => [
                        [
                            'name' => 'id',
                            'in' => 'path',
                            'required' => true,
                            'schema' => ['type' => 'string'],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'OK',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/ConversationDetailResponse'],
                                ],
                            ],
                        ],
                        '401' => $this->errorResponse('Missing or invalid Mirror API key'),
                    ],
                ],
            ],
            '/api/settings/default-system-instructions' => [
                'get' => [
                    'summary' => 'Get Playground default system instructions',
                    'tags' => ['Mirror'],
                    'responses' => [
                        '200' => [
                            'description' => 'OK',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/DefaultInstructionsPayload'],
                                ],
                            ],
                        ],
                    ],
                ],
                'put' => [
                    'summary' => 'Update Playground default system instructions',
                    'tags' => ['Mirror'],
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => ['$ref' => '#/components/schemas/DefaultInstructionsPayload'],
                            ],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'OK',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/DefaultInstructionsPayload'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '/api/settings/hotkeys' => [
                'get' => [
                    'summary' => 'Get Playground keyboard shortcut preferences',
                    'tags' => ['Mirror'],
                    'responses' => [
                        '200' => [
                            'description' => 'OK',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/HotkeysPayload'],
                                ],
                            ],
                        ],
                    ],
                ],
                'put' => [
                    'summary' => 'Update Playground keyboard shortcut preferences',
                    'tags' => ['Mirror'],
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => ['$ref' => '#/components/schemas/HotkeysPayload'],
                            ],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'OK',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/HotkeysPayload'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '/mirror/openapi' => [
                'get' => [
                    'summary' => 'Get this OpenAPI 3.1.0 specification',
                    'description' => 'Returns the live OpenAPI 3.1.0 specification in JSON (default) or YAML when ?format=yaml is passed.',
                    'tags' => ['Mirror'],
                    'security' => [],
                    'parameters' => [
                        [
                            'name' => 'format',
                            'in' => 'query',
                            'required' => false,
                            'description' => 'Pass "yaml" for YAML or omit/"json" for JSON.',
                            'schema' => [
                                'type' => 'string',
                                'enum' => ['json', 'yaml'],
                                'default' => 'json',
                            ],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'OK',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'additionalProperties' => true,
                                        'description' => 'This OpenAPI 3.1.0 document as JSON.',
                                    ],
                                ],
                                'application/yaml' => [
                                    'schema' => [
                                        'type' => 'string',
                                        'description' => 'This OpenAPI 3.1.0 document as YAML.',
                                    ],
                                ],
                            ],
                        ],
                        '400' => $this->errorResponse('Unsupported format parameter'),
                    ],
                ],
            ],
        ];
    }

    /**
     * Admin monitoring endpoint definitions.
     */
    protected function adminPaths(): array
    {
        return [
            '/mirror/admin/api/stats' => [
                'get' => [
                    'summary' => 'Live multi-token pool statistics',
                    'description' => 'Returns active healthy token counts, cooldown counts, in-flight requests, total served counter, and balancing strategy.',
                    'tags' => ['Admin'],
                    'security' => [],
                    'responses' => [
                        '200' => [
                            'description' => 'OK',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/AdminStatsResponse'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Reusable component schemas referenced by path definitions.
     */
    protected function componentSchemas(): array
    {
        return [
            'ErrorResponse' => [
                'type' => 'object',
                'properties' => [
                    'error' => [
                        'type' => 'object',
                        'properties' => [
                            'message' => ['type' => 'string'],
                            'type' => ['type' => 'string'],
                            'code' => ['type' => ['string', 'integer', 'null']],
                        ],
                    ],
                ],
            ],
            'OkResponse' => [
                'type' => 'object',
                'properties' => [
                    'ok' => ['type' => 'boolean'],
                ],
                'additionalProperties' => true,
            ],
            'ModelSchema' => [
                'type' => 'object',
                'required' => ['id', 'object', 'created', 'owned_by'],
                'properties' => [
                    'id' => ['type' => 'string', 'example' => 'gpt-4o'],
                    'object' => ['type' => 'string', 'const' => 'model'],
                    'created' => ['type' => 'integer'],
                    'owned_by' => ['type' => 'string'],
                ],
            ],
            'ModelsResponse' => [
                'type' => 'object',
                'required' => ['object', 'data'],
                'properties' => [
                    'object' => ['type' => 'string', 'const' => 'list'],
                    'data' => [
                        'type' => 'array',
                        'items' => ['$ref' => '#/components/schemas/ModelSchema'],
                    ],
                ],
            ],
            'ChatMessage' => [
                'type' => 'object',
                'required' => ['role', 'content'],
                'properties' => [
                    'role' => ['type' => 'string', 'enum' => ['system', 'developer', 'user', 'assistant']],
                    'content' => ['type' => 'string'],
                ],
            ],
            'CompletionRequest' => [
                'type' => 'object',
                'required' => ['messages'],
                'properties' => [
                    'model' => ['type' => 'string', 'default' => 'gpt-4o'],
                    'messages' => [
                        'type' => 'array',
                        'minItems' => 1,
                        'items' => ['$ref' => '#/components/schemas/ChatMessage'],
                    ],
                    'stream' => ['type' => 'boolean', 'default' => false],
                ],
            ],
            'CompletionChoice' => [
                'type' => 'object',
                'properties' => [
                    'index' => ['type' => 'integer'],
                    'message' => [
                        'type' => 'object',
                        'properties' => [
                            'role' => ['type' => 'string', 'const' => 'assistant'],
                            'content' => ['type' => 'string'],
                        ],
                    ],
                    'logprobs' => ['type' => ['object', 'null']],
                    'finish_reason' => ['type' => ['string', 'null']],
                ],
            ],
            'CompletionResponse' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'string'],
                    'object' => ['type' => 'string', 'const' => 'chat.completion'],
                    'created' => ['type' => 'integer'],
                    'model' => ['type' => 'string'],
                    'system_fingerprint' => ['type' => 'string'],
                    'choices' => [
                        'type' => 'array',
                        'items' => ['$ref' => '#/components/schemas/CompletionChoice'],
                    ],
                    'usage' => [
                        'type' => 'object',
                        'properties' => [
                            'prompt_tokens' => ['type' => 'integer'],
                            'completion_tokens' => ['type' => 'integer'],
                            'total_tokens' => ['type' => 'integer'],
                        ],
                    ],
                ],
            ],
            'ResponsesRequest' => [
                'type' => 'object',
                'required' => ['input'],
                'properties' => [
                    'model' => ['type' => 'string', 'default' => 'auto'],
                    'instructions' => ['type' => ['string', 'null']],
                    'input' => [
                        'oneOf' => [
                            ['type' => 'string'],
                            [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'additionalProperties' => true,
                                ],
                            ],
                        ],
                    ],
                    'stream' => ['type' => 'boolean', 'default' => false],
                ],
            ],
            'CapabilitiesResponse' => [
                'type' => 'object',
                'properties' => [
                    'schemaVersion' => ['type' => 'integer'],
                    'routes' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'fields' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'metadataFields' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'supported' => [
                        'type' => 'object',
                        'additionalProperties' => ['type' => 'boolean'],
                    ],
                ],
            ],
            'HealthResponse' => [
                'type' => 'object',
                'properties' => [
                    'ok' => ['type' => 'boolean'],
                    'storage' => ['type' => 'string'],
                    'configured' => ['type' => 'boolean'],
                    'active_tokens' => ['type' => 'integer'],
                    'in_flight' => ['type' => 'integer'],
                    'total_served' => ['type' => 'integer'],
                    'upstream' => ['type' => 'string'],
                ],
            ],
            'DiagnosticsResponse' => [
                'type' => 'object',
                'properties' => [
                    'schemaVersion' => ['type' => 'integer'],
                    'build' => ['type' => 'object', 'additionalProperties' => true],
                    'api' => ['type' => 'object', 'additionalProperties' => true],
                    'storage' => ['type' => 'object', 'additionalProperties' => true],
                    'pool' => ['type' => 'object', 'additionalProperties' => true],
                    'config' => ['type' => 'object', 'additionalProperties' => true],
                ],
            ],
            'SessionStatusResponse' => [
                'type' => 'object',
                'properties' => [
                    'configured' => ['type' => 'boolean'],
                    'total_tokens' => ['type' => 'integer'],
                    'active_tokens' => ['type' => 'integer'],
                    'account' => [
                        'type' => ['object', 'null'],
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'email' => ['type' => ['string', 'null']],
                            'name' => ['type' => ['string', 'null']],
                            'plan' => ['type' => ['string', 'null']],
                        ],
                    ],
                ],
            ],
            'SessionSetRequest' => [
                'type' => 'object',
                'required' => ['sessionToken'],
                'properties' => [
                    'sessionToken' => ['type' => 'string'],
                ],
            ],
            'ConversationSummary' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'string'],
                    'title' => ['type' => 'string'],
                    'model' => ['type' => 'string'],
                    'updatedAt' => ['type' => 'string', 'format' => 'date-time'],
                ],
            ],
            'ConversationsResponse' => [
                'type' => 'object',
                'properties' => [
                    'items' => [
                        'type' => 'array',
                        'items' => ['$ref' => '#/components/schemas/ConversationSummary'],
                    ],
                    'total' => ['type' => 'integer'],
                    'hasMore' => ['type' => 'boolean'],
                ],
            ],
            'ConversationDetailResponse' => [
                'type' => 'object',
                'properties' => [
                    'conversation' => ['type' => 'object', 'additionalProperties' => true],
                    'messages' => ['type' => 'array', 'items' => ['type' => 'object']],
                    'instructions' => ['type' => ['string', 'null']],
                ],
            ],
            'DefaultInstructionsPayload' => [
                'type' => 'object',
                'properties' => [
                    'content' => ['type' => 'string'],
                ],
            ],
            'HotkeysPayload' => [
                'type' => 'object',
                'properties' => [
                    'hotkeys' => ['type' => 'object', 'additionalProperties' => true],
                ],
            ],
            'AdminStatsResponse' => [
                'type' => 'object',
                'properties' => [
                    'total_tokens' => ['type' => 'integer'],
                    'active_healthy' => ['type' => 'integer'],
                    'in_cooldown' => ['type' => 'integer'],
                    'total_in_flight_requests' => ['type' => 'integer'],
                    'total_requests_served' => ['type' => 'integer'],
                    'strategy' => ['type' => 'string'],
                ],
            ],
        ];
    }

    /**
     * Build a reusable JSON error response entry.
     */
    protected function errorResponse(string $description): array
    {
        return [
            'description' => $description,
            'content' => [
                'application/json' => [
                    'schema' => ['$ref' => '#/components/schemas/ErrorResponse'],
                ],
            ],
        ];
    }

    /**
     * Serialize an OpenAPI document array to YAML using Symfony Yaml or a pure-PHP fallback.
     */
    protected function encodeYaml(array $document): string
    {
        if (class_exists(Yaml::class)) {
            return Yaml::dump(
                $document,
                12,
                2,
                Yaml::DUMP_OBJECT_AS_MAP | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE
            );
        }

        return $this->dumpYamlFallback($document, 0);
    }

    /**
     * Minimal dependency-free YAML serializer fallback.
     */
    protected function dumpYamlFallback(mixed $value, int $indent): string
    {
        $pad = str_repeat('  ', $indent);

        if (!is_array($value)) {
            return $this->formatYamlScalar($value);
        }

        if ($value === []) {
            return '[]';
        }

        $isList = array_is_list($value);
        $lines = [];

        foreach ($value as $k => $v) {
            if ($isList) {
                if (is_array($v) && $v !== []) {
                    $lines[] = $pad . '-';
                    $lines[] = $this->dumpYamlFallback($v, $indent + 1);
                } else {
                    $lines[] = $pad . '- ' . $this->dumpYamlFallback($v, $indent + 1);
                }
                continue;
            }

            $key = preg_match('/^[A-Za-z0-9_.-]+$/', (string) $k) ? (string) $k : json_encode((string) $k);
            if (is_array($v) && $v !== []) {
                $lines[] = $pad . $key . ':';
                $lines[] = $this->dumpYamlFallback($v, $indent + 1);
            } else {
                $lines[] = $pad . $key . ': ' . $this->dumpYamlFallback($v, $indent + 1);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Format a scalar value safely for YAML output.
     */
    protected function formatYamlScalar(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return (string) json_encode((string) $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
