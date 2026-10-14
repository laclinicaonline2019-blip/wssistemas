<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Melhor Sprint — sistema de split de pagamento.
 *
 * Uma VENDA tem itens, cada um de um profissional (médico ou parceiro) com a regra de divisão
 * (percentual ou valor fixo). Os PAGAMENTOS (dinheiro, PIX, crédito, débito — ASAAS, Cielo ou
 * maquininha) podem ser mistos. Cada pagamento confirmado gera os lançamentos de DIVISÃO:
 * parte de cada profissional, parte da clínica (casa), comissão da plataforma e tarifa do
 * gateway. A NFS-e (opcional) é emitida sobre a parte da clínica, não sobre o total.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Comissão própria da clínica (definida só pelo Super Admin; nula = taxa padrão).
        Schema::table('companies', function (Blueprint $table) {
            $table->unsignedInteger('sprint_commission_bp')->nullable()->comment('Comissão Melhor Sprint em centésimos de %');
        });

        Schema::create('sprint_professionals', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('company_id', 26);
            $table->char('doctor_id', 26)->nullable()->comment('Vínculo opcional com o cadastro de médicos');
            $table->string('kind', 20)->default('partner')->comment('doctor (médico), partner (parceiro)');
            $table->string('name', 150);
            $table->string('document', 14)->nullable()->comment('CPF ou CNPJ (só dígitos)');
            $table->string('email', 150)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('pix_key', 140)->nullable();
            $table->string('bank_info', 255)->nullable();
            $table->string('asaas_wallet_id', 64)->nullable()->comment('Carteira ASAAS — split nativo');
            $table->string('cielo_subordinate_id', 36)->nullable()->comment('SubordinateMerchantId — split Cielo');
            $table->string('split_type', 10)->default('percent')->comment('percent, fixed');
            $table->unsignedInteger('split_value')->default(0)->comment('percent: centésimos de % (6000 = 60%); fixed: centavos');
            $table->string('notes', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->datetimes();

            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'is_active']);
            $table->foreign(['company_id', 'doctor_id'])->references(['company_id', 'id'])->on('doctors')->restrictOnDelete();
        });

        Schema::create('sprint_settings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('company_id', 26)->unique();
            $table->char('pix_gateway_id', 26)->nullable()->comment('Gateway do PIX/cartão por link (ASAAS)');
            $table->char('card_gateway_id', 26)->nullable()->comment('Gateway do cartão digitado (Cielo API)');
            $table->boolean('nfse_enabled')->default(false);
            $table->boolean('nfse_auto')->default(false)->comment('Emitir automaticamente quando a venda for quitada');
            $table->char('nfse_gateway_id', 26)->nullable();
            $table->string('nfse_service_code', 30)->nullable()->comment('Código do serviço municipal (LC 116)');
            $table->string('nfse_service_name', 255)->nullable();
            $table->unsignedInteger('nfse_iss_bp')->default(0)->comment('Alíquota de ISS em centésimos de % (200 = 2%)');
            $table->string('nfse_description', 500)->nullable();
            $table->datetimes();

            $table->unique(['company_id', 'id']);
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
        });

        Schema::create('sprint_sales', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('company_id', 26);
            $table->char('branch_id', 26);
            $table->unsignedInteger('number');
            $table->char('patient_id', 26)->nullable();
            $table->string('customer_name', 150)->nullable();
            $table->string('customer_document', 14)->nullable();
            $table->string('description', 200);
            $table->char('receivable_id', 26)->comment('Conta a receber no financeiro');
            $table->bigInteger('total_cents');
            $table->bigInteger('professional_cents')->comment('Soma das partes dos profissionais');
            $table->bigInteger('house_cents')->comment('Parte da clínica (antes de tarifa e comissão)');
            $table->unsignedInteger('commission_bp')->default(0)->comment('Comissão da plataforma na data da venda');
            $table->string('status', 20)->default('open')->comment('open, partial, paid, cancelled, refunded');
            $table->boolean('issue_nfse')->default(false);
            $table->char('created_by', 26)->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->datetimes();

            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'number']);
            $table->unique(['company_id', 'receivable_id']);
            $table->index(['company_id', 'status', 'created_at']);
            $table->foreign(['company_id', 'branch_id'])->references(['company_id', 'id'])->on('branches')->restrictOnDelete();
            $table->foreign(['company_id', 'patient_id'])->references(['company_id', 'id'])->on('patients')->restrictOnDelete();
            $table->foreign(['company_id', 'receivable_id'])->references(['company_id', 'id'])->on('receivables')->restrictOnDelete();
        });

        Schema::create('sprint_sale_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('company_id', 26);
            $table->char('sale_id', 26);
            $table->char('professional_id', 26)->nullable()->comment('Vazio = item 100% da clínica');
            $table->string('description', 200);
            $table->bigInteger('amount_cents');
            $table->string('split_type', 10)->nullable();
            $table->unsignedInteger('split_value')->nullable();
            $table->bigInteger('professional_cents')->default(0);
            $table->bigInteger('house_cents');
            $table->datetimes();

            $table->index(['company_id', 'sale_id']);
            $table->foreign(['company_id', 'sale_id'])->references(['company_id', 'id'])->on('sprint_sales')->cascadeOnDelete();
            $table->foreign(['company_id', 'professional_id'])->references(['company_id', 'id'])->on('sprint_professionals')->restrictOnDelete();
        });

        Schema::create('sprint_payments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('company_id', 26);
            $table->char('sale_id', 26);
            $table->string('method', 20)->comment('cash, pix, credit_card, debit_card');
            $table->string('channel', 20)->comment('cash, asaas, cielo_api, cielo_terminal, terminal, mock');
            $table->bigInteger('amount_cents');
            $table->string('status', 20)->default('pending')->comment('pending, paid, cancelled, failed, refunded');
            $table->char('charge_id', 26)->nullable()->comment('Cobrança online (PIX/cartão)');
            $table->char('transaction_id', 26)->nullable()->comment('Recebimento no livro financeiro');
            $table->json('allocation')->nullable()->comment('Divisão planejada: profissionais, comissão e split nativo');
            $table->string('card_brand', 30)->nullable();
            $table->unsignedSmallInteger('installments')->nullable();
            $table->string('nsu', 60)->nullable();
            $table->boolean('terminal_split')->default(false)->comment('Maquininha Cielo já dividiu a venda');
            $table->bigInteger('cash_given_cents')->nullable();
            $table->bigInteger('change_cents')->nullable();
            $table->string('idempotency_key', 64);
            $table->char('created_by', 26)->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->datetimes();

            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'idempotency_key']);
            $table->index(['company_id', 'sale_id']);
            $table->index(['company_id', 'charge_id']);
            $table->foreign(['company_id', 'sale_id'])->references(['company_id', 'id'])->on('sprint_sales')->restrictOnDelete();
            $table->foreign(['company_id', 'charge_id'])->references(['company_id', 'id'])->on('payment_charges')->restrictOnDelete();
            $table->foreign(['company_id', 'transaction_id'])->references(['company_id', 'id'])->on('financial_transactions')->restrictOnDelete();
        });

        // Livro da divisão: uma linha por parte de cada pagamento (positivo) ou devolução (negativo).
        Schema::create('sprint_splits', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('company_id', 26);
            $table->char('sale_id', 26);
            $table->char('payment_id', 26)->nullable();
            $table->char('transaction_id', 26)->comment('Recebimento (ou estorno)');
            $table->string('party', 20)->comment('professional, house, platform, fee');
            $table->char('professional_id', 26)->nullable();
            $table->bigInteger('amount_cents');
            $table->string('mode', 10)->comment('native (gateway já separou), internal (a repassar/cobrar)');
            $table->string('status', 20)->comment('pending, settled, reversed, to_invoice, invoiced');
            $table->string('source', 20)->nullable()->comment('asaas, cielo_api, cielo_terminal, mock');
            $table->char('payable_id', 26)->nullable()->comment('Conta a pagar do repasse');
            $table->dateTime('settled_at')->nullable();
            $table->datetimes();

            $table->index(['company_id', 'sale_id']);
            $table->index(['company_id', 'party', 'status', 'created_at']);
            $table->index(['company_id', 'professional_id', 'status']);
            $table->index(['company_id', 'transaction_id']);
            $table->foreign(['company_id', 'sale_id'])->references(['company_id', 'id'])->on('sprint_sales')->restrictOnDelete();
            $table->foreign(['company_id', 'transaction_id'])->references(['company_id', 'id'])->on('financial_transactions')->restrictOnDelete();
            $table->foreign(['company_id', 'professional_id'])->references(['company_id', 'id'])->on('sprint_professionals')->restrictOnDelete();
            $table->foreign(['company_id', 'payable_id'])->references(['company_id', 'id'])->on('payables')->restrictOnDelete();
        });

        Schema::create('sprint_invoices', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('company_id', 26);
            $table->char('sale_id', 26);
            $table->string('provider', 20)->comment('asaas, mock');
            $table->string('mode', 20);
            $table->string('provider_invoice_id', 100)->nullable();
            $table->string('status', 20)->default('pending')->comment('pending, scheduled, authorized, error, cancelling, cancelled');
            $table->bigInteger('value_cents')->comment('Parte da clínica — base da nota');
            $table->bigInteger('sale_total_cents');
            $table->string('description', 1000);
            $table->string('number', 40)->nullable();
            $table->string('verification_code', 80)->nullable();
            $table->string('pdf_url', 500)->nullable();
            $table->string('xml_url', 500)->nullable();
            $table->string('error', 500)->nullable();
            $table->dateTime('issued_at')->nullable();
            $table->dateTime('last_checked_at')->nullable();
            $table->char('created_by', 26)->nullable();
            $table->datetimes();

            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'sale_id']);
            $table->index(['company_id', 'status']);
            $table->foreign(['company_id', 'sale_id'])->references(['company_id', 'id'])->on('sprint_sales')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE sprint_professionals ADD CONSTRAINT sprint_professionals_check CHECK (kind IN ('doctor','partner') AND split_type IN ('percent','fixed') AND (split_type <> 'percent' OR split_value <= 10000))");
        DB::statement("ALTER TABLE sprint_sales ADD CONSTRAINT sprint_sales_check CHECK (total_cents > 0 AND professional_cents >= 0 AND professional_cents <= total_cents AND house_cents = total_cents - professional_cents AND status IN ('open','partial','paid','cancelled','refunded'))");
        DB::statement('ALTER TABLE sprint_sale_items ADD CONSTRAINT sprint_sale_items_check CHECK (amount_cents > 0 AND professional_cents >= 0 AND professional_cents <= amount_cents AND house_cents = amount_cents - professional_cents)');
        DB::statement("ALTER TABLE sprint_payments ADD CONSTRAINT sprint_payments_check CHECK (amount_cents > 0 AND method IN ('cash','pix','credit_card','debit_card') AND channel IN ('cash','asaas','cielo_api','cielo_terminal','terminal','mock') AND status IN ('pending','paid','cancelled','failed','refunded'))");
        DB::statement("ALTER TABLE sprint_splits ADD CONSTRAINT sprint_splits_check CHECK (party IN ('professional','house','platform','fee') AND mode IN ('native','internal') AND status IN ('pending','settled','reversed','to_invoice','invoiced') AND (party <> 'professional' OR professional_id IS NOT NULL))");
        DB::statement("ALTER TABLE sprint_invoices ADD CONSTRAINT sprint_invoices_check CHECK (value_cents > 0 AND status IN ('pending','scheduled','authorized','error','cancelling','cancelled'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('sprint_invoices');
        Schema::dropIfExists('sprint_splits');
        Schema::dropIfExists('sprint_payments');
        Schema::dropIfExists('sprint_sale_items');
        Schema::dropIfExists('sprint_sales');
        Schema::dropIfExists('sprint_settings');
        Schema::dropIfExists('sprint_professionals');
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn('sprint_commission_bp'));
    }
};
