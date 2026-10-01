<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/*
 * Fase 5 — prontuário eletrônico, triagem, alergias, CID e medicamentos.
 *
 * Princípios: conteúdo clínico finalizado é IMUTÁVEL (versões encadeadas por
 * hash; correções só por adendo com justificativa); diagnósticos guardam uma
 * cópia do código/descrição do CID usado (atualizar a base não altera o passado).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Catálogo CID (global, compartilhado por todas as clínicas).
        Schema::create('cid_codes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('version', 20)->default('CID-10');
            $table->string('code', 10);
            $table->string('description', 255);
            $table->string('search_text', 300);
            $table->char('sex_restriction', 1)->nullable()->comment('F/M quando o código é restrito a um sexo');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_sample')->default(false)->comment('Amostra de demonstração (não é a tabela oficial)');
            $table->datetimes();

            $table->unique(['version', 'code']);
            $table->index('search_text');
        });

        Schema::create('cid_favorites', function (Blueprint $table) {
            $table->char('company_id', 26);
            $table->char('user_id', 26);
            $table->char('cid_code_id', 26);
            $table->dateTime('created_at');
            $table->primary(['user_id', 'cid_code_id']);
            $table->foreign(['company_id', 'user_id'])->references(['company_id', 'id'])->on('users')->cascadeOnDelete();
            $table->foreign('cid_code_id')->references('id')->on('cid_codes')->cascadeOnDelete();
        });

        // Medicamentos: company_id NULL = base global da plataforma; preenchido = cadastro da clínica.
        Schema::create('medications', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('company_id', 26)->nullable();
            $table->string('active_ingredient', 200);
            $table->string('commercial_name', 150)->nullable();
            $table->string('presentation', 120)->nullable()->comment('comprimido, cápsula, solução oral…');
            $table->string('concentration', 80)->nullable();
            $table->string('manufacturer', 120)->nullable();
            $table->string('route', 40)->nullable()->comment('oral, IM, IV, tópica…');
            $table->string('default_posology', 255)->nullable();
            $table->string('control_type', 20)->default('none')->comment('none, antimicrobial ou lista da Portaria 344/98 (A1…C5)');
            $table->string('notes', 500)->nullable();
            $table->string('search_text', 500);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_sample')->default(false);
            $table->char('created_by', 26)->nullable();
            $table->datetimes();

            $table->index(['company_id', 'search_text']);
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
        });

        Schema::create('patient_allergies', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('company_id', 26);
            $table->char('patient_id', 26);
            $table->string('substance', 150);
            $table->string('reaction', 255)->nullable();
            $table->string('severity', 20)->default('unknown');
            $table->string('status', 20)->default('active');
            $table->char('recorded_by', 26)->nullable();
            $table->datetimes();

            $table->index(['company_id', 'patient_id', 'status']);
            $table->foreign(['company_id', 'patient_id'])->references(['company_id', 'id'])->on('patients')->restrictOnDelete();
        });

        // Triagem / sinais vitais (enfermagem). Registro imutável: correção = nova triagem.
        Schema::create('triages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('company_id', 26);
            $table->char('branch_id', 26);
            $table->char('patient_id', 26);
            $table->char('appointment_id', 26)->nullable();
            $table->char('recorded_by', 26)->nullable();
            $table->unsignedSmallInteger('bp_systolic')->nullable();
            $table->unsignedSmallInteger('bp_diastolic')->nullable();
            $table->unsignedSmallInteger('heart_rate')->nullable();
            $table->unsignedSmallInteger('respiratory_rate')->nullable();
            $table->decimal('temperature', 4, 1)->nullable();
            $table->unsignedTinyInteger('spo2')->nullable();
            $table->decimal('weight_kg', 5, 2)->nullable();
            $table->unsignedSmallInteger('height_cm')->nullable();
            $table->unsignedSmallInteger('glucose')->nullable();
            $table->unsignedTinyInteger('pain_scale')->nullable();
            $table->string('risk', 20)->nullable()->comment('Classificação de risco (cores)');
            $table->string('chief_complaint', 500)->nullable();
            $table->text('notes')->nullable();
            $table->dateTime('created_at');

            $table->index(['company_id', 'patient_id', 'created_at']);
            $table->foreign(['company_id', 'patient_id'])->references(['company_id', 'id'])->on('patients')->restrictOnDelete();
            $table->foreign(['company_id', 'branch_id'])->references(['company_id', 'id'])->on('branches')->restrictOnDelete();
            $table->foreign(['company_id', 'appointment_id'])->references(['company_id', 'id'])->on('appointments')->restrictOnDelete();
        });

        // Atendimento (consulta). Rascunho mutável (autosave) até a finalização.
        Schema::create('encounters', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('company_id', 26);
            $table->char('branch_id', 26);
            $table->char('patient_id', 26);
            $table->char('doctor_id', 26);
            $table->char('appointment_id', 26)->nullable();
            $table->char('specialty_id', 26)->nullable();
            $table->string('status', 20)->default('draft');
            $table->json('draft_data')->nullable();
            $table->unsignedInteger('draft_revision')->default(0)->comment('Controle otimista do autosave');
            $table->dateTime('draft_saved_at')->nullable();
            $table->unsignedInteger('current_version')->default(0);
            $table->dateTime('started_at');
            $table->dateTime('finalized_at')->nullable();
            $table->char('created_by', 26)->nullable();
            $table->datetimes();

            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'appointment_id']);
            $table->index(['company_id', 'patient_id', 'started_at']);
            $table->index(['company_id', 'doctor_id', 'status']);
            $table->foreign(['company_id', 'patient_id'])->references(['company_id', 'id'])->on('patients')->restrictOnDelete();
            $table->foreign(['company_id', 'doctor_id'])->references(['company_id', 'id'])->on('doctors')->restrictOnDelete();
            $table->foreign(['company_id', 'branch_id'])->references(['company_id', 'id'])->on('branches')->restrictOnDelete();
            $table->foreign(['company_id', 'appointment_id'])->references(['company_id', 'id'])->on('appointments')->restrictOnDelete();
            $table->foreign(['company_id', 'specialty_id'])->references(['company_id', 'id'])->on('specialties')->restrictOnDelete();
        });

        // Versões IMUTÁVEIS do registro clínico (original + adendos), encadeadas por hash.
        Schema::create('encounter_versions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('company_id', 26);
            $table->char('encounter_id', 26);
            $table->unsignedInteger('version');
            $table->string('kind', 20)->comment('original, addendum');
            $table->json('data');
            $table->json('diagnoses');
            $table->string('reason', 500)->nullable()->comment('Justificativa obrigatória no adendo');
            $table->char('author_id', 26);
            $table->char('author_doctor_id', 26);
            $table->dateTime('created_at');
            $table->char('prev_hash', 64);
            $table->char('hash', 64);

            $table->unique(['encounter_id', 'version']);
            $table->foreign(['company_id', 'encounter_id'])->references(['company_id', 'id'])->on('encounters')->restrictOnDelete();
        });

        // Diagnósticos finalizados (para estatísticas/relatórios) — cópia do CID no momento do registro.
        Schema::create('encounter_diagnoses', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('company_id', 26);
            $table->char('encounter_id', 26);
            $table->unsignedInteger('version');
            $table->char('cid_code_id', 26)->nullable();
            $table->string('code', 10);
            $table->string('description', 255);
            $table->string('cid_version', 20);
            $table->boolean('is_primary')->default(false);
            $table->string('notes', 255)->nullable();
            $table->dateTime('created_at');

            $table->index(['company_id', 'code']);
            $table->foreign(['company_id', 'encounter_id'])->references(['company_id', 'id'])->on('encounters')->restrictOnDelete();
            $table->foreign('cid_code_id')->references('id')->on('cid_codes')->nullOnDelete();
        });

        DB::statement("ALTER TABLE medications ADD CONSTRAINT medications_control_check CHECK (control_type IN ('none','antimicrobial','A1','A2','A3','B1','B2','C1','C2','C3','C4','C5'))");
        DB::statement("ALTER TABLE patient_allergies ADD CONSTRAINT patient_allergies_severity_check CHECK (severity IN ('mild','moderate','severe','unknown'))");
        DB::statement("ALTER TABLE encounters ADD CONSTRAINT encounters_status_check CHECK (status IN ('draft','finalized'))");
        DB::statement("ALTER TABLE encounter_versions ADD CONSTRAINT encounter_versions_kind_check CHECK (kind IN ('original','addendum'))");

        self::protectImmutable(['encounter_versions', 'encounter_diagnoses', 'triages']);
    }

    public function down(): void
    {
        foreach (['encounter_versions', 'encounter_diagnoses', 'triages'] as $table) {
            self::dropProtection($table);
        }

        Schema::dropIfExists('encounter_diagnoses');
        Schema::dropIfExists('encounter_versions');
        Schema::dropIfExists('encounters');
        Schema::dropIfExists('triages');
        Schema::dropIfExists('patient_allergies');
        Schema::dropIfExists('medications');
        Schema::dropIfExists('cid_favorites');
        Schema::dropIfExists('cid_codes');

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS clinical_record_block_mutation()');
        }
    }

    /** Bloqueio de UPDATE/DELETE no banco quando houver privilégio (ver auditoria). */
    private static function protectImmutable(array $tables): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION clinical_record_block_mutation() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'Registro clínico imutável: use adendo/novo registro';
                END;
                $$ LANGUAGE plpgsql;
            SQL);
        }

        foreach ($tables as $table) {
            try {
                if (DB::getDriverName() === 'pgsql') {
                    DB::unprepared("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table}
                        FOR EACH ROW EXECUTE FUNCTION clinical_record_block_mutation()");
                } else {
                    foreach (['UPDATE' => 'upd', 'DELETE' => 'del'] as $event => $suffix) {
                        DB::unprepared("CREATE TRIGGER {$table}_no_{$suffix} BEFORE {$event} ON {$table} FOR EACH ROW
                            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Registro clínico imutável'");
                    }
                }
            } catch (Throwable $e) {
                Log::warning("Trigger de imutabilidade não criado em {$table}: ".$e->getMessage());
            }
        }
    }

    private static function dropProtection(string $table): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_immutable ON {$table}");

            return;
        }

        DB::unprepared("DROP TRIGGER IF EXISTS {$table}_no_upd");
        DB::unprepared("DROP TRIGGER IF EXISTS {$table}_no_del");
    }
};
