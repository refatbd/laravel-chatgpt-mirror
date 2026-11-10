<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $table = config('mirror.storage.table', 'mirror_tokens');

        if (!Schema::hasTable($table)) {
            Schema::create($table, function (Blueprint $table) {
                $table->id();
                $table->string('name')->nullable();
                $table->string('email')->nullable()->index();
                $table->text('session_token'); // Encrypted session token
                $table->text('access_token')->nullable(); // Encrypted access token
                $table->timestamp('access_token_expires_at')->nullable();
                $table->string('plan_type')->default('free'); // free, plus, pro, team
                $table->string('account_id')->nullable();
                $table->string('status')->default('active')->index(); // active, cooldown, disabled, expired
                $table->unsignedInteger('active_requests')->default(0)->index(); // In-flight request counter for least-busy balancing
                $table->unsignedBigInteger('total_requests')->default(0);
                $table->unsignedBigInteger('total_tokens_used')->default(0);
                $table->unsignedInteger('consecutive_errors')->default(0);
                $table->timestamp('cooldown_until')->nullable()->index();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('last_refreshed_at')->nullable();
                $table->json('meta')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        $table = config('mirror.storage.table', 'mirror_tokens');
        Schema::dropIfExists($table);
    }
};
