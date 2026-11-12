<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Financeiro por empresa: contas bancárias, lançamentos (receitas/despesas, recorrentes e parcelados),
 * dívidas e configurações do plano de quitação. Valores sempre em centavos (inteiros).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('account_id');
            $t->ulid('company_id');
            $t->string('name', 120);
            $t->string('bank', 120)->nullable();
            $t->bigInteger('opening_balance_cents')->default(0);
            $t->date('opening_date')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['account_id', 'id']);
            $t->unique(['company_id', 'id']);
            $t->foreign(['account_id', 'company_id'])->references(['account_id', 'id'])->on('companies')->cascadeOnDelete();
        });

        Schema::create('entries', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('account_id');
            $t->ulid('company_id');
            $t->string('type', 10); // receita | despesa
            $t->string('description', 200);
            $t->unsignedBigInteger('amount_cents');
            $t->string('category', 60)->nullable();
            $t->char('competence', 7); // AAAA-MM
            $t->date('due_date'); // vencimento (despesa) ou data (receita)
            $t->boolean('paid')->default(false);
            $t->date('paid_at')->nullable();
            $t->ulid('bank_account_id')->nullable();
            $t->ulid('recurrence_group')->nullable(); // fixa (repetida) ou parcelada
            $t->string('recurrence_kind', 12)->nullable(); // fixa | parcelada
            $t->unsignedSmallInteger('installment_no')->nullable();
            $t->unsignedSmallInteger('installments_total')->nullable();
            $t->string('source', 12)->default('web'); // web | whatsapp | import | exemplo
            $t->boolean('is_sample')->default(false);
            $t->string('external_ref', 80)->nullable(); // id do armazenamento local importado
            $t->ulid('created_by')->nullable();
            $t->timestamps();
            $t->softDeletes(); // cancelamentos ficam registrados (auditoria)

            $t->foreign(['account_id', 'company_id'])->references(['account_id', 'id'])->on('companies')->cascadeOnDelete();
            $t->foreign(['company_id', 'bank_account_id'])->references(['company_id', 'id'])->on('bank_accounts');
            $t->index(['company_id', 'competence']);
            $t->index(['company_id', 'type', 'paid', 'due_date']);
            $t->index(['account_id', 'competence']);
            $t->index('recurrence_group');
            $t->unique(['company_id', 'external_ref']);
        });

        Schema::create('debts', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('account_id');
            $t->ulid('company_id');
            $t->string('creditor', 160);
            $t->unsignedBigInteger('balance_cents');
            $t->decimal('monthly_rate', 7, 3)->default(0); // % ao mês
            $t->unsignedBigInteger('min_payment_cents')->default(0);
            $t->boolean('is_sample')->default(false);
            $t->string('external_ref', 80)->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->foreign(['account_id', 'company_id'])->references(['account_id', 'id'])->on('companies')->cascadeOnDelete();
            $t->unique(['company_id', 'external_ref']);
        });

        Schema::create('company_settings', function (Blueprint $t) {
            $t->ulid('company_id')->primary();
            $t->ulid('account_id');
            $t->unsignedBigInteger('debt_budget_cents')->default(0);
            $t->string('debt_strategy', 12)->default('avalanche'); // avalanche | bola
            $t->timestamps();
            $t->foreign(['account_id', 'company_id'])->references(['account_id', 'id'])->on('companies')->cascadeOnDelete();
        });

        // Importações do armazenamento local do navegador (registro e prevenção de duplicidade).
        Schema::create('local_imports', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('account_id');
            $t->ulid('company_id');
            $t->ulid('user_id')->nullable();
            $t->char('checksum', 64);
            $t->json('summary');
            $t->timestamps();
            $t->foreign(['account_id', 'company_id'])->references(['account_id', 'id'])->on('companies')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        foreach (['local_imports', 'company_settings', 'debts', 'entries', 'bank_accounts'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
