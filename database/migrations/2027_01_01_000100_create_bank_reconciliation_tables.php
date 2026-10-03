<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 14 — conciliação bancária.
 *
 * O livro financeiro continua imutável: a conciliação só VINCULA linhas do extrato a
 * lançamentos (bank_line_matches). Desfazer um vínculo não apaga nada (undone_at).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('company_id', 26);
            $table->char('branch_id', 26)->nullable();
            $table->string('name', 80);
            $table->string('bank_code', 10)->nullable()->comment('Código COMPE/ISPB');
            $table->string('agency', 20)->nullable();
            $table->string('account_number', 30)->nullable();
            $table->string('sync_provider', 20)->default('none')->comment('none, pluggy (Open Finance via agregador)');
            $table->string('external_account_id', 100)->nullable();
            $table->text('credentials')->nullable()->comment('Criptografado');
            $table->dateTime('last_synced_at')->nullable();
            $table->string('sync_error', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->datetimes();

            $table->unique(['company_id', 'id']);
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'branch_id'])->references(['company_id', 'id'])->on('branches')->restrictOnDelete();
        });

        Schema::create('bank_statements', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('company_id', 26);
            $table->char('bank_account_id', 26);
            $table->string('source', 20)->comment('ofx, csv, openfinance');
            $table->string('filename', 191)->nullable();
            $table->char('sha256', 64)->nullable();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->bigInteger('balance_cents')->nullable()->comment('Saldo informado pelo banco');
            $table->date('balance_date')->nullable();
            $table->unsignedInteger('lines_total')->default(0);
            $table->unsignedInteger('lines_new')->default(0);
            $table->char('imported_by', 26)->nullable();
            $table->dateTime('created_at');

            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'bank_account_id', 'created_at'], 'bank_statements_account_idx');
            $table->foreign(['company_id', 'bank_account_id'])->references(['company_id', 'id'])->on('bank_accounts')->restrictOnDelete();
        });

        Schema::create('bank_statement_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('company_id', 26);
            $table->char('bank_account_id', 26);
            $table->char('statement_id', 26);
            $table->string('dedupe_key', 120)->comment('fitid:… ou hash de data+valor+descrição+ordem');
            $table->date('posted_on');
            $table->bigInteger('amount_cents')->comment('Positivo = crédito, negativo = débito');
            $table->string('description', 255);
            $table->string('reference', 100)->nullable()->comment('FITID, documento, ID do Open Finance');
            $table->string('status', 20)->default('pending')->comment('pending, reconciled, ignored');
            $table->string('notes', 500)->nullable();
            $table->char('resolved_by', 26)->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->datetimes();

            $table->unique(['company_id', 'id']);
            $table->unique(['bank_account_id', 'dedupe_key']);
            $table->index(['company_id', 'bank_account_id', 'status', 'posted_on'], 'bank_lines_account_status_idx');
            $table->foreign(['company_id', 'bank_account_id'])->references(['company_id', 'id'])->on('bank_accounts')->restrictOnDelete();
            $table->foreign(['company_id', 'statement_id'])->references(['company_id', 'id'])->on('bank_statements')->restrictOnDelete();
        });

        Schema::create('bank_line_matches', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('company_id', 26);
            $table->char('line_id', 26);
            $table->char('transaction_id', 26);
            $table->string('origin', 20)->default('manual')->comment('manual, auto, created');
            $table->char('matched_by', 26)->nullable();
            $table->dateTime('undone_at')->nullable();
            $table->char('undone_by', 26)->nullable();
            $table->datetimes();
            // Um lançamento só pode estar conciliado com UMA linha ativa (vínculos desfeitos não contam).
            $table->string('active_transaction', 26)->nullable()->storedAs('CASE WHEN undone_at IS NULL THEN RTRIM(transaction_id) END');

            $table->unique(['company_id', 'active_transaction'], 'bank_line_matches_one_active');
            $table->index(['company_id', 'line_id']);
            $table->foreign(['company_id', 'line_id'])->references(['company_id', 'id'])->on('bank_statement_lines')->restrictOnDelete();
            $table->foreign(['company_id', 'transaction_id'])->references(['company_id', 'id'])->on('financial_transactions')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE bank_accounts ADD CONSTRAINT bank_accounts_check CHECK (sync_provider IN ('none','pluggy'))");
        DB::statement("ALTER TABLE bank_statements ADD CONSTRAINT bank_statements_check CHECK (source IN ('ofx','csv','openfinance'))");
        DB::statement("ALTER TABLE bank_statement_lines ADD CONSTRAINT bank_statement_lines_check CHECK (amount_cents <> 0 AND status IN ('pending','reconciled','ignored'))");
        DB::statement("ALTER TABLE bank_line_matches ADD CONSTRAINT bank_line_matches_check CHECK (origin IN ('manual','auto','created'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_line_matches');
        Schema::dropIfExists('bank_statement_lines');
        Schema::dropIfExists('bank_statements');
        Schema::dropIfExists('bank_accounts');
    }
};
