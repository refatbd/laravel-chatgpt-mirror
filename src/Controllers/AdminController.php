<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Controllers;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Refatbd\LaravelChatGptMirror\Models\MirrorToken;
use Refatbd\LaravelChatGptMirror\Services\ChatGptClient;
use Refatbd\LaravelChatGptMirror\Services\TokenPoolManager;
use Refatbd\LaravelChatGptMirror\Services\TokenRefresher;
use Throwable;

class AdminController extends Controller
{
    public function __construct(
        protected TokenPoolManager $poolManager,
        protected TokenRefresher $refresher,
        protected ChatGptClient $client
    ) {
    }

    /**
     * Render the administrative dashboard & settings UI.
     */
    public function index(): Response
    {
        $tokens = MirrorToken::orderBy('id', 'desc')->get();
        $stats = $this->poolManager->getPoolStats();
        $config = config('mirror');

        $html = $this->renderView($tokens, $stats, $config);

        return response($html, 200)->header('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * Add and validate a new token via UI.
     */
    public function addToken(Request $request)
    {
        $raw = trim((string) $request->input('session_token', ''));
        $name = trim((string) $request->input('name', ''));

        if (empty($raw)) {
            return redirect()->back()->with('error', 'Session token cannot be empty.');
        }

        // Check if raw is a JSON dump
        $json = json_decode($raw, true);
        if (is_array($json) && (isset($json['accessToken']) || isset($json['sessionToken']))) {
            $token = MirrorToken::create([
                'name' => $name ?: ($json['user']['name'] ?? 'Imported Account'),
                'email' => $json['user']['email'] ?? null,
                'session_token' => $json['sessionToken'] ?? ($json['accessToken'] ?? ''),
                'access_token' => $json['accessToken'] ?? null,
                'access_token_expires_at' => isset($json['expires']) ? Carbon::parse($json['expires']) : now()->addDays(7),
                'plan_type' => $json['account']['planType'] ?? 'free',
                'account_id' => $json['account']['id'] ?? ($json['user']['id'] ?? null),
                'status' => 'active',
                'last_refreshed_at' => now(),
                'meta' => $json,
            ]);

            return redirect()->back()->with('success', "Account {$token->email} added successfully!");
        }

        $token = new MirrorToken([
            'name' => $name ?: 'Account #' . (MirrorToken::count() + 1),
            'session_token' => $raw,
            'status' => 'active',
        ]);
        $token->save();

        try {
            $this->refresher->refresh($token);
            return redirect()->back()->with('success', "Token #{$token->id} ({$token->email}) validated and added to pool!");
        } catch (Throwable $e) {
            return redirect()->back()->with('warning', "Token saved, but verification failed: " . $e->getMessage());
        }
    }

    /**
     * Refresh a specific token.
     */
    public function refreshToken(int $id)
    {
        $token = MirrorToken::findOrFail($id);
        try {
            $this->refresher->refresh($token);
            return redirect()->back()->with('success', "Token #{$token->id} refreshed successfully!");
        } catch (Throwable $e) {
            return redirect()->back()->with('error', "Refresh failed: " . $e->getMessage());
        }
    }

    /**
     * Delete a token from the pool.
     */
    public function deleteToken(int $id)
    {
        $token = MirrorToken::findOrFail($id);
        $token->delete();

        return redirect()->back()->with('success', "Token #{$id} deleted from pool.");
    }

    /**
     * Return live pool statistics in JSON.
     */
    public function stats(): JsonResponse
    {
        $stats = $this->poolManager->getPoolStats();
        $stats['strategy'] = config('mirror.pool.strategy', 'least_busy');

        return response()->json($stats);
    }

    /**
     * Render the complete self-contained Admin Dashboard HTML.
     */
    protected function renderView($tokens, array $stats, array $config): string
    {
        $csrf = function_exists('csrf_token') ? csrf_token() : '';
        $rows = '';
        foreach ($tokens as $t) {
            $badgeColor = match ($t->status) {
                'active' => 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20',
                'cooldown' => 'bg-amber-500/10 text-amber-400 border-amber-500/20',
                default => 'bg-rose-500/10 text-rose-400 border-rose-500/20',
            };

            $expires = $t->access_token_expires_at ? $t->access_token_expires_at->diffForHumans() : 'Unknown';
            $inFlight = $t->active_requests > 0 ? "<span class='px-2 py-0.5 rounded text-xs font-bold bg-indigo-500/20 text-indigo-300'>{$t->active_requests}</span>" : "<span class='text-zinc-500'>0</span>";

            $rows .= <<<HTML
            <tr class="border-b border-zinc-800 hover:bg-zinc-800/40 transition">
                <td class="py-3 px-4 text-xs font-mono text-zinc-400">#{$t->id}</td>
                <td class="py-3 px-4 font-medium text-white">{$t->name}</td>
                <td class="py-3 px-4 text-zinc-300 font-mono text-xs">{$t->email}</td>
                <td class="py-3 px-4"><span class="px-2 py-0.5 rounded text-xs font-semibold bg-zinc-800 text-zinc-300 uppercase">{$t->plan_type}</span></td>
                <td class="py-3 px-4"><span class="px-2 py-0.5 rounded text-xs border font-medium {$badgeColor}">{$t->status}</span></td>
                <td class="py-3 px-4 text-center font-mono">{$inFlight}</td>
                <td class="py-3 px-4 text-center font-mono text-zinc-400">{$t->total_requests}</td>
                <td class="py-3 px-4 text-xs text-zinc-400">{$expires}</td>
                <td class="py-3 px-4 text-right space-x-2">
                    <form method="POST" action="/mirror/admin/tokens/{$t->id}/refresh" class="inline">
                        <input type="hidden" name="_token" value="{$csrf}">
                        <button type="submit" class="px-2.5 py-1 text-xs bg-zinc-700 hover:bg-zinc-600 text-zinc-200 rounded transition">Refresh</button>
                    </form>
                    <form method="POST" action="/mirror/admin/tokens/{$t->id}/delete" class="inline" onsubmit="return confirm('Delete this token from pool?');">
                        <input type="hidden" name="_token" value="{$csrf}">
                        <button type="submit" class="px-2.5 py-1 text-xs bg-rose-900/40 hover:bg-rose-800/60 text-rose-300 border border-rose-800/50 rounded transition">Delete</button>
                    </form>
                </td>
            </tr>
HTML;
        }

        if (empty($rows)) {
            $rows = '<tr><td colspan="9" class="py-8 text-center text-zinc-500 text-sm">No tokens configured in the pool. Use the form below to add your first session token.</td></tr>';
        }

        $apiKey = config('mirror.api_key', 'Not Set');
        $strategy = config('mirror.pool.strategy', 'least_busy');
        $cooldown = config('mirror.pool.cooldown_seconds', 60);
        $egress = config('mirror.egress.mode', 'direct');

        return <<<HTML
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laravel ChatGPT Mirror — Settings & Admin Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        brand: '#10a37f',
                        'brand-hover': '#0e8a6c'
                    }
                }
            }
        }
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        code, pre { font-family: 'JetBrains Mono', monospace; }
    </style>
