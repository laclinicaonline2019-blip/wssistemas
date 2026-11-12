<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plataforma: planos, clientes (tenants), usuários e vínculos.
 *
 * Isolamento: todo dado financeiro carrega account_id (cliente) e company_id (empresa).
 * FKs compostas (account_id, company_id) → companies(account_id, id) impedem, mesmo por SQL
 * direto, ligar um lançamento a uma empresa de outro cliente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('code', 40)->unique();
            $t->string('name', 80);
            $t->string('description', 500)->nullable();
            $t->unsignedBigInteger('price_monthly_cents');
            $t->unsignedBigInteger('price_yearly_cents');
            $t->unsignedInteger('max_companies');
            $t->unsignedInteger('max_users');
            $t->unsignedInteger('whatsapp_messages_month')->default(0); // 0 = sem WhatsApp
            $t->unsignedInteger('ai_calls_month')->default(0);
            $t->json('features'); // whatsapp, consolidated, advanced_reports, advanced_permissions, accountant
            $t->boolean('active')->default(true);
            $t->unsignedSmallInteger('sort')->default(0);
            $t->timestamps();
        });

        Schema::create('accounts', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('name', 160);
            $t->string('person_type', 2); // pf | pj
            $t->text('document')->nullable(); // CPF/CNPJ criptografado
            $t->string('email', 190);
            $t->text('phone')->nullable(); // criptografado
            $t->string('status', 20)->default('active'); // active | suspended | canceled
            $t->string('status_reason', 255)->nullable();
            $t->timestamp('canceled_at')->nullable();
            $t->timestamp('purge_after')->nullable(); // LGPD: exclusão definitiva agendada
            $t->timestamps();
            $t->index('status');
        });

        Schema::create('users', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('name', 160);
            $t->string('email', 190)->unique();
            $t->string('password');
            $t->boolean('is_platform_admin')->default(false);
            $t->string('status', 20)->default('active'); // active | blocked
            $t->text('two_factor_secret')->nullable();
            $t->text('two_factor_recovery_codes')->nullable();
            $t->timestamp('two_factor_confirmed_at')->nullable();
            $t->unsignedSmallInteger('failed_logins')->default(0);
            $t->timestamp('locked_until')->nullable();
            $t->timestamp('last_login_at')->nullable();
            $t->string('last_login_ip', 45)->nullable();
            $t->rememberToken();
            $t->timestamps();
        });

        // Vínculo usuário ↔ cliente com papel. Um contador pode atender vários clientes com um único login.
        Schema::create('memberships', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('account_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $t->string('role', 20); // admin | gestor | financeiro | consulta | contador
            $t->boolean('all_companies')->default(true); // false = só as empresas em company_access
            $t->string('status', 20)->default('active');
            $t->timestamps();
            $t->unique(['account_id', 'user_id']);
            $t->unique(['account_id', 'id']);
        });

        Schema::create('companies', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('account_id')->constrained()->cascadeOnDelete();
            $t->string('name', 160);
            $t->string('alias', 60)->nullable(); // apelido usado no WhatsApp ("empresa A", "loja")
            $t->string('person_type', 2)->default('pj');
            $t->text('document')->nullable(); // criptografado
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['account_id', 'id']);
            $t->index(['account_id', 'active']);
        });

        Schema::create('company_access', function (Blueprint $t) {
            $t->id();
            $t->ulid('account_id');
            $t->ulid('membership_id');
            $t->ulid('company_id');
            $t->timestamps();
            $t->unique(['membership_id', 'company_id']);
            $t->foreign(['account_id', 'membership_id'])->references(['account_id', 'id'])->on('memberships')->cascadeOnDelete();
            $t->foreign(['account_id', 'company_id'])->references(['account_id', 'id'])->on('companies')->cascadeOnDelete();
        });

        Schema::create('subscriptions', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('account_id')->unique()->constrained()->cascadeOnDelete();
            $t->foreignUlid('plan_id')->constrained();
            $t->string('cycle', 10)->default('monthly'); // monthly | yearly
            $t->string('status', 20); // trialing | active | past_due | suspended | canceled
            $t->unsignedBigInteger('price_cents');
            $t->timestamp('trial_ends_at')->nullable();
            $t->date('current_period_end')->nullable();
            $t->date('past_due_since')->nullable();
            $t->boolean('cancel_at_period_end')->default(false);
            $t->string('gateway', 20)->default('mock');
            $t->string('gateway_customer_id', 80)->nullable();
            $t->string('gateway_subscription_id', 80)->nullable()->unique();
            $t->timestamps();
            $t->index('status');
        });

        Schema::create('invoices', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('account_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('subscription_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('amount_cents');
            $t->date('due_date');
            $t->string('status', 20); // pending | paid | overdue | canceled | refunded
            $t->string('billing_type', 20)->nullable(); // PIX | BOLETO | CREDIT_CARD | UNDEFINED | MANUAL
            $t->string('gateway_payment_id', 80)->nullable()->unique();
            $t->string('invoice_url', 500)->nullable();
            $t->string('description', 255);
            $t->timestamp('paid_at')->nullable();
            $t->string('paid_note', 255)->nullable();
            $t->timestamps();
            $t->index(['status', 'due_date']);
        });

        // Histórico de assinatura (append-only).
        Schema::create('subscription_events', function (Blueprint $t) {
            $t->id();
            $t->foreignUlid('account_id')->constrained()->cascadeOnDelete();
            $t->string('event', 40);
            $t->string('from_status', 20)->nullable();
            $t->string('to_status', 20)->nullable();
            $t->json('data')->nullable();
            $t->ulid('user_id')->nullable();
            $t->timestamp('created_at')->useCurrent();
        });

        // Idempotência dos webhooks (Asaas e WhatsApp).
        Schema::create('webhook_events', function (Blueprint $t) {
            $t->id();
            $t->string('source', 20);
            $t->string('event_id', 120);
            $t->string('event', 60)->nullable();
            $t->timestamp('processed_at')->nullable();
            $t->timestamps();
            $t->unique(['source', 'event_id']);
        });

        Schema::create('platform_settings', function (Blueprint $t) {
            $t->string('key', 80)->primary();
            $t->text('value')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['platform_settings', 'webhook_events', 'subscription_events', 'invoices', 'subscriptions', 'company_access', 'companies', 'memberships', 'users', 'accounts', 'plans'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
