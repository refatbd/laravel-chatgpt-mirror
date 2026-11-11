<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mirror_tokens')) {
            Schema::table('mirror_tokens', function (Blueprint $table): void {
                if (!Schema::hasColumn('mirror_tokens', 'token_hash')) {
                    $table->string('token_hash', 64)->nullable()->index();
                }
                if (!Schema::hasColumn('mirror_tokens', 'last_error')) {
                    $table->text('last_error')->nullable();
                }
            });
        }

        if (Schema::hasTable('mirror_conversations')) {
            Schema::table('mirror_conversations', function (Blueprint $table): void {
                if (!Schema::hasColumn('mirror_conversations', 'public_id')) {
                    $table->string('public_id', 128)->nullable()->unique();
                }
                if (!Schema::hasColumn('mirror_conversations', 'upstream_conversation_id')) {
                    $table->string('upstream_conversation_id', 128)->nullable()->index();
                }
                if (!Schema::hasColumn('mirror_conversations', 'account_id')) {
                    $table->string('account_id', 128)->default('default')->index();
                }
                if (!Schema::hasColumn('mirror_conversations', 'gizmo_id')) {
                    $table->string('gizmo_id', 128)->nullable();
                }
                if (!Schema::hasColumn('mirror_conversations', 'is_private')) {
                    $table->boolean('is_private')->default(false);
                }
                if (!Schema::hasColumn('mirror_conversations', 'is_branch')) {
                    $table->boolean('is_branch')->default(false);
                }
                if (!Schema::hasColumn('mirror_conversations', 'branched_from_id')) {
                    $table->string('branched_from_id', 128)->nullable();
                }
                if (!Schema::hasColumn('mirror_conversations', 'branched_from_message_id')) {
                    $table->string('branched_from_message_id', 128)->nullable();
                }
                if (!Schema::hasColumn('mirror_conversations', 'initialized')) {
                    $table->boolean('initialized')->default(false);
                }
                if (!Schema::hasColumn('mirror_conversations', 'init_payload')) {
                    $table->longText('init_payload')->nullable();
                }
                if (!Schema::hasColumn('mirror_conversations', 'instructions_payload')) {
                    $table->longText('instructions_payload')->nullable();
                }
                if (!Schema::hasColumn('mirror_conversations', 'openai_context_hash')) {
                    $table->string('openai_context_hash', 64)->nullable();
                }
                if (!Schema::hasColumn('mirror_conversations', 'openai_transcript_hash')) {
                    $table->string('openai_transcript_hash', 64)->nullable()->index();
                }
            });
        }

        if (!Schema::hasTable('mirror_messages')) {
            Schema::create('mirror_messages', function (Blueprint $table): void {
                $table->string('id', 64)->primary();
                $table->string('conversation_public_id', 128)->index();
                $table->string('upstream_node_id', 128)->nullable()->index();
                $table->string('role', 32);
                $table->longText('content')->default('');
                $table->string('status', 32)->default('done');
                $table->longText('events_payload')->nullable();
                $table->longText('attachments_payload')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('mirror_files')) {
            Schema::create('mirror_files', function (Blueprint $table): void {
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

        if (!Schema::hasTable('mirror_settings')) {
            Schema::create('mirror_settings', function (Blueprint $table): void {
                $table->string('key', 191)->primary();
                $table->longText('value')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mirror_settings');
        Schema::dropIfExists('mirror_files');
        Schema::dropIfExists('mirror_messages');
    }
};
