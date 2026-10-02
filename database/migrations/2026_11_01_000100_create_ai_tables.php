<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 12 — IA de atendimento (recepcionista digital).
 *
 * - ai_configs: provedor (Claude/Anthropic, ChatGPT/OpenAI ou MOCK), modelo, chave própria
 *   criptografada (ou a da plataforma, via .env), instruções da clínica.
 * - ai_sessions: estado da IA em cada conversa (ativa / com a equipe), rascunho de agendamento.
 * - ai_requests / ai_tool_calls: registro de cada chamada ao modelo (tokens) e de cada ação.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_configs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('company_id', 26)->unique();
            $table->string('provider', 20)->default('claude')->comment('claude, openai, mock');
            $table->string('model', 80)->nullable();
            $table->text('api_key')->nullable()->comment('Criptografada. Vazio = chave da plataforma (.env)');
            $table->string('assistant_name', 60)->default('Assistente virtual');
            $table->text('instructions')->nullable()->comment('Informações da clínica para a IA (FAQ, convênios, preparo)');
            $table->json('settings')->nullable();
            $table->boolean('is_active')->default(false);
            $table->datetimes();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
        });

        Schema::create('ai_sessions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('company_id', 26);
            $table->char('thread_id', 26)->unique();
            $table->char('patient_id', 26)->nullable();
            $table->string('status', 20)->default('active')->comment('active, handoff');
            $table->string('handoff_reason', 255)->nullable();
            $table->json('state')->nullable();
            $table->unsignedInteger('replies')->default(0);
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->dateTime('last_reply_at')->nullable();
            $table->datetimes();

            $table->unique(['company_id', 'id']);
            $table->foreign(['company_id', 'thread_id'])->references(['company_id', 'id'])->on('message_threads')->cascadeOnDelete();
            $table->foreign(['company_id', 'patient_id'])->references(['company_id', 'id'])->on('patients')->restrictOnDelete();
        });

        Schema::create('ai_requests', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('company_id', 26);
            $table->char('session_id', 26)->nullable();
            $table->string('provider', 20);
            $table->string('model', 80);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('cache_read_tokens')->default(0);
            $table->string('stop_reason', 40)->nullable();
            $table->unsignedInteger('latency_ms')->default(0);
            $table->string('error', 500)->nullable();
            $table->dateTime('created_at');

            $table->index(['company_id', 'created_at']);
        });

        Schema::create('ai_tool_calls', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('company_id', 26);
            $table->char('session_id', 26);
            $table->string('tool', 60);
            $table->json('input')->nullable()->comment('CPF mascarado');
            $table->json('result')->nullable();
            $table->boolean('is_error')->default(false);
            $table->dateTime('created_at');

            $table->index(['company_id', 'session_id']);
            $table->foreign(['company_id', 'session_id'])->references(['company_id', 'id'])->on('ai_sessions')->cascadeOnDelete();
        });

        DB::statement("ALTER TABLE ai_configs ADD CONSTRAINT ai_configs_check CHECK (provider IN ('claude','openai','mock'))");
        DB::statement("ALTER TABLE ai_sessions ADD CONSTRAINT ai_sessions_check CHECK (status IN ('active','handoff'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_tool_calls');
        Schema::dropIfExists('ai_requests');
        Schema::dropIfExists('ai_sessions');
        Schema::dropIfExists('ai_configs');
    }
};
