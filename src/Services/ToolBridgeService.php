<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Services;

use Illuminate\Support\Str;

/**
 * Emulates OpenAI function-calling (`tools` / `tool_choice`) over ChatGPT Web
 * by encoding available functions in a developer system block and parsing
 * structured tool_call envelopes out of the model reply.
 */
class ToolBridgeService
{
    /**
     * Validate and extract normalized tool-bridge options from a completion payload.
     *
     * @param array<int, array<string, mixed>>|null $tools
     * @param mixed $toolChoice
     * @return array{enabled: bool, mode: string, forcedName: ?string, tools: array<int, array<string, mixed>>}
     */
    public function resolve(?array $tools, mixed $toolChoice = null): array
    {
        if (empty($tools)) {
            return [
                'enabled' => false,
                'mode' => 'none',
                'forcedName' => null,
                'tools' => [],
            ];
        }

        $normalized = [];
        foreach ($tools as $tool) {
            if (!is_array($tool) || ($tool['type'] ?? '') !== 'function' || empty($tool['function']['name'])) {
                continue;
            }
            $normalized[] = [
                'name' => (string) $tool['function']['name'],
                'description' => (string) ($tool['function']['description'] ?? ''),
                'parameters' => is_array($tool['function']['parameters'] ?? null)
                    ? $tool['function']['parameters']
                    : ['type' => 'object', 'properties' => new \stdClass()],
            ];
        }

        if ($normalized === []) {
            return ['enabled' => false, 'mode' => 'none', 'forcedName' => null, 'tools' => []];
        }

        $mode = 'auto';
        $forcedName = null;

        if (is_string($toolChoice)) {
            $mode = in_array($toolChoice, ['none', 'auto', 'required'], true) ? $toolChoice : 'auto';
        } elseif (is_array($toolChoice) && ($toolChoice['type'] ?? '') === 'function' && !empty($toolChoice['function']['name'])) {
            $mode = 'function';
            $forcedName = (string) $toolChoice['function']['name'];
        }

        if ($mode === 'none') {
            return ['enabled' => false, 'mode' => 'none', 'forcedName' => null, 'tools' => []];
        }

        return [
            'enabled' => true,
            'mode' => $mode,
            'forcedName' => $forcedName,
            'tools' => $normalized,
        ];
    }

    /**
     * Build synthetic developer instruction block explaining available tools.
     *
     * @param array{enabled: bool, mode: string, forcedName: ?string, tools: array<int, array<string, mixed>>} $bridge
     */
    public function buildInstructionBlock(array $bridge): string
    {
        if (!$bridge['enabled']) {
            return '';
        }

        $specJson = json_encode($bridge['tools'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $constraint = match ($bridge['mode']) {
            'required' => 'You MUST call at least one of the listed functions instead of replying with plain text.',
            'function' => "You MUST call the function `{$bridge['forcedName']}` and reply ONLY with a valid tool call block.",
            default => 'When calling a tool is appropriate, respond ONLY with the JSON envelope below; otherwise answer the user normally.',
        };

        return <<<PROMPT
[Tool Calling Protocol]
Available functions:
{$specJson}

{$constraint}
If you invoke tools, output valid JSON inside ```mirror_tool_calls``` (or standalone JSON object) with exact schema:
```mirror_tool_calls
{"tool_calls":[{"name":"function_name","arguments":{...}}]}
```
PROMPT;
    }

    /**
     * Parse structured tool_calls from assistant output when tool calling is active.
     *
     * @param array{enabled: bool, mode: string, forcedName: ?string, tools: array<int, array<string, mixed>>} $bridge
     * @return array<int, array{id: string, type: string, function: array{name: string, arguments: string}}>|null
     */
    public function parseToolCalls(string $text, array $bridge): ?array
    {
        if (!$bridge['enabled']) {
            return null;
        }

        $trimmed = trim($text);
        if ($trimmed === '') {
            return null;
        }

        $jsonCandidate = null;
        if (preg_match('/```(?:mirror_tool_calls|json)?\s*(\{[\s\S]*?"tool_calls"[\s\S]*?\})\s*```/', $trimmed, $match)) {
            $jsonCandidate = $match[1];
        } elseif (str_starts_with($trimmed, '{') && str_contains($trimmed, '"tool_calls"')) {
            $jsonCandidate = $trimmed;
        }

        if ($jsonCandidate === null) {
            return null;
        }

        $decoded = json_decode($jsonCandidate, true);
        if (!is_array($decoded) || empty($decoded['tool_calls']) || !is_array($decoded['tool_calls'])) {
            return null;
        }

        $allowedNames = array_column($bridge['tools'], 'name');
        $calls = [];

        foreach ($decoded['tool_calls'] as $item) {
            if (!is_array($item) || empty($item['name']) || !in_array($item['name'], $allowedNames, true)) {
                continue;
            }
            $args = $item['arguments'] ?? [];
            $argsJson = is_string($args)
                ? $args
                : (string) json_encode(is_array($args) ? $args : new \stdClass(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $calls[] = [
                'id' => 'call_' . Str::random(16),
                'type' => 'function',
                'function' => [
                    'name' => (string) $item['name'],
                    'arguments' => $argsJson,
                ],
            ];
        }

        return $calls !== [] ? $calls : null;
    }
}
