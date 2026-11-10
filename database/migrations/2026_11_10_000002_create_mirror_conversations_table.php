<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $table = config('mirror.storage.conversations_table', 'mirror_conversations');

        if (!Schema::hasTable($table)) {
            Schema::create($table, function (Blueprint $table) {
                $table->id();
                $table->string('conversation_id')->unique(); // Upstream ChatGPT conversation UUID
                $table->foreignId('token_id')->nullable()->index(); // Associated MirrorToken
                $table->string('model')->default('gpt-4o');
                $table->string('current_node_id')->nullable(); // Parent message UUID in message tree
                $table->string('title')->nullable();
                $table->json('messages_payload')->nullable(); // Cached context if needed
                $table->timestamp('last_active_at')->nullable()->index();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        $table = config('mirror.storage.conversations_table', 'mirror_conversations');
        Schema::dropIfExists($table);
    }
};
