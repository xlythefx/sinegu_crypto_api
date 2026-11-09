<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI access (owner's request, 2026-11-09): the employer's Grok app reads the
 * platform through a read-only MCP connector at POST /api/mcp
 * (App\Services\Ai\McpServer). It is NOT a user session and NOT a public
 * endpoint — each caller holds a key made on Admin → AI Access.
 *
 *  - ai_access_keys — one row per key. Only the SHA-256 of the key is kept;
 *    the key itself is shown once, at creation, and can never be read back.
 *    `prefix` is the first characters, so the page can say which key is which.
 *    A revoked or expired row stays, so the question log keeps its owner.
 *  - ai_access_logs — one row per call the connector made: which tool, with
 *    which arguments, how it went. The auditor is audited.
 *
 * One migration, so `migrate:rollback --step=1` removes the feature's tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_access_keys', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80)->comment('What the admin called it, e.g. "Owner\'s Grok"');
            $table->string('prefix', 16)->comment('First characters of the key, for telling keys apart');
            $table->char('key_hash', 64)->unique()->comment('SHA-256 of the key; the key itself is never stored');
            $table->string('created_by', 64)->nullable()->comment('uni_id of the admin who made it');
            $table->string('created_by_name', 120)->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('last_used_at')->nullable();
            $table->string('last_used_ip', 45)->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->string('revoked_by_name', 120)->nullable();
            $table->timestamps();
        });

        Schema::create('ai_access_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('key_id')->nullable()->constrained('ai_access_keys')->nullOnDelete();
            $table->string('method', 40)->comment('MCP method: initialize | tools/list | tools/call | …');
            $table->string('tool', 60)->nullable();
            $table->json('arguments')->nullable();
            $table->string('status', 12)->comment('ok | error');
            $table->string('error', 255)->nullable();
            $table->unsignedInteger('response_bytes')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['key_id', 'created_at'], 'idx_ai_access_logs_key_time');
            $table->index('created_at', 'idx_ai_access_logs_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_access_logs');
        Schema::dropIfExists('ai_access_keys');
    }
};