</head>
<body class="bg-zinc-950 text-zinc-100 min-h-screen">
    <!-- Top Header -->
    <header class="border-b border-zinc-800 bg-zinc-900/60 backdrop-blur sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
            <div class="flex items-center space-x-3">
                <div class="w-8 h-8 rounded-lg bg-emerald-500/20 border border-emerald-500/30 flex items-center justify-center text-emerald-400 font-bold text-lg">
                    ⚡
                </div>
                <div>
                    <h1 class="text-base font-bold tracking-tight text-white flex items-center gap-2">
                        Laravel ChatGPT Mirror
                        <span class="text-[10px] font-mono uppercase bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 px-2 py-0.5 rounded-full">v1.0.0</span>
                    </h1>
                    <p class="text-xs text-zinc-400">High-Concurrency Pool & Direct Egress Gateway</p>
                </div>
            </div>
            <div class="flex items-center space-x-4">
                <a href="/" target="_blank" class="px-3 py-1.5 text-xs font-medium text-zinc-300 hover:text-white bg-zinc-800 hover:bg-zinc-700 rounded-md transition flex items-center gap-1.5 border border-zinc-700">
                    <span>Open Web UI</span>
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path></svg>
                </a>
                <div class="flex items-center gap-2 text-xs text-emerald-400 bg-emerald-500/10 border border-emerald-500/20 px-3 py-1.5 rounded-md">
                    <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                    <span>Direct Egress (WARP: Off)</span>
                </div>
            </div>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-8">
        <!-- Live Pool Metric Cards -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="bg-zinc-900 border border-zinc-800 rounded-xl p-5 shadow-sm">
                <div class="text-xs font-medium text-zinc-400 uppercase tracking-wider">Total Accounts in Pool</div>
                <div class="mt-2 text-3xl font-extrabold text-white">{$stats['total_tokens']}</div>
                <div class="mt-1 text-xs text-emerald-400">{$stats['active_healthy']} active &amp; ready</div>
            </div>

            <div class="bg-zinc-900 border border-zinc-800 rounded-xl p-5 shadow-sm">
                <div class="text-xs font-medium text-zinc-400 uppercase tracking-wider">In-Flight Concurrency</div>
                <div class="mt-2 text-3xl font-extrabold text-indigo-400">{$stats['total_in_flight_requests']}</div>
                <div class="mt-1 text-xs text-zinc-400">Targeting 100–200 concurrent users</div>
            </div>

            <div class="bg-zinc-900 border border-zinc-800 rounded-xl p-5 shadow-sm">
                <div class="text-xs font-medium text-zinc-400 uppercase tracking-wider">Total Requests Served</div>
                <div class="mt-2 text-3xl font-extrabold text-white">{$stats['total_requests_served']}</div>
                <div class="mt-1 text-xs text-zinc-400">Zero upstream concurrency drops</div>
            </div>

            <div class="bg-zinc-900 border border-zinc-800 rounded-xl p-5 shadow-sm">
                <div class="text-xs font-medium text-zinc-400 uppercase tracking-wider">Balancing Strategy</div>
                <div class="mt-2 text-2xl font-bold font-mono text-emerald-400 uppercase">{$strategy}</div>
                <div class="mt-1 text-xs text-zinc-400">Auto-routes to least busy account</div>
            </div>
        </div>

        <!-- Token Pool Table -->
        <div class="bg-zinc-900 border border-zinc-800 rounded-xl overflow-hidden shadow-sm">
            <div class="px-6 py-4 border-b border-zinc-800 flex items-center justify-between bg-zinc-900/80">
                <div>
                    <h2 class="text-base font-semibold text-white">ChatGPT Accounts &amp; Token Pool</h2>
                    <p class="text-xs text-zinc-400">Tokens are encrypted with AES-256 at rest and rotated dynamically under high traffic.</p>
                </div>
                <span class="text-xs font-mono text-zinc-400">Storage: SQLite Encrypted</span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="border-b border-zinc-800 text-xs font-medium text-zinc-400 uppercase tracking-wider bg-zinc-950/50">
                            <th class="py-3 px-4">ID</th>
                            <th class="py-3 px-4">Name / Label</th>
                            <th class="py-3 px-4">Email Account</th>
                            <th class="py-3 px-4">Plan</th>
                            <th class="py-3 px-4">Health Status</th>
                            <th class="py-3 px-4 text-center">In-Flight</th>
                            <th class="py-3 px-4 text-center">Total Served</th>
                            <th class="py-3 px-4">Access Token</th>
                            <th class="py-3 px-4 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-800 text-sm">
                        {$rows}
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Two Columns: Add Token & System Configuration -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            <!-- Add Token Box -->
            <div class="bg-zinc-900 border border-zinc-800 rounded-xl p-6 shadow-sm flex flex-col justify-between">
                <div>
                    <h3 class="text-base font-semibold text-white mb-1">Add Account to Pool</h3>
                    <p class="text-xs text-zinc-400 mb-4">Paste a single session token, or the entire JSON object from <a href="https://chatgpt.com/api/auth/session" target="_blank" class="text-emerald-400 underline">chatgpt.com/api/auth/session</a>.</p>

                    <form method="POST" action="/mirror/admin/tokens/add" class="space-y-4">
                        <input type="hidden" name="_token" value="{$csrf}">
                        <div>
                            <label class="block text-xs font-medium text-zinc-300 mb-1">Account Label (Optional)</label>
                            <input type="text" name="name" placeholder="e.g. Production Account #2" class="w-full px-3 py-2 text-sm bg-zinc-950 border border-zinc-800 rounded-lg text-white focus:outline-none focus:border-emerald-500">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-zinc-300 mb-1">Session Token or Full Session JSON</label>
                            <textarea name="session_token" rows="5" placeholder="eyJhbGciOiJkaXIi... or { 'accessToken': '...', 'sessionToken': '...' }" required class="w-full px-3 py-2 text-xs font-mono bg-zinc-950 border border-zinc-800 rounded-lg text-zinc-200 focus:outline-none focus:border-emerald-500"></textarea>
                        </div>
                        <button type="submit" class="w-full py-2.5 px-4 bg-emerald-600 hover:bg-emerald-500 text-white font-medium text-sm rounded-lg transition shadow">
                            + Add Account to Concurrency Pool
                        </button>
                    </form>
                </div>
            </div>

            <!-- Configuration & Gateway Settings -->
            <div class="bg-zinc-900 border border-zinc-800 rounded-xl p-6 shadow-sm">
                <h3 class="text-base font-semibold text-white mb-1">Project &amp; Gateway Settings</h3>
                <p class="text-xs text-zinc-400 mb-4">Current runtime parameters loaded from <code>config/mirror.php</code> and <code>.env</code>.</p>

                <div class="space-y-4 text-xs font-mono">
                    <div class="bg-zinc-950 p-3 rounded-lg border border-zinc-800 flex items-center justify-between">
                        <div>
                            <div class="text-zinc-500 uppercase">API Key (Authorization: Bearer)</div>
                            <div class="text-emerald-400 font-bold mt-0.5 select-all">{$apiKey}</div>
                        </div>
                        <button onclick="navigator.clipboard.writeText('{$apiKey}'); alert('Copied API Key!');" class="px-2.5 py-1 text-[11px] bg-zinc-800 hover:bg-zinc-700 text-zinc-300 rounded border border-zinc-700">Copy</button>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div class="bg-zinc-950 p-3 rounded-lg border border-zinc-800">
                            <div class="text-zinc-500 uppercase">Egress Mode</div>
                            <div class="text-white font-semibold mt-0.5">{$egress} (WARP: Disabled)</div>
                        </div>
                        <div class="bg-zinc-950 p-3 rounded-lg border border-zinc-800">
                            <div class="text-zinc-500 uppercase">429 Cooldown</div>
                            <div class="text-white font-semibold mt-0.5">{$cooldown} seconds</div>
                        </div>
                        <div class="bg-zinc-950 p-3 rounded-lg border border-zinc-800">
                            <div class="text-zinc-500 uppercase">OpenAI Prefix</div>
                            <div class="text-white font-semibold mt-0.5">/v1/chat/completions</div>
                        </div>
                        <div class="bg-zinc-950 p-3 rounded-lg border border-zinc-800">
                            <div class="text-zinc-500 uppercase">Browser Gateway</div>
                            <div class="text-white font-semibold mt-0.5">/ (Root) &amp; /mirror</div>
                        </div>
                    </div>

                    <div class="bg-zinc-950/60 p-3 rounded-lg border border-zinc-800/80 text-zinc-400 text-[11px] font-sans">
                        💡 <strong>High-concurrency Tip:</strong> To serve 200 concurrent users without throttling, add 3 to 5 separate session tokens above. The <code>least_busy</code> load balancer will automatically distribute prompts across healthy accounts.
                    </div>
                </div>
            </div>
        </div>

        <!-- Live API Tester / Playground -->
        <div class="bg-zinc-900 border border-zinc-800 rounded-xl p-6 shadow-sm">
            <h3 class="text-base font-semibold text-white mb-1">Live API Tester (OpenAI Compatible)</h3>
            <p class="text-xs text-zinc-400 mb-4">Send an prompt to test Server-Sent Events (SSE) token streaming via your active token pool.</p>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="space-y-4">
                    <div>
                        <label class="block text-xs font-medium text-zinc-300 mb-1">Model</label>
                        <select id="test-model" class="w-full px-3 py-2 text-sm bg-zinc-950 border border-zinc-800 rounded-lg text-white">
                            <option value="auto">auto (ChatGPT Default)</option>
                            <option value="gpt-4o">gpt-4o</option>
                            <option value="gpt-4o-mini">gpt-4o-mini</option>
                            <option value="o1">o1</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-zinc-300 mb-1">Test Prompt</label>
                        <textarea id="test-prompt" rows="4" class="w-full px-3 py-2 text-sm bg-zinc-950 border border-zinc-800 rounded-lg text-white">Hello! Write a 2-line greeting in Bengali.</textarea>
                    </div>
                    <button type="button" onclick="runTestPrompt()" id="btn-run" class="py-2.5 px-6 bg-indigo-600 hover:bg-indigo-500 text-white font-medium text-sm rounded-lg transition shadow flex items-center gap-2">
                        <span>Execute Completion</span>
                    </button>
                </div>
                <div>
                    <label class="block text-xs font-medium text-zinc-300 mb-1">Streaming Output</label>
                    <div id="test-output" class="w-full h-48 bg-zinc-950 border border-zinc-800 rounded-lg p-3 text-xs font-mono text-emerald-400 overflow-y-auto whitespace-pre-wrap select-all">Ready for execution. Click "Execute Completion" to start...</div>
                </div>
            </div>
        </div>
    </main>

    <footer class="border-t border-zinc-800 mt-12 py-6 text-center text-xs text-zinc-500">
        Laravel ChatGPT Mirror &bull; Built by Mostafizur Rahman Refat (<a href="https://github.com/refatbd" target="_blank" class="text-zinc-400 hover:text-white">github.com/refatbd</a>)
    </footer>

    <script>
        async function runTestPrompt() {
            const btn = document.getElementById('btn-run');
            const output = document.getElementById('test-output');
            const prompt = document.getElementById('test-prompt').value;
            const model = document.getElementById('test-model').value;

            btn.disabled = true;
            btn.innerText = 'Streaming...';
            output.innerText = '';

            try {
                const response = await fetch('/v1/chat/completions', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': 'Bearer {$apiKey}'
                    },
                    body: JSON.stringify({
                        model: model,
                        messages: [{ role: 'user', content: prompt }],
                        stream: true
                    })
                });

                if (!response.ok) {
                    const err = await response.text();
                    output.innerText = 'Error [' + response.status + ']: ' + err;
                    return;
                }

                const reader = response.body.getReader();
                const decoder = new TextDecoder();
                let buffer = '';

                while (true) {
                    const { done, value } = await reader.read();
                    if (done) break;

                    buffer += decoder.decode(value, { stream: true });
                    const lines = buffer.split('\\n');
                    buffer = lines.pop();

                    for (const line of lines) {
                        const trimmed = line.trim();
                        if (trimmed.startsWith('data: ') && trimmed !== 'data: [DONE]') {
                            try {
                                const json = JSON.parse(trimmed.substring(6));
                                if (json.choices && json.choices[0] && json.choices[0].delta && json.choices[0].delta.content) {
                                    output.innerText += json.choices[0].delta.content;
                                    output.scrollTop = output.scrollHeight;
                                } else if (json.error && json.error.message) {
                                    output.innerText = 'Error: ' + json.error.message;
                                }
                            } catch (e) {}
                        }
                    }
                }
            } catch (err) {
                output.innerText = 'Connection error: ' + err.message;
            } finally {
                btn.disabled = false;
                btn.innerText = 'Execute Completion';
            }
        }
    </script>
</body>
</html>
HTML;
    }
}
