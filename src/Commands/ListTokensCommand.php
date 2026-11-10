<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Commands;

use Illuminate\Console\Command;
use Refatbd\LaravelChatGptMirror\Models\MirrorToken;

class ListTokensCommand extends Command
{
    protected $signature = 'mirror:token:list';
    protected $description = 'Display all ChatGPT session tokens and real-time concurrency metrics';

    public function handle(): int
    {
        $tokens = MirrorToken::all();

        if ($tokens->isEmpty()) {
            $this->warn('No tokens found in the pool. Add one using: php artisan mirror:token:add');
            return self::SUCCESS;
        }

        $rows = $tokens->map(function (MirrorToken $token) {
            $statusColor = match ($token->status) {
                'active' => "<info>{$token->status}</info>",
                'cooldown' => "<comment>{$token->status}</comment>",
                default => "<error>{$token->status}</error>",
            };

            return [
                $token->id,
                $token->name ?: 'N/A',
                $token->email ?: 'N/A',
                strtoupper($token->plan_type ?: 'free'),
                $statusColor,
                $token->active_requests,
                $token->total_requests,
                $token->cooldown_until ? $token->cooldown_until->diffForHumans() : 'None',
                $token->last_used_at ? $token->last_used_at->diffForHumans() : 'Never',
            ];
        });

        $this->table(
            ['ID', 'Name', 'Email', 'Plan', 'Status', 'In-Flight', 'Total Served', 'Cooldown', 'Last Used'],
            $rows
        );

        $this->line("Total Tokens: <info>{$tokens->count()}</info> | Total In-Flight Requests: <comment>{$tokens->sum('active_requests')}</comment>");

        return self::SUCCESS;
    }
}
