<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Yaml\Yaml;

/**
 * Generates an OpenAPI 3.1.0 specification and serves the local Swagger UI (/mirror/api-docs).
 */
class ApiDocsController extends Controller
{
    public function openapi(Request $request): Response
    {
        $format = strtolower((string) $request->query('format', 'json'));
        if (!in_array($format, ['json', 'yaml'], true)) {
            return response()->json([
                'error' => [
                    'message' => "Unsupported format '{$format}'. Supported values are 'json' and 'yaml'.",
                    'type' => 'invalid_request_error',
                    'code' => 'invalid_format',
                ],
            ], 400);
        }

        $doc = $this->buildOpenApiDocument($request->getSchemeAndHttpHost());

        if ($format === 'yaml') {
            $yaml = class_exists(Yaml::class)
                ? Yaml::dump($doc, 10, 2)
                : json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            return response((string) $yaml, 200, [
                'Content-Type' => 'application/yaml; charset=utf-8',
                'Cache-Control' => 'no-store',
            ]);
        }

        return response()->json($doc, 200, ['Cache-Control' => 'no-store'], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    public function ui(): Response
    {
        $html = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <title>Laravel ChatGPT Mirror API Docs</title>
    <link rel="stylesheet" type="text/css" href="/mirror/api-docs/swagger-ui.css" />
    <link rel="stylesheet" type="text/css" href="/mirror/api-docs/index.css" />
    <link rel="icon" type="image/png" href="/mirror/api-docs/favicon-32x32.png" sizes="32x32" />
    <style>
      .swagger-ui .topbar { display: none; }
    </style>
  </head>
  <body>
    <div id="swagger-ui"></div>
    <script src="/mirror/api-docs/swagger-ui-bundle.js" charset="UTF-8"></script>
    <script src="/mirror/api-docs/swagger-ui-standalone-preset.js" charset="UTF-8"></script>
    <script>
      window.onload = function () {
        window.ui = SwaggerUIBundle({
          url: '/mirror/openapi',
          dom_id: '#swagger-ui',
          deepLinking: true,
          presets: [
            SwaggerUIBundle.presets.apis,
            SwaggerUIStandalonePreset
          ],
          plugins: [
            SwaggerUIBundle.plugins.DownloadUrl
          ],
          layout: 'StandaloneLayout'
        });
      };
    </script>
  </body>
</html>
HTML;

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function asset(string $file): Response
    {
        $baseDir = realpath(__DIR__ . '/../../resources/web/swagger-ui');
        $target = realpath($baseDir . '/' . basename($file));

        if (!$baseDir || !$target || !str_starts_with($target, $baseDir) || !is_file($target)) {
            return response('Swagger UI asset not found.', 404);
        }

        $ext = strtolower(pathinfo($target, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'css' => 'text/css; charset=utf-8',
            'js' => 'application/javascript; charset=utf-8',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'json' => 'application/json',
            'html' => 'text/html; charset=utf-8',
            default => 'application/octet-stream',
        };

        return response((string) file_get_contents($target), 200, [
            'Content-Type' => $mime,
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildOpenApiDocument(string $serverUrl): array
    {
        $openaiPrefix = '/' . trim((string) config('mirror.routes.openai_prefix', 'v1'), '/');
        $adminPrefix = '/' . trim((string) config('mirror.routes.admin_prefix', 'mirror/admin'), '/');

        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'Mirror API',
                'version' => '2.1.0',
                'description' => 'Local ChatGPT Web Mirror in Laravel: OpenAI-compatible gateway (/v1/chat/completions, /v1/responses, /v1/models), Sentinel PoW + Conduit, native /api/chat & /api/conversations/*, file uploads & signed /api/asset-content links, binary codecs (/api/convert/*), decoder challenges, and transparent ChatGPT web proxy.',
            ],
            'servers' => [['url' => $serverUrl, 'description' => 'Active Laravel Mirror instance']],
            'tags' => [
                ['name' => 'OpenAI Gateway', 'description' => 'Drop-in OpenAI-compatible endpoints (/v1/*)'],
                ['name' => 'Mirror Chat & Models', 'description' => 'Native Playground & UI APIs (/api/chat, /api/models, /api/gpts)'],
                ['name' => 'Conversations & Assets', 'description' => 'History search, branching, export, file uploads, signed asset tickets'],
                ['name' => 'Conversions & Challenges', 'description' => 'LoaF, PngSpeak, gptgif, gptgif-v4, GIF89a & decoder challenges'],
                ['name' => 'Health & Sessions', 'description' => 'Health, diagnostics, capabilities, session pool management'],
            ],
            'components' => [
                'securitySchemes' => [
                    'mirrorApiKey' => ['type' => 'http', 'scheme' => 'bearer'],
                    'BearerAuth' => ['type' => 'http', 'scheme' => 'bearer'],
                ],
                'schemas' => [
                    'ChatMessage' => [
                        'type' => 'object',
                        'required' => ['role', 'content'],
                        'properties' => [
                            'role' => ['type' => 'string', 'enum' => ['system', 'developer', 'user', 'assistant']],
                            'content' => ['type' => ['string', 'array']],
                        ],
                    ],
                    'ChatCompletionRequest' => [
                        'type' => 'object',
                        'required' => ['model', 'messages'],
                        'properties' => [
                            'model' => ['type' => 'string', 'default' => 'auto'],
                            'messages' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/ChatMessage']],
                            'stream' => ['type' => 'boolean', 'default' => false],
                            'tools' => ['type' => 'array', 'items' => ['type' => 'object']],
                            'tool_choice' => ['type' => ['string', 'object']],
                            'metadata' => ['type' => 'object'],
                        ],
                    ],
                    'ResponsesRequest' => [
                        'type' => 'object',
                        'required' => ['model', 'input'],
                        'properties' => [
                            'model' => ['type' => 'string', 'default' => 'auto'],
                            'input' => ['type' => ['string', 'array']],
                            'instructions' => ['type' => 'string'],
                            'stream' => ['type' => 'boolean', 'default' => false],
                            'metadata' => ['type' => 'object'],
                        ],
                    ],
                ],
            ],
            'paths' => [
                "{$openaiPrefix}/models" => [
                    'get' => ['tags' => ['OpenAI Gateway'], 'summary' => 'List available models', 'security' => [['BearerAuth' => []]], 'responses' => ['200' => ['description' => 'Model list']]],
                ],
                "{$openaiPrefix}/chat/completions" => [
                    'post' => [
                        'tags' => ['OpenAI Gateway'],
                        'summary' => 'Create Chat Completion (Sentinel PoW + Conduit + SSE/Tools/RichOutput)',
                        'security' => [['BearerAuth' => []]],
                        'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ChatCompletionRequest']]]],
                        'responses' => ['200' => ['description' => 'Completion response or SSE stream']],
                    ],
                ],
                "{$openaiPrefix}/responses" => [
                    'post' => [
                        'tags' => ['OpenAI Gateway'],
                        'summary' => 'Create Response (OpenAI Responses API JSON & SSE events)',
                        'security' => [['BearerAuth' => []]],
                        'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ResponsesRequest']]]],
                        'responses' => ['200' => ['description' => 'Responses API object or event stream']],
                    ],
                ],
                "{$openaiPrefix}/capabilities" => [
                    'get' => ['tags' => ['Health & Sessions'], 'summary' => 'Gateway feature capabilities matrix', 'responses' => ['200' => ['description' => 'Capabilities']]],
                ],
                '/api/health' => ['get' => ['tags' => ['Health & Sessions'], 'summary' => 'Liveness health check', 'responses' => ['200' => ['description' => 'Healthy']]]],
                '/api/diagnostics' => ['get' => ['tags' => ['Health & Sessions'], 'summary' => 'Deep pool & failure diagnostics', 'responses' => ['200' => ['description' => 'Diagnostics JSON']]]],
                '/api/session' => [
                    'get' => ['tags' => ['Health & Sessions'], 'summary' => 'Session status', 'responses' => ['200' => ['description' => 'Status']]],
                    'post' => ['tags' => ['Health & Sessions'], 'summary' => 'Verify and store sessionToken (+ optional turnstileToken)', 'responses' => ['200' => ['description' => 'Saved']]],
                    'delete' => ['tags' => ['Health & Sessions'], 'summary' => 'Clear sessions', 'responses' => ['200' => ['description' => 'Cleared']]],
                ],
                '/api/models' => ['get' => ['tags' => ['Mirror Chat & Models'], 'summary' => 'Normalized ChatGPT models', 'responses' => ['200' => ['description' => 'Array of models']]]],
                '/api/gpts' => ['get' => ['tags' => ['Mirror Chat & Models'], 'summary' => 'Custom GPTs & Projects', 'responses' => ['200' => ['description' => 'Array of GPTs']]]],
                '/api/chat' => ['post' => ['tags' => ['Mirror Chat & Models'], 'summary' => 'Native Playground SSE chat', 'responses' => ['200' => ['description' => 'SSE stream']]]],
                '/api/conversations' => [
                    'get' => ['tags' => ['Conversations & Assets'], 'summary' => 'Paginated conversations (sync=true|false)', 'responses' => ['200' => ['description' => 'List']]],
                    'post' => ['tags' => ['Conversations & Assets'], 'summary' => 'Create new local conversation', 'responses' => ['201' => ['description' => 'Created']]],
                ],
                '/api/conversations/search' => ['get' => ['tags' => ['Conversations & Assets'], 'summary' => 'Search conversations & messages', 'responses' => ['200' => ['description' => 'Matches']]]],
                '/api/conversations/{id}' => [
                    'get' => ['tags' => ['Conversations & Assets'], 'summary' => 'Load conversation & messages', 'responses' => ['200' => ['description' => 'Detail']]],
                    'patch' => ['tags' => ['Conversations & Assets'], 'summary' => 'Update conversation model', 'responses' => ['200' => ['description' => 'Updated']]],
                    'delete' => ['tags' => ['Conversations & Assets'], 'summary' => 'Delete conversation', 'responses' => ['200' => ['description' => 'Deleted']]],
                ],
                '/api/conversations/{id}/stop' => ['post' => ['tags' => ['Conversations & Assets'], 'summary' => 'Abort active generation', 'responses' => ['200' => ['description' => 'Stopped']]]],
                '/api/conversations/{id}/branch' => ['post' => ['tags' => ['Conversations & Assets'], 'summary' => 'Branch from message', 'responses' => ['201' => ['description' => 'Branch created']]]],
                '/api/conversations/{id}/branches' => ['get' => ['tags' => ['Conversations & Assets'], 'summary' => 'List conversation branches', 'responses' => ['200' => ['description' => 'Branches']]]],
                '/api/conversations/{id}/export' => ['get' => ['tags' => ['Conversations & Assets'], 'summary' => 'Export conversation as JSON or Markdown', 'responses' => ['200' => ['description' => 'Export']]]],
                '/api/files' => ['post' => ['tags' => ['Conversations & Assets'], 'summary' => 'Upload attachment to ChatGPT backend', 'responses' => ['201' => ['description' => 'Uploaded metadata']]]],
                '/api/assets' => ['get' => ['tags' => ['Conversations & Assets'], 'summary' => 'Resolve file-service:// or sediment:// pointer', 'responses' => ['302' => ['description' => 'Redirect']]]],
                '/api/asset-content' => ['get' => ['tags' => ['Conversations & Assets'], 'summary' => 'Sealed 7-day preview/download proxy endpoint', 'responses' => ['200' => ['description' => 'Binary asset content']]]],
                '/api/convert/formats' => ['get' => ['tags' => ['Conversions & Challenges'], 'summary' => 'Supported conversion formats', 'responses' => ['200' => ['description' => 'Formats']]]],
                '/api/convert/roundtrip' => ['post' => ['tags' => ['Conversions & Challenges'], 'summary' => 'Encode+decode verification', 'responses' => ['200' => ['description' => 'Result']]]],
                '/api/convert/gauntlet' => ['post' => ['tags' => ['Conversions & Challenges'], 'summary' => 'Run round-trip across all codecs', 'responses' => ['200' => ['description' => 'Results']]]],
                '/api/convert/gpt-prompt' => ['post' => ['tags' => ['Conversions & Challenges'], 'summary' => 'Generate ChatGPT decoding prompt', 'responses' => ['200' => ['description' => 'Prompt payload']]]],
                '/api/decoder-challenges' => ['post' => ['tags' => ['Conversions & Challenges'], 'summary' => 'Create ephemeral decoder challenge', 'responses' => ['200' => ['description' => 'Challenge']]]],
                '/mirror/openapi' => ['get' => ['tags' => ['Health & Sessions'], 'summary' => 'OpenAPI 3.1.0 specification (json|yaml)', 'responses' => ['200' => ['description' => 'OpenAPI document']]]],
                "{$adminPrefix}/api/stats" => ['get' => ['tags' => ['Health & Sessions'], 'summary' => 'Pool & request statistics', 'security' => [['mirrorApiKey' => []]], 'responses' => ['200' => ['description' => 'Pool stats']]]],
            ],
        ];
    }
}
