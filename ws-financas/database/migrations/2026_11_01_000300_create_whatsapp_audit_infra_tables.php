<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Números de WhatsApp autorizados por cliente. O telefone fica criptografado; a busca usa um HMAC.
        Schema::create('whatsapp_contacts', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('account_id')->constrained()->cascadeOnDelete();
            $t->ulid('user_id')->nullable();
            $t->string('label', 80);
            $t->text('phone'); // criptografado (E.164 sem +)
            $t->char('phone_hash', 64)->unique();
            $t->string('phone_last4', 4);
            $t->ulid('default_company_id')->nullable();
            $t->boolean('can_write')->default(true);
            $t->boolean('reminders_opt_in')->default(false);
            $t->boolean('active')->default(true);
            $t->timestamps();
            // FK simples (SET NULL não é permitido em FK composta com account_id obrigatório no MySQL);
            // a empresa padrão é validada contra o cliente no WhatsappController.
            $t->foreign('default_company_id')->references('id')->on('companies')->nullOnDelete();
        });

        Schema::create('whatsapp_messages', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('account_id')->nullable();
            $t->string('direction', 3); // in | out
            $t->string('wamid', 191)->nullable()->unique(); // id da Meta: evita processar a mesma mensagem duas vezes
            $t->char('phone_hash', 64);
            $t->text('body')->nullable();
            $t->string('intent', 40)->nullable();
            $t->string('status', 20)->default('received');
            $t->ulid('entry_id')->nullable();
            $t->boolean('used_ai')->default(false);
            $t->timestamps();
            $t->index(['account_id', 'created_at']);
            $t->index(['phone_hash', 'created_at']);
        });

        // Conversa pendente (aguardando confirmação/escolha) — uma por telefone.
        Schema::create('whatsapp_pendings', function (Blueprint $t) {
            $t->char('phone_hash', 64)->primary();
            $t->ulid('account_id');
            $t->json('payload');
            $t->timestamp('expires_at');
            $t->timestamps();
        });

        Schema::create('usage_counters', function (Blueprint $t) {
            $t->id();
            $t->foreignUlid('account_id')->constrained()->cascadeOnDelete();
            $t->char('period', 7);
            $t->unsignedInteger('whatsapp_in')->default(0);
            $t->unsignedInteger('whatsapp_out')->default(0);
            $t->unsignedInteger('ai_calls')->default(0);
            $t->unsignedBigInteger('ai_input_tokens')->default(0);
            $t->unsignedBigInteger('ai_output_tokens')->default(0);
            $t->timestamps();
            $t->unique(['account_id', 'period']);
        });

        // Auditoria append-only com cadeia de hash (HMAC) — adulteração é detectável.
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->ulid('account_id')->nullable();
            $t->ulid('user_id')->nullable();
            $t->string('action', 80);
            $t->string('subject_type', 60)->nullable();
            $t->string('subject_id', 40)->nullable();
            $t->json('data')->nullable();
            $t->string('ip', 45)->nullable();
            $t->string('channel', 12)->default('web');
            $t->char('prev_hash', 64)->nullable();
            $t->char('hash', 64);
            $t->timestamp('created_at')->useCurrent();
            $t->index(['account_id', 'created_at']);
            $t->index('action');
        });

        Schema::create('password_reset_tokens', function (Blueprint $t) {
            $t->string('email')->primary();
            $t->string('token');
            $t->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->string('user_id', 26)->nullable()->index();
            $t->string('ip_address', 45)->nullable();
            $t->text('user_agent')->nullable();
            $t->longText('payload');
            $t->integer('last_activity')->index();
        });

        Schema::create('cache', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->mediumText('value');
            $t->bigInteger('expiration')->index();
        });

        Schema::create('cache_locks', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->string('owner');
            $t->bigInteger('expiration')->index();
        });

        Schema::create('jobs', function (Blueprint $t) {
            $t->id();
            $t->string('queue')->index();
            $t->longText('payload');
            $t->unsignedTinyInteger('attempts');
            $t->unsignedInteger('reserved_at')->nullable();
            $t->unsignedInteger('available_at');
            $t->unsignedInteger('created_at');
        });

        Schema::create('failed_jobs', function (Blueprint $t) {
            $t->id();
            $t->string('uuid')->unique();
            $t->text('connection');
            $t->text('queue');
            $t->longText('payload');
            $t->longText('exception');
            $t->timestamp('failed_at')->useCurrent();
        });
    }

    public function down(): void
    {
        foreach (['failed_jobs', 'jobs', 'cache_locks', 'cache', 'sessions', 'password_reset_tokens', 'audit_logs', 'usage_counters', 'whatsapp_pendings', 'whatsapp_messages', 'whatsapp_contacts'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
