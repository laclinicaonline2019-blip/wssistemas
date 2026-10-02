<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 9 — convênios (operadoras), planos, procedimentos (TUSS), tabelas de preço,
 * credenciamento de médicos, autorizações, guias (consulta / SP-SADT), lotes de
 * faturamento no padrão TISS e retorno (pagamento e glosas).
 *
 * Valores em centavos. FKs compostas (company_id, x_id) como no restante do sistema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('insurers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('company_id', 26);
            $table->string('name', 120);
            $table->char('ans_registry', 6)->nullable()->comment('Registro ANS da operadora (6 dígitos) — obrigatório no TISS');
            $table->string('cnpj', 14)->nullable();
            $table->string('provider_code', 14)->nullable()->comment('Código do prestador (clínica) na operadora');
            $table->string('tiss_version', 8)->default('4.01.00');
            $table->unsignedSmallInteger('payment_term_days')->default(30)->comment('Prazo de pagamento após o envio do lote');
            $table->unsignedSmallInteger('max_guides_per_batch')->default(100);
            $table->string('phone', 20)->nullable();
            $table->string('email', 190)->nullable();
            $table->string('portal_url', 255)->nullable();
            $table->string('notes', 1000)->nullable();
            $table->boolean('is_active')->default(true);
            $table->datetimes();

            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'name']);
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
        });

        Schema::create('insurance_plans', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('company_id', 26);
            $table->char('insurer_id', 26);
            $table->string('name', 120);
            $table->string('ans_code', 20)->nullable()->comment('Registro do produto na ANS');
            $table->boolean('is_active')->default(true);
            $table->datetimes();

            $table->unique(['company_id', 'id']);
            $table->unique(['insurer_id', 'name']);
            $table->foreign(['company_id', 'insurer_id'])->references(['company_id', 'id'])->on('insurers')->restrictOnDelete();
        });

        // Credenciamento: médicos que atendem pelo convênio (nenhum cadastrado = todos).
        Schema::create('insurer_doctor', function (Blueprint $table) {
            $table->char('company_id', 26);
            $table->char('insurer_id', 26);
            $table->char('doctor_id', 26);
            $table->primary(['insurer_id', 'doctor_id']);
            $table->foreign(['company_id', 'insurer_id'])->references(['company_id', 'id'])->on('insurers')->cascadeOnDelete();
            $table->foreign(['company_id', 'doctor_id'])->references(['company_id', 'id'])->on('doctors')->cascadeOnDelete();
        });

        // Procedimentos (TUSS — tabela 22 — ou tabela própria "00").
        Schema::create('procedures', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('company_id', 26);
            $table->char('table_code', 2)->default('22');
            $table->string('code', 10);
            $table->string('name', 150);
            $table->string('kind', 20)->default('consultation')->comment('consultation, exam, therapy, minor_surgery, small_care');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_sample')->default(false);
            $table->datetimes();

            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'table_code', 'code']);
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
        });

        Schema::create('insurance_price_tables', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('company_id', 26);
            $table->char('insurer_id', 26);
            $table->char('plan_id', 26)->nullable()->comment('Vazio = todos os planos');
            $table->string('name', 120);
            $table->date('valid_from');
            $table->date('valid_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->datetimes();

            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'insurer_id', 'is_active']);
            $table->foreign(['company_id', 'insurer_id'])->references(['company_id', 'id'])->on('insurers')->restrictOnDelete();
            $table->foreign(['company_id', 'plan_id'])->references(['company_id', 'id'])->on('insurance_plans')->restrictOnDelete();
        });

        Schema::create('insurance_price_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('company_id', 26);
            $table->char('price_table_id', 26);
            $table->char('procedure_id', 26);
            $table->unsignedBigInteger('price_cents');
            $table->boolean('requires_authorization')->default(false);
            $table->string('copay_type', 10)->default('none')->comment('none, percent, fixed (coparticipação do paciente)');
            $table->unsignedInteger('copay_value')->default(0)->comment('percent: centésimos de %; fixed: centavos');
            $table->datetimes();

            $table->unique(['company_id', 'id']);
            $table->unique(['price_table_id', 'procedure_id']);
            $table->foreign(['company_id', 'price_table_id'])->references(['company_id', 'id'])->on('insurance_price_tables')->cascadeOnDelete();
            $table->foreign(['company_id', 'procedure_id'])->references(['company_id', 'id'])->on('procedures')->restrictOnDelete();
        });

        // Carteirinha passa a apontar para a operadora/plano cadastrados.
        Schema::table('patient_insurances', function (Blueprint $table) {
            $table->renameColumn('insurance_company_id', 'insurer_id');
        });
        Schema::table('patient_insurances', function (Blueprint $table) {
            $table->char('plan_id', 26)->nullable();
            $table->boolean('is_active')->default(true)->comment('Carteirinha removida do cadastro fica inativa (guias antigas continuam válidas)');
            $table->unique(['company_id', 'id']);
            $table->foreign(['company_id', 'insurer_id'])->references(['company_id', 'id'])->on('insurers')->restrictOnDelete();
            $table->foreign(['company_id', 'plan_id'])->references(['company_id', 'id'])->on('insurance_plans')->restrictOnDelete();
        });

        Schema::table('doctor_services', function (Blueprint $table) {
            $table->char('procedure_id', 26)->nullable()->comment('Procedimento faturado no convênio (TUSS)');
            $table->foreign(['company_id', 'procedure_id'])->references(['company_id', 'id'])->on('procedures')->restrictOnDelete();
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->string('cnes', 7)->nullable()->comment('CNES da unidade (guias TISS)');
        });

        Schema::table('split_rules', function (Blueprint $table) {
            $table->char('insurer_id', 26)->nullable()->comment('Regra específica de um convênio');
            $table->foreign(['company_id', 'insurer_id'])->references(['company_id', 'id'])->on('insurers')->cascadeOnDelete();
        });

        Schema::create('insurance_authorizations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('company_id', 26);
            $table->char('branch_id', 26);
            $table->char('patient_id', 26);
            $table->char('patient_insurance_id', 26);
            $table->char('insurer_id', 26);
            $table->char('procedure_id', 26);
            $table->char('doctor_id', 26)->nullable();
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->string('status', 20)->default('requested')->comment('requested, authorized, denied, cancelled, used');
            $table->string('operator_guide_number', 20)->nullable();
            $table->string('password', 20)->nullable()->comment('Senha de autorização');
            $table->date('authorized_on')->nullable();
            $table->date('valid_until')->nullable();
            $table->string('denial_reason', 255)->nullable();
            $table->string('notes', 500)->nullable();
            $table->char('created_by', 26)->nullable();
            $table->char('decided_by', 26)->nullable();
            $table->dateTime('decided_at')->nullable();
            $table->datetimes();

            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'patient_id', 'status']);
            $table->foreign(['company_id', 'branch_id'])->references(['company_id', 'id'])->on('branches')->restrictOnDelete();
            $table->foreign(['company_id', 'patient_id'])->references(['company_id', 'id'])->on('patients')->restrictOnDelete();
            $table->foreign(['company_id', 'patient_insurance_id'])->references(['company_id', 'id'])->on('patient_insurances')->restrictOnDelete();
            $table->foreign(['company_id', 'insurer_id'])->references(['company_id', 'id'])->on('insurers')->restrictOnDelete();
            $table->foreign(['company_id', 'procedure_id'])->references(['company_id', 'id'])->on('procedures')->restrictOnDelete();
            $table->foreign(['company_id', 'doctor_id'])->references(['company_id', 'id'])->on('doctors')->restrictOnDelete();
        });

        Schema::create('insurance_batches', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('company_id', 26);
            $table->char('branch_id', 26);
            $table->char('insurer_id', 26);
            $table->string('number', 12)->comment('numeroLote (sequencial da empresa)');
            $table->string('guide_type', 10);
            $table->char('competence', 7)->comment('AAAA-MM');
            $table->string('status', 20)->default('open')->comment('open, closed, partial, paid, cancelled');
            $table->unsignedInteger('guides_count')->default(0);
            $table->unsignedBigInteger('total_cents')->default(0);
            $table->unsignedBigInteger('paid_cents')->default(0);
            $table->unsignedBigInteger('glosa_cents')->default(0);
            $table->char('receivable_id', 26)->nullable();
            $table->longText('xml')->nullable()->comment('Mensagem TISS gerada no fechamento (imutável)');
            $table->char('xml_hash', 32)->nullable()->comment('Hash MD5 do epílogo TISS');
            $table->boolean('xml_schema_valid')->nullable();
            $table->string('protocol', 40)->nullable()->comment('Protocolo de recebimento da operadora');
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->char('closed_by', 26)->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->char('created_by', 26)->nullable();
            $table->datetimes();

            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'insurer_id', 'status']);
            $table->foreign(['company_id', 'branch_id'])->references(['company_id', 'id'])->on('branches')->restrictOnDelete();
            $table->foreign(['company_id', 'insurer_id'])->references(['company_id', 'id'])->on('insurers')->restrictOnDelete();
            $table->foreign(['company_id', 'receivable_id'])->references(['company_id', 'id'])->on('receivables')->restrictOnDelete();
        });

        Schema::create('insurance_guides', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('company_id', 26);
            $table->char('branch_id', 26);
            $table->char('insurer_id', 26);
            $table->char('plan_id', 26)->nullable();
            $table->char('patient_id', 26);
            $table->char('patient_insurance_id', 26);
            $table->char('appointment_id', 26)->nullable();
            $table->char('doctor_id', 26);
            $table->char('authorization_id', 26)->nullable();
            $table->char('batch_id', 26)->nullable();
            $table->char('copay_receivable_id', 26)->nullable();
            $table->string('guide_type', 10)->comment('consulta, sp_sadt');
            $table->string('number', 20)->comment('numeroGuiaPrestador');
            $table->string('operator_guide_number', 20)->nullable();
            // Cópia dos dados no momento do atendimento (a guia não muda se o cadastro mudar).
            $table->string('card_number', 20);
            $table->date('card_valid_until')->nullable();
            $table->string('cbo_code', 6);
            $table->date('attendance_date');
            $table->char('consultation_type', 1)->default('1')->comment('TISS: 1 primeira, 2 seguimento, 3 pré-natal, 4 encaminhamento');
            $table->char('attendance_type', 2)->default('04')->comment('TISS tipoAtendimento (SP-SADT)');
            $table->char('accident_indicator', 1)->default('9');
            $table->char('character', 1)->default('1')->comment('1 eletiva, 2 urgência');
            $table->string('clinical_indication', 500)->nullable();
            $table->string('observation', 500)->nullable();
            $table->string('status', 20)->default('draft')->comment('draft, ready, billed, paid, partial, denied, cancelled');
            $table->unsignedBigInteger('total_cents')->default(0);
            $table->unsignedBigInteger('paid_cents')->default(0);
            $table->unsignedBigInteger('glosa_cents')->default(0);
            $table->string('glosa_status', 20)->nullable()->comment('pending, appealed, accepted, recovered');
            $table->string('glosa_code', 4)->nullable()->comment('Código de glosa (tabela TISS 38)');
            $table->string('glosa_reason', 255)->nullable();
            $table->string('appeal_text', 1000)->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->char('created_by', 26)->nullable();
            $table->datetimes();

            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'number']);
            $table->unique(['company_id', 'appointment_id']);
            $table->index(['company_id', 'insurer_id', 'status']);
            $table->index(['company_id', 'batch_id']);
            $table->foreign(['company_id', 'branch_id'])->references(['company_id', 'id'])->on('branches')->restrictOnDelete();
            $table->foreign(['company_id', 'insurer_id'])->references(['company_id', 'id'])->on('insurers')->restrictOnDelete();
            $table->foreign(['company_id', 'plan_id'])->references(['company_id', 'id'])->on('insurance_plans')->restrictOnDelete();
            $table->foreign(['company_id', 'patient_id'])->references(['company_id', 'id'])->on('patients')->restrictOnDelete();
            $table->foreign(['company_id', 'patient_insurance_id'])->references(['company_id', 'id'])->on('patient_insurances')->restrictOnDelete();
            $table->foreign(['company_id', 'appointment_id'])->references(['company_id', 'id'])->on('appointments')->restrictOnDelete();
            $table->foreign(['company_id', 'doctor_id'])->references(['company_id', 'id'])->on('doctors')->restrictOnDelete();
            $table->foreign(['company_id', 'authorization_id'])->references(['company_id', 'id'])->on('insurance_authorizations')->restrictOnDelete();
            $table->foreign(['company_id', 'batch_id'])->references(['company_id', 'id'])->on('insurance_batches')->restrictOnDelete();
            $table->foreign(['company_id', 'copay_receivable_id'])->references(['company_id', 'id'])->on('receivables')->restrictOnDelete();
        });

        Schema::create('insurance_guide_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('company_id', 26);
            $table->char('guide_id', 26);
            $table->char('procedure_id', 26);
            $table->char('table_code', 2);
            $table->string('code', 10);
            $table->string('description', 150);
            $table->date('execution_date');
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->unsignedBigInteger('unit_cents');
            $table->unsignedBigInteger('total_cents');
            $table->boolean('requires_authorization')->default(false);
            $table->datetimes();

            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'guide_id']);
            $table->foreign(['company_id', 'guide_id'])->references(['company_id', 'id'])->on('insurance_guides')->cascadeOnDelete();
            $table->foreign(['company_id', 'procedure_id'])->references(['company_id', 'id'])->on('procedures')->restrictOnDelete();
        });

        // Cobranças particulares do atendimento misto (coparticipação, itens não cobertos).
        Schema::table('receivables', function (Blueprint $table) {
            $table->char('insurance_guide_id', 26)->nullable();
            $table->index(['company_id', 'insurance_guide_id']);
            $table->foreign(['company_id', 'insurance_guide_id'])->references(['company_id', 'id'])->on('insurance_guides')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE procedures ADD CONSTRAINT procedures_check CHECK (table_code IN ('18','19','20','22','90','98','00') AND kind IN ('consultation','exam','therapy','minor_surgery','small_care'))");
        DB::statement("ALTER TABLE insurance_price_items ADD CONSTRAINT insurance_price_items_check CHECK (price_cents > 0 AND copay_type IN ('none','percent','fixed') AND (copay_type <> 'percent' OR copay_value <= 10000))");
        DB::statement("ALTER TABLE insurance_authorizations ADD CONSTRAINT insurance_authorizations_check CHECK (quantity > 0 AND status IN ('requested','authorized','denied','cancelled','used'))");
        DB::statement("ALTER TABLE insurance_batches ADD CONSTRAINT insurance_batches_check CHECK (status IN ('open','closed','partial','paid','cancelled') AND guide_type IN ('consulta','sp_sadt') AND paid_cents + glosa_cents <= total_cents)");
        DB::statement("ALTER TABLE insurance_guides ADD CONSTRAINT insurance_guides_check CHECK (status IN ('draft','ready','billed','paid','partial','denied','cancelled') AND guide_type IN ('consulta','sp_sadt') AND paid_cents + glosa_cents <= total_cents)");
        DB::statement('ALTER TABLE insurance_guide_items ADD CONSTRAINT insurance_guide_items_check CHECK (quantity > 0 AND total_cents = unit_cents * quantity)');
        DB::statement('ALTER TABLE split_rules DROP CONSTRAINT split_rules_check');
        DB::statement("ALTER TABLE split_rules ADD CONSTRAINT split_rules_check CHECK (type IN ('percent','fixed') AND (type <> 'percent' OR value <= 10000) AND (insurer_id IS NULL OR payer_type = 'insurance'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE split_rules DROP CONSTRAINT split_rules_check');
        DB::statement("ALTER TABLE split_rules ADD CONSTRAINT split_rules_check CHECK (type IN ('percent','fixed') AND (type <> 'percent' OR value <= 10000))");
        Schema::table('split_rules', function (Blueprint $table) {
            $table->dropForeign(['company_id', 'insurer_id']);
            $table->dropColumn('insurer_id');
        });
        Schema::table('receivables', function (Blueprint $table) {
            $table->dropForeign(['company_id', 'insurance_guide_id']);
            $table->dropIndex(['company_id', 'insurance_guide_id']);
            $table->dropColumn('insurance_guide_id');
        });
        Schema::dropIfExists('insurance_guide_items');
        Schema::dropIfExists('insurance_guides');
        Schema::dropIfExists('insurance_batches');
        Schema::dropIfExists('insurance_authorizations');
        Schema::table('branches', fn (Blueprint $table) => $table->dropColumn('cnes'));
        Schema::table('doctor_services', function (Blueprint $table) {
            $table->dropForeign(['company_id', 'procedure_id']);
            $table->dropColumn('procedure_id');
        });
        Schema::table('patient_insurances', function (Blueprint $table) {
            $table->dropForeign(['company_id', 'insurer_id']);
            $table->dropForeign(['company_id', 'plan_id']);
            $table->dropUnique(['company_id', 'id']);
            $table->dropColumn(['plan_id', 'is_active']);
        });
        Schema::table('patient_insurances', fn (Blueprint $table) => $table->renameColumn('insurer_id', 'insurance_company_id'));
        Schema::dropIfExists('insurance_price_items');
        Schema::dropIfExists('insurance_price_tables');
        Schema::dropIfExists('procedures');
        Schema::dropIfExists('insurer_doctor');
        Schema::dropIfExists('insurance_plans');
        Schema::dropIfExists('insurers');
    }
};
