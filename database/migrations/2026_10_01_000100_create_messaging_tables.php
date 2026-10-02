<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 11 — WhatsApp, lembretes/confirmações automáticos e central de notificações.
 *
 * - messaging_channels: número de WhatsApp da clínica (Cloud API oficial da Meta ou MOCK).
 * - messages: caixa de saída/entrada (WhatsApp e e-mail) com status, tentativas e
 *   chave de deduplicação (o mesmo lembrete nunca sai duas vezes).
 * - message_threads: conversas por telefone (janela de 24 h do WhatsApp).
 * - staff_notifications: avisos internos para a equipe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messaging_channels', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('company_id', 26);
            $table->string('provider', 20)->comment('meta, mock');
            $table->string('mode', 20)->default('test')->comment('mock, test, production');
            $table->string('name', 80);
            $table->string('phone_number_id', 40)->nullable()->comment('Phone Number ID da Cloud API');
            $table->string('waba_id', 40)->nullable();
            $table->string('display_phone', 20)->nullable();
            $table->text('credentials')->nullable()->comment('Criptografado: access_token, app_secret');
            $table->text('verify_token')->nullable()->comment('Criptografado: token de verificação do webhook');
            $table->string('api_version', 10)->default('v21.0');
            $table->json('templates')->nullable()->comment('Finalidade → {name, language} aprovados na Meta');
            $table->boolean('is_active')->default(true);
            $table->dateTime('last_webhook_at')->nullable();
            $table->datetimes();

            $table->unique(['company_id', 'id']);
            $table->unique(['phone_number_id']);
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
        });

        Schema::create('message_threads', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('company_id', 26);
            $table->char('channel_id', 26);
            $table->string('phone', 20)->comment('E.164 sem +');
            $table->string('contact_name', 120)->nullable();
            $table->char('patient_id', 26)->nullable();
            $table->dateTime('last_inbound_at')->nullable()->comment('Abre a janela de 24 h para texto livre');
            $table->dateTime('last_message_at')->nullable();
            $table->unsignedInteger('unread_count')->default(0);
            $table->string('status', 20)->default('open')->comment('open, closed');
            $table->datetimes();

            $table->unique(['company_id', 'id']);
            $table->unique(['channel_id', 'phone']);
            $table->index(['company_id', 'last_message_at']);
            $table->foreign(['company_id', 'channel_id'])->references(['company_id', 'id'])->on('messaging_channels')->cascadeOnDelete();
            $table->foreign(['company_id', 'patient_id'])->references(['company_id', 'id'])->on('patients')->restrictOnDelete();
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('company_id', 26);
            $table->char('branch_id', 26)->nullable();
            $table->char('channel_id', 26)->nullable();
            $table->char('thread_id', 26)->nullable();
            $table->char('patient_id', 26)->nullable();
            $table->char('appointment_id', 26)->nullable();
            $table->string('channel', 20)->comment('whatsapp, email');
            $table->string('direction', 3)->comment('out, in');
            $table->string('purpose', 40)->comment('reminder, booking_confirmation, cancellation, reschedule, no_show, portal_access, manual, inbound');
            $table->string('recipient', 190)->nullable()->comment('Telefone E.164 ou e-mail');
            $table->string('template', 80)->nullable();
            $table->json('params')->nullable();
            $table->text('body')->nullable();
            $table->string('status', 20)->default('queued')->comment('queued, sent, delivered, read, failed, received, skipped');
            $table->string('provider_message_id', 120)->nullable();
            $table->string('error', 500)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->dateTime('next_attempt_at')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('delivered_at')->nullable();
            $table->dateTime('read_at')->nullable();
            $table->string('dedupe_key', 120)->nullable();
            $table->char('created_by', 26)->nullable();
            $table->datetimes();

            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'dedupe_key']);
            $table->unique(['provider_message_id']);
            $table->index(['status', 'next_attempt_at']);
            $table->index(['company_id', 'thread_id', 'created_at']);
            $table->index(['company_id', 'appointment_id']);
            $table->foreign(['company_id', 'patient_id'])->references(['company_id', 'id'])->on('patients')->restrictOnDelete();
            $table->foreign(['company_id', 'appointment_id'])->references(['company_id', 'id'])->on('appointments')->restrictOnDelete();
            $table->foreign(['company_id', 'thread_id'])->references(['company_id', 'id'])->on('message_threads')->restrictOnDelete();
        });

        Schema::create('staff_notifications', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('company_id', 26);
            $table->char('branch_id', 26)->nullable()->comment('Vazio = todas as unidades');
            $table->string('permission', 60)->nullable()->comment('Quem vê (vazio = todos)');
            $table->string('type', 40);
            $table->string('level', 10)->default('info')->comment('info, warning, danger');
            $table->string('title', 160);
            $table->string('body', 500)->nullable();
            $table->string('url', 300)->nullable();
            $table->dateTime('created_at');

            $table->index(['company_id', 'created_at']);
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
        });

        Schema::create('staff_notification_reads', function (Blueprint $table) {
            $table->char('notification_id', 26);
            $table->char('user_id', 26);
            $table->dateTime('read_at');
            $table->primary(['notification_id', 'user_id']);
            $table->foreign('notification_id')->references('id')->on('staff_notifications')->cascadeOnDelete();
        });

        DB::statement("ALTER TABLE messaging_channels ADD CONSTRAINT messaging_channels_check CHECK (provider IN ('meta','mock') AND mode IN ('mock','test','production'))");
        DB::statement("ALTER TABLE messages ADD CONSTRAINT messages_check CHECK (channel IN ('whatsapp','email') AND direction IN ('out','in') AND status IN ('queued','sent','delivered','read','failed','received','skipped'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_notification_reads');
        Schema::dropIfExists('staff_notifications');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('message_threads');
        Schema::dropIfExists('messaging_channels');
    }
};
