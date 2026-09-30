<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Fase 3 — Especialidades, médicos e pacientes.
 * Portável (MySQL/MariaDB e PostgreSQL). Toda tabela tem company_id e FKs
 * compostas (company_id, x_id) para garantir que os vínculos são da mesma empresa.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Sequências por empresa (nº de prontuário etc.), incrementadas sob lock.
        Schema::create('company_sequences', function (Blueprint $table) {
            $table->char('company_id', 26);
            $table->string('name', 50);
            $table->unsignedBigInteger('value')->default(0);
            $table->primary(['company_id', 'name']);
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
        });

        Schema::create('specialties', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('name', 120);
            $table->string('cbo_code', 10)->nullable()->comment('CBO (Classificação Brasileira de Ocupações)');
            $table->string('description', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->datetimes();

            $table->unique(['company_id', 'name']);
            $table->unique(['company_id', 'id']);
        });

        Schema::create('doctors', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->restrictOnDelete();
            $table->char('user_id', 26)->nullable()->comment('Conta de acesso do médico (opcional)');
            $table->string('name', 150);
            $table->string('social_name', 150)->nullable();
            $table->string('crm', 12);
            $table->char('crm_state', 2);
            $table->string('cpf', 11)->nullable();
            $table->string('email', 190)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('bio', 1000)->nullable()->comment('Apresentação usada pela IA e pelo portal');
            $table->string('status', 20)->default('active');
            $table->datetimes();
            $table->softDeletesDatetime();

            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'status']);
            $table->foreign(['company_id', 'user_id'])->references(['company_id', 'id'])->on('users')->restrictOnDelete();

            // CRM/UF único por empresa entre não excluídos; um usuário vinculado a no máximo um médico.
            // (NULL nunca colide em índices únicos: registros excluídos ficam fora.)
            $table->string('active_crm', 12)->nullable()->storedAs('CASE WHEN deleted_at IS NULL THEN crm END');
            $table->unique(['company_id', 'active_crm', 'crm_state'], 'doctors_crm_unique');
            $table->string('active_user_key', 26)->nullable()
                ->storedAs('CASE WHEN deleted_at IS NULL THEN RTRIM(user_id) END')->unique();
        });

        Schema::create('doctor_specialty', function (Blueprint $table) {
            $table->char('company_id', 26);
            $table->char('doctor_id', 26);
            $table->char('specialty_id', 26);
            $table->string('rqe', 20)->nullable()->comment('Registro de Qualificação de Especialista');
            $table->primary(['doctor_id', 'specialty_id']);
            $table->foreign(['company_id', 'doctor_id'])->references(['company_id', 'id'])->on('doctors')->cascadeOnDelete();
            $table->foreign(['company_id', 'specialty_id'])->references(['company_id', 'id'])->on('specialties')->restrictOnDelete();
        });

        Schema::create('doctor_branch', function (Blueprint $table) {
            $table->char('company_id', 26);
            $table->char('doctor_id', 26);
            $table->char('branch_id', 26);
            $table->primary(['doctor_id', 'branch_id']);
            $table->foreign(['company_id', 'doctor_id'])->references(['company_id', 'id'])->on('doctors')->cascadeOnDelete();
            $table->foreign(['company_id', 'branch_id'])->references(['company_id', 'id'])->on('branches')->restrictOnDelete();
        });

        Schema::create('patients', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->restrictOnDelete();
            $table->unsignedBigInteger('record_number')->comment('Nº do prontuário, sequencial por empresa');
            $table->char('home_branch_id', 26)->nullable()->comment('Filial de cadastro');
            $table->string('name', 150);
            $table->string('social_name', 150)->nullable();
            $table->string('search_name', 150)->comment('Nome normalizado (minúsculas, sem acento) para busca');
            $table->string('cpf', 11)->nullable();
            $table->string('rg', 20)->nullable();
            $table->string('rg_issuer', 20)->nullable();
            $table->string('cns', 15)->nullable()->comment('Cartão Nacional de Saúde');
            $table->date('birth_date')->nullable();
            $table->char('sex', 1)->nullable()->comment('F, M, I (intersexo) — sexo biológico para fins clínicos');
            $table->string('gender_identity', 60)->nullable();
            $table->string('mother_name', 150)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('whatsapp', 20)->nullable();
            $table->string('email', 190)->nullable();
            $table->string('zip_code', 8)->nullable();
            $table->string('street', 190)->nullable();
            $table->string('number', 20)->nullable();
            $table->string('complement', 100)->nullable();
            $table->string('district', 100)->nullable();
            $table->string('city', 100)->nullable();
            $table->char('state', 2)->nullable();
            $table->string('preferred_contact', 20)->nullable()->comment('whatsapp, phone, email');
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('active');
            $table->dateTime('deceased_at')->nullable();
            $table->dateTime('anonymized_at')->nullable();
            $table->char('created_by', 26)->nullable();
            $table->datetimes();
            $table->softDeletesDatetime();

            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'record_number']);
            $table->index(['company_id', 'search_name']);
            $table->index(['company_id', 'birth_date']);
            $table->index(['company_id', 'phone']);
            $table->index(['company_id', 'whatsapp']);
            $table->foreign(['company_id', 'home_branch_id'])->references(['company_id', 'id'])->on('branches')->restrictOnDelete();

            // CPF único por empresa entre não excluídos (NULL permitido: recém-nascidos, estrangeiros).
            $table->string('active_cpf', 11)->nullable()->storedAs('CASE WHEN deleted_at IS NULL THEN cpf END');
            $table->unique(['company_id', 'active_cpf'], 'patients_cpf_unique');
        });

        Schema::create('patient_contacts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('company_id', 26);
            $table->char('patient_id', 26);
            $table->string('type', 20)->comment('guardian (responsável legal), emergency, other');
            $table->string('name', 150);
            $table->string('relationship', 60)->nullable();
            $table->string('cpf', 11)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email', 190)->nullable();
            $table->datetimes();

            $table->index(['company_id', 'patient_id']);
            $table->foreign(['company_id', 'patient_id'])->references(['company_id', 'id'])->on('patients')->cascadeOnDelete();
        });

        // Carteirinhas de convênio. insurance_company_id será ligado ao módulo de convênios (Fase 9).
        Schema::create('patient_insurances', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('company_id', 26);
            $table->char('patient_id', 26);
            $table->char('insurance_company_id', 26)->nullable();
            $table->string('insurer_name', 120);
            $table->string('plan_name', 120)->nullable();
            $table->string('card_number', 40);
            $table->date('valid_until')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->datetimes();

            $table->index(['company_id', 'patient_id']);
            $table->index(['company_id', 'card_number']);
            $table->foreign(['company_id', 'patient_id'])->references(['company_id', 'id'])->on('patients')->cascadeOnDelete();
        });

        // Consentimentos LGPD: cada concessão/revogação é um registro (histórico preservado).
        Schema::create('patient_consents', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('company_id', 26);
            $table->char('patient_id', 26);
            $table->string('purpose', 50);
            $table->string('term_version', 20);
            $table->boolean('granted');
            $table->string('channel', 20)->comment('presencial, whatsapp, portal, email, telefone');
            $table->string('notes', 255)->nullable();
            $table->char('recorded_by', 26)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->dateTime('created_at');

            $table->index(['company_id', 'patient_id', 'purpose']);
            $table->foreign(['company_id', 'patient_id'])->references(['company_id', 'id'])->on('patients')->cascadeOnDelete();
        });

        DB::statement("ALTER TABLE doctors ADD CONSTRAINT doctors_status_check CHECK (status IN ('active','inactive'))");
        DB::statement("ALTER TABLE patients ADD CONSTRAINT patients_status_check CHECK (status IN ('active','inactive'))");
        DB::statement("ALTER TABLE patients ADD CONSTRAINT patients_sex_check CHECK (sex IS NULL OR sex IN ('F','M','I'))");
        DB::statement("ALTER TABLE patient_contacts ADD CONSTRAINT patient_contacts_type_check CHECK (type IN ('guardian','emergency','other'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_consents');
        Schema::dropIfExists('patient_insurances');
        Schema::dropIfExists('patient_contacts');
        Schema::dropIfExists('patients');
        Schema::dropIfExists('doctor_branch');
        Schema::dropIfExists('doctor_specialty');
        Schema::dropIfExists('doctors');
        Schema::dropIfExists('specialties');
        Schema::dropIfExists('company_sequences');
    }
};
