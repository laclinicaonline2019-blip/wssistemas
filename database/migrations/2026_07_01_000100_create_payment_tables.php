<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Fase 8 — pagamentos online (ASAAS, Cielo, MOCK), webhooks, split e repasses.
 *
 * Uma cobrança só é dada como paga quando: (1) chega um webhook AUTENTICADO e
 * (2) a consulta ativa à API do gateway confirma status e valor. Webhooks são
 * idempotentes (provider_event_id único). Credenciais ficam criptografadas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_gateways', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('company_id', 26);
            $table->string('provider', 20)->comment('asaas, cielo, mock');
            $table->string('mode', 20)->default('sandbox')->comment('mock, sandbox, production');
            $table->string('name', 80);
            $table->text('credentials')->nullable()->comment('Criptografado com APP_KEY');
            $table->text('webhook_token')->comment('Criptografado — enviado pelo gateway no webhook');
            $table->json('settings')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->datetimes();

            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'provider', 'mode']);
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
        });

        Schema::create('payment_customers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('company_id', 26);
            $table->char('gateway_id', 26);
            $table->char('patient_id', 26);
            $table->string('provider_customer_id', 100);
            $table->datetimes();

            $table->unique(['gateway_id', 'patient_id']);
            $table->foreign(['company_id', 'gateway_id'])->references(['company_id', 'id'])->on('payment_gateways')->cascadeOnDelete();
            $table->foreign(['company_id', 'patient_id'])->references(['company_id', 'id'])->on('patients')->restrictOnDelete();
        });

        Schema::create('payment_charges', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('company_id', 26);
            $table->char('branch_id', 26);
            $table->char('receivable_id', 26);
            $table->char('gateway_id', 26);
            $table->string('provider', 20);
            $table->string('mode', 20);
            $table->char('patient_id', 26)->nullable();
            $table->bigInteger('amount_cents');
            $table->string('billing_type', 20)->comment('pix, boleto, credit_card, undefined (cliente escolhe)');
            $table->string('status', 20)->default('pending');
            $table->string('provider_charge_id', 100)->nullable();
            $table->string('payment_url', 500)->nullable();
            $table->text('pix_payload')->nullable();
            $table->longText('pix_qr_image')->nullable()->comment('PNG base64 devolvido pelo gateway');
            $table->date('due_date');
            $table->string('idempotency_key', 64);
            $table->string('public_token', 40)->unique();
            $table->bigInteger('paid_cents')->nullable();
            $table->bigInteger('net_cents')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->char('transaction_id', 26)->nullable()->comment('Recebimento lançado no livro');
            $table->json('split_snapshot')->nullable();
            $table->string('review_reason', 255)->nullable()->comment('Divergência que exige ação humana');
            $table->dateTime('last_checked_at')->nullable();
            $table->char('created_by', 26)->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->datetimes();

            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'idempotency_key']);
            $table->unique(['gateway_id', 'provider_charge_id']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'receivable_id']);
            $table->foreign(['company_id', 'receivable_id'])->references(['company_id', 'id'])->on('receivables')->restrictOnDelete();
            $table->foreign(['company_id', 'gateway_id'])->references(['company_id', 'id'])->on('payment_gateways')->restrictOnDelete();
            $table->foreign(['company_id', 'branch_id'])->references(['company_id', 'id'])->on('branches')->restrictOnDelete();
            $table->foreign(['company_id', 'transaction_id'])->references(['company_id', 'id'])->on('financial_transactions')->restrictOnDelete();
        });

        // Eventos recebidos dos gateways (idempotência + diagnóstico). Sem dados de cartão.
        Schema::create('payment_webhook_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('company_id', 26)->nullable();
            $table->char('gateway_id', 26)->nullable();
            $table->string('provider', 20);
            $table->string('provider_event_id', 150);
            $table->string('event_type', 80)->nullable();
            $table->string('provider_charge_id', 100)->nullable();
            $table->json('payload')->nullable();
            $table->string('status', 20)->default('received')->comment('received, processed, ignored, failed');
            $table->string('error', 500)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->dateTime('received_at');
            $table->dateTime('processed_at')->nullable();

            $table->unique(['provider', 'provider_event_id']);
            $table->index(['company_id', 'received_at']);
        });

        Schema::create('split_rules', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('company_id', 26);
            $table->char('doctor_id', 26);
            $table->char('doctor_service_id', 26)->nullable()->comment('Tipo de atendimento (vazio = todos)');
            $table->string('payer_type', 20)->nullable()->comment('private, insurance (vazio = todos)');
            $table->string('type', 10)->comment('percent, fixed');
            $table->unsignedInteger('value')->comment('percent: centésimos de % (6000 = 60%); fixed: centavos');
            $table->boolean('is_active')->default(true);
            $table->datetimes();

            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'doctor_id', 'is_active']);
            $table->foreign(['company_id', 'doctor_id'])->references(['company_id', 'id'])->on('doctors')->cascadeOnDelete();
            $table->foreign(['company_id', 'doctor_service_id'])->references(['company_id', 'id'])->on('doctor_services')->cascadeOnDelete();
        });

        Schema::create('payment_splits', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('company_id', 26);
            $table->char('doctor_id', 26);
            $table->char('receivable_id', 26)->nullable();
            $table->char('transaction_id', 26)->comment('Recebimento (ou estorno, quando negativo)');
            $table->char('charge_id', 26)->nullable();
            $table->char('split_rule_id', 26)->nullable();
            $table->bigInteger('base_cents');
            $table->bigInteger('amount_cents')->comment('Parte do médico (negativo = devolução por estorno)');
            $table->string('mode', 10)->comment('native (gateway já separou), internal (repasse a pagar)');
            $table->string('status', 20)->default('pending')->comment('pending, settled, reversed');
            $table->char('payable_id', 26)->nullable()->comment('Conta a pagar do repasse');
            $table->dateTime('settled_at')->nullable();
            $table->datetimes();

            $table->unique(['transaction_id', 'doctor_id']);
            $table->index(['company_id', 'doctor_id', 'status']);
            $table->foreign(['company_id', 'doctor_id'])->references(['company_id', 'id'])->on('doctors')->restrictOnDelete();
            $table->foreign(['company_id', 'transaction_id'])->references(['company_id', 'id'])->on('financial_transactions')->restrictOnDelete();
            $table->foreign(['company_id', 'payable_id'])->references(['company_id', 'id'])->on('payables')->restrictOnDelete();
        });

        Schema::table('doctors', function (Blueprint $table) {
            $table->string('asaas_wallet_id', 64)->nullable()->comment('Carteira ASAAS para split nativo');
        });

        // Novo tipo de movimentação: tarifa cobrada pelo gateway.
        DB::statement('ALTER TABLE financial_transactions DROP CONSTRAINT financial_transactions_check');
        DB::statement("ALTER TABLE financial_transactions ADD CONSTRAINT financial_transactions_check CHECK (amount_cents > 0 AND direction IN ('in','out') AND kind IN ('receipt','payment','withdrawal','deposit','reversal','fee'))");
        DB::statement("ALTER TABLE payment_gateways ADD CONSTRAINT payment_gateways_check CHECK (provider IN ('asaas','cielo','mock') AND mode IN ('mock','sandbox','production'))");
        DB::statement("ALTER TABLE payment_charges ADD CONSTRAINT payment_charges_check CHECK (amount_cents > 0 AND status IN ('pending','paid','overdue','cancelled','refunded','failed','review'))");
        DB::statement("ALTER TABLE split_rules ADD CONSTRAINT split_rules_check CHECK (type IN ('percent','fixed') AND (type <> 'percent' OR value <= 10000))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE financial_transactions DROP CONSTRAINT financial_transactions_check');
        DB::statement("ALTER TABLE financial_transactions ADD CONSTRAINT financial_transactions_check CHECK (amount_cents > 0 AND direction IN ('in','out') AND kind IN ('receipt','payment','withdrawal','deposit','reversal'))");
        Schema::table('doctors', fn (Blueprint $table) => $table->dropColumn('asaas_wallet_id'));
        Schema::dropIfExists('payment_splits');
        Schema::dropIfExists('split_rules');
        Schema::dropIfExists('payment_webhook_events');
        Schema::dropIfExists('payment_charges');
        Schema::dropIfExists('payment_customers');
        Schema::dropIfExists('payment_gateways');
    }
};
