<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/*
 * Fase 7 — financeiro: categorias, contas a receber/pagar, caixa e livro de
 * movimentações.
 *
 * Valores SEMPRE em centavos (inteiros). Movimentações financeiras formam um livro
 * IMUTÁVEL: nada é editado ou apagado; erro se corrige com ESTORNO (lançamento
 * inverso vinculado ao original). Saldos de contas e caixas são derivados dele.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_categories', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('company_id', 26);
            $table->string('type', 10)->comment('income, expense');
            $table->string('name', 80);
            $table->boolean('is_active')->default(true);
            $table->datetimes();

            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'type', 'name']);
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
        });

        Schema::create('receivables', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('company_id', 26);
            $table->char('branch_id', 26);
            $table->char('category_id', 26);
            $table->char('patient_id', 26)->nullable();
            $table->char('appointment_id', 26)->nullable();
            $table->char('doctor_id', 26)->nullable();
            $table->string('description', 200);
            $table->string('origin', 20)->default('manual')->comment('manual, appointment, gateway');
            $table->string('payer_type', 20)->default('private');
            $table->bigInteger('amount_cents');
            $table->bigInteger('discount_cents')->default(0);
            $table->bigInteger('paid_cents')->default(0);
            $table->date('due_date');
            $table->string('status', 20)->default('open');
            $table->dateTime('paid_at')->nullable();
            $table->string('notes', 500)->nullable();
            $table->char('created_by', 26)->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->char('cancelled_by', 26)->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->datetimes();

            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'appointment_id']);
            $table->index(['company_id', 'status', 'due_date']);
            $table->index(['company_id', 'patient_id']);
            $table->foreign(['company_id', 'branch_id'])->references(['company_id', 'id'])->on('branches')->restrictOnDelete();
            $table->foreign(['company_id', 'category_id'])->references(['company_id', 'id'])->on('financial_categories')->restrictOnDelete();
            $table->foreign(['company_id', 'patient_id'])->references(['company_id', 'id'])->on('patients')->restrictOnDelete();
            $table->foreign(['company_id', 'appointment_id'])->references(['company_id', 'id'])->on('appointments')->restrictOnDelete();
            $table->foreign(['company_id', 'doctor_id'])->references(['company_id', 'id'])->on('doctors')->restrictOnDelete();
        });

        Schema::create('payables', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('company_id', 26);
            $table->char('branch_id', 26)->nullable();
            $table->char('category_id', 26);
            $table->string('supplier', 150);
            $table->string('description', 200);
            $table->string('document_number', 60)->nullable()->comment('NF, boleto, contrato');
            $table->bigInteger('amount_cents');
            $table->bigInteger('paid_cents')->default(0);
            $table->date('due_date');
            $table->string('status', 20)->default('open');
            $table->char('installment_group', 26)->nullable();
            $table->unsignedSmallInteger('installment')->default(1);
            $table->unsignedSmallInteger('installments')->default(1);
            $table->dateTime('paid_at')->nullable();
            $table->string('notes', 500)->nullable();
            $table->char('created_by', 26)->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->char('cancelled_by', 26)->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->datetimes();

            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'status', 'due_date']);
            $table->foreign(['company_id', 'branch_id'])->references(['company_id', 'id'])->on('branches')->restrictOnDelete();
            $table->foreign(['company_id', 'category_id'])->references(['company_id', 'id'])->on('financial_categories')->restrictOnDelete();
        });

        Schema::create('cash_sessions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('company_id', 26);
            $table->char('branch_id', 26);
            $table->char('user_id', 26)->comment('Operador');
            $table->string('status', 20)->default('open')->comment('open, closed, reviewed');
            $table->dateTime('opened_at');
            $table->bigInteger('opening_cents')->default(0)->comment('Fundo de troco');
            $table->dateTime('closed_at')->nullable();
            $table->json('expected')->nullable()->comment('Esperado por forma de pagamento no fechamento');
            $table->json('declared')->nullable()->comment('Contado/declarado pelo operador (fechamento cego)');
            $table->bigInteger('difference_cents')->nullable()->comment('Soma das diferenças (declarado − esperado)');
            $table->string('closing_notes', 500)->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->char('reviewed_by', 26)->nullable();
            $table->string('review_notes', 500)->nullable();
            $table->datetimes();
            // Um único caixa aberto por operador (NULL não colide).
            $table->string('open_user', 26)->nullable()->storedAs("CASE WHEN status = 'open' THEN RTRIM(user_id) END");

            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'open_user'], 'cash_sessions_one_open_per_user');
            $table->index(['company_id', 'branch_id', 'opened_at']);
            $table->foreign(['company_id', 'branch_id'])->references(['company_id', 'id'])->on('branches')->restrictOnDelete();
            $table->foreign(['company_id', 'user_id'])->references(['company_id', 'id'])->on('users')->restrictOnDelete();
        });

        // Livro de movimentações (imutável).
        Schema::create('financial_transactions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('company_id', 26);
            $table->char('branch_id', 26);
            $table->string('direction', 3)->comment('in, out');
            $table->string('kind', 20)->comment('receipt, payment, withdrawal, deposit, reversal');
            $table->string('method', 20);
            $table->bigInteger('amount_cents');
            $table->char('receivable_id', 26)->nullable();
            $table->char('payable_id', 26)->nullable();
            $table->char('cash_session_id', 26)->nullable();
            $table->char('reversal_of', 26)->nullable()->unique();
            $table->unsignedSmallInteger('card_installments')->nullable();
            $table->string('card_brand', 30)->nullable();
            $table->string('authorization_code', 60)->nullable()->comment('NSU / autorização / ID do PIX');
            $table->string('gateway', 30)->nullable()->comment('Fase 8: asaas, cielo…');
            $table->string('gateway_reference', 100)->nullable();
            $table->string('description', 255)->nullable();
            $table->dateTime('occurred_at');
            $table->char('created_by', 26)->nullable();
            $table->dateTime('created_at');

            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'occurred_at']);
            $table->index(['company_id', 'cash_session_id']);
            $table->index(['company_id', 'receivable_id']);
            $table->index(['company_id', 'payable_id']);
            $table->foreign(['company_id', 'branch_id'])->references(['company_id', 'id'])->on('branches')->restrictOnDelete();
            $table->foreign(['company_id', 'receivable_id'])->references(['company_id', 'id'])->on('receivables')->restrictOnDelete();
            $table->foreign(['company_id', 'payable_id'])->references(['company_id', 'id'])->on('payables')->restrictOnDelete();
            $table->foreign(['company_id', 'cash_session_id'])->references(['company_id', 'id'])->on('cash_sessions')->restrictOnDelete();
            $table->foreign(['company_id', 'reversal_of'])->references(['company_id', 'id'])->on('financial_transactions')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE financial_categories ADD CONSTRAINT financial_categories_type_check CHECK (type IN ('income','expense'))");
        DB::statement('ALTER TABLE receivables ADD CONSTRAINT receivables_values_check CHECK (amount_cents > 0 AND discount_cents >= 0 AND paid_cents >= 0 AND discount_cents + paid_cents <= amount_cents)');
        DB::statement("ALTER TABLE receivables ADD CONSTRAINT receivables_status_check CHECK (status IN ('open','partial','paid','cancelled'))");
        DB::statement('ALTER TABLE payables ADD CONSTRAINT payables_values_check CHECK (amount_cents > 0 AND paid_cents >= 0 AND paid_cents <= amount_cents)');
        DB::statement("ALTER TABLE payables ADD CONSTRAINT payables_status_check CHECK (status IN ('open','partial','paid','cancelled'))");
        DB::statement("ALTER TABLE cash_sessions ADD CONSTRAINT cash_sessions_status_check CHECK (status IN ('open','closed','reviewed') AND opening_cents >= 0)");
        DB::statement("ALTER TABLE financial_transactions ADD CONSTRAINT financial_transactions_check CHECK (amount_cents > 0 AND direction IN ('in','out') AND kind IN ('receipt','payment','withdrawal','deposit','reversal'))");

        self::protectLedger();
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS financial_transactions_immutable ON financial_transactions');
            DB::unprepared('DROP FUNCTION IF EXISTS financial_ledger_block_mutation()');
        } else {
            DB::unprepared('DROP TRIGGER IF EXISTS financial_transactions_no_upd');
            DB::unprepared('DROP TRIGGER IF EXISTS financial_transactions_no_del');
        }

        Schema::dropIfExists('financial_transactions');
        Schema::dropIfExists('cash_sessions');
        Schema::dropIfExists('payables');
        Schema::dropIfExists('receivables');
        Schema::dropIfExists('financial_categories');
    }

    /** Bloqueio de UPDATE/DELETE no livro quando o banco permitir (PostgreSQL sempre; MySQL com privilégio). */
    private static function protectLedger(): void
    {
        try {
            if (DB::getDriverName() === 'pgsql') {
                DB::unprepared(<<<'SQL'
                    CREATE OR REPLACE FUNCTION financial_ledger_block_mutation() RETURNS trigger AS $$
                    BEGIN
                        RAISE EXCEPTION 'Movimentação financeira imutável: use estorno';
                    END;
                    $$ LANGUAGE plpgsql;
                    CREATE TRIGGER financial_transactions_immutable BEFORE UPDATE OR DELETE ON financial_transactions
                        FOR EACH ROW EXECUTE FUNCTION financial_ledger_block_mutation();
                SQL);
            } else {
                foreach (['UPDATE' => 'upd', 'DELETE' => 'del'] as $event => $suffix) {
                    DB::unprepared("CREATE TRIGGER financial_transactions_no_{$suffix} BEFORE {$event} ON financial_transactions FOR EACH ROW
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Movimentacao financeira imutavel: use estorno'");
                }
            }
        } catch (Throwable $e) {
            Log::warning('Trigger de imutabilidade do livro financeiro não criado: '.$e->getMessage());
        }
    }
};
