<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tokensTable = (string) config('mirror.storage.tokens_table', config('mirror.storage.table', 'mirror_tokens'));
        $conversationsTable = (string) config('mirror.storage.conversations_table', 'mirror_conversations');
        $messagesTable = (string) config('mirror.storage.messages_table', 'mirror_messages');
        $filesTable = (string) config('mirror.storage.files_table', 'mirror_files');
        $settingsTable = (string) config('mirror.storage.settings_table', 'mirror_settings');

        if (Schema::hasTable($tokensTable)) {
            Schema::table($tokensTable, function (Blueprint $table) use ($tokensTable): void {
                if (!Schema::hasColumn($tokensTable, 'token_hash')) {
                    $table->string('token_hash', 64)->nullable()->index();
                }
                if (!Schema::hasColumn($tokensTable, 'last_error')) {
                    $table->text('last_error')->nullable();
                }
            });
        }

        if (Schema::hasTable($conversationsTable)) {
            Schema::table($conversationsTable, function (Blueprint $table) use ($conversationsTable): void {
                if (!Schema::hasColumn($conversationsTable, 'public_id')) {
                    $table->string('public_id', 128)->nullable()->unique();
                }
                if (!Schema::hasColumn($conversationsTable, 'upstream_conversation_id')) {
                    $table->string('upstream_conversation_id', 128)->nullable()->index();
                }
                if (!Schema::hasColumn($conversationsTable, 'account_id')) {
                    $table->string('account_id', 128)->default('default')->index();
                }
                if (!Schema::hasColumn($conversationsTable, 'gizmo_id')) {
                    $table->string('gizmo_id', 128)->nullable();
                }
                if (!Schema::hasColumn($conversationsTable, 'is_private')) {
                    $table->boolean('is_private')->default(false);
                }
                if (!Schema::hasColumn($conversationsTable, 'is_branch')) {
                    $table->boolean('is_branch')->default(false);
                }
                if (!Schema::hasColumn($conversationsTable, 'branched_from_id')) {
                    $table->string('branched_from_id', 128)->nullable();
                }
                if (!Schema::hasColumn($conversationsTable, 'branched_from_message_id')) {
                    $table->string('branched_from_message_id', 128)->nullable();
                }
                if (!Schema::hasColumn($conversationsTable, 'initialized')) {
                    $table->boolean('initialized')->default(false);
                }
                if (!Schema::hasColumn($conversationsTable, 'init_payload')) {
                    $table->longText('init_payload')->nullable();
                }
                if (!Schema::hasColumn($conversationsTable, 'instructions_payload')) {
                    $table->longText('instructions_payload')->nullable();
                }
                if (!Schema::hasColumn($conversationsTable, 'openai_context_hash')) {
                    $table->string('openai_context_hash', 64)->nullable();
                }
                if (!Schema::hasColumn($conversationsTable, 'openai_transcript_hash')) {
                    $table->string('openai_transcript_hash', 64)->nullable()->index();
                }
            });
        }

        if (!Schema::hasTable($messagesTable)) {
            Schema::create($messagesTable, function (Blueprint $table): void {
                $table->string('id', 64)->primary();
                $table->string('conversation_public_id', 128)->index();
                $table->string('upstream_node_id', 128)->nullable()->index();
                $table->string('role', 32);
                $table->longText('content')->nullable();
                $table->string('status', 32)->default('done');
                $table->longText('events_payload')->nullable();
                $table->longText('attachments_payload')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable($filesTable)) {
            Schema::create($filesTable, function (Blueprint $table): void {
                $table->id();
                $table->string('file_id', 128)->unique();
                $table->string('account_id', 128)->default('default')->index();
                $table->unsignedBigInteger('token_id')->nullable()->index();
                $table->string('file_name', 512);
                $table->unsignedBigInteger('file_size')->default(0);
                $table->string('mime_type', 255);
                $table->string('use_case', 64)->default('my_files');
                $table->integer('width')->nullable();
                $table->integer('height')->nullable();
                $table->longText('raw_payload')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable($settingsTable)) {
            Schema::create($settingsTable, function (Blueprint $table): void {
                $table->string('key', 191)->primary();
                $table->longText('value')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        $settingsTable = (string) config('mirror.storage.settings_table', 'mirror_settings');
        $filesTable = (string) config('mirror.storage.files_table', 'mirror_files');
        $messagesTable = (string) config('mirror.storage.messages_table', 'mirror_messages');

        Schema::dropIfExists($settingsTable);
        Schema::dropIfExists($filesTable);
        Schema::dropIfExists($messagesTable);
    }
};
