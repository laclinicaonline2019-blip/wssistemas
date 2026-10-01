<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Fase 6 — documentos médicos (receitas, atestados, solicitações de exames,
 * relatórios/declarações/encaminhamentos) e arquivos anexados ao paciente.
 *
 * Documento emitido nunca é alterado nem excluído: o conteúdo é selado por HMAC
 * (content_hash) e validável publicamente pelo código de verificação/QR Code.
 * Erros se corrigem cancelando (com motivo) e emitindo outro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medical_documents', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('company_id', 26);
            $table->char('branch_id', 26);
            $table->char('patient_id', 26);
            $table->char('doctor_id', 26);
            $table->char('encounter_id', 26)->nullable();
            $table->char('group_id', 26)->comment('Documentos emitidos juntos (ex.: receita simples + controle especial)');
            $table->unsignedInteger('number')->comment('Sequencial por empresa');
            $table->string('type', 30);
            $table->string('subtype', 30)->nullable();
            $table->json('content');
            $table->char('content_hash', 64);
            $table->string('verification_code', 16)->unique();
            $table->string('status', 20)->default('issued');
            $table->dateTime('issued_at');
            $table->date('valid_until')->nullable();
            $table->char('issued_by', 26);
            $table->dateTime('cancelled_at')->nullable();
            $table->char('cancelled_by', 26)->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->unsignedInteger('print_count')->default(0);
            $table->dateTime('last_printed_at')->nullable();
            $table->string('signature_status', 20)->default('none')->comment('none, signed (ICP-Brasil — integração futura)');
            $table->string('signature_provider', 40)->nullable();
            $table->dateTime('signed_at')->nullable();
            $table->string('signature_reference', 191)->nullable();
            $table->datetimes();

            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'patient_id', 'issued_at']);
            $table->index(['company_id', 'group_id']);
            $table->index(['company_id', 'type', 'issued_at']);
            $table->foreign(['company_id', 'patient_id'])->references(['company_id', 'id'])->on('patients')->restrictOnDelete();
            $table->foreign(['company_id', 'doctor_id'])->references(['company_id', 'id'])->on('doctors')->restrictOnDelete();
            $table->foreign(['company_id', 'branch_id'])->references(['company_id', 'id'])->on('branches')->restrictOnDelete();
            $table->foreign(['company_id', 'encounter_id'])->references(['company_id', 'id'])->on('encounters')->restrictOnDelete();
        });

        Schema::create('patient_files', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('company_id', 26);
            $table->char('patient_id', 26);
            $table->char('encounter_id', 26)->nullable();
            $table->string('category', 30);
            $table->string('title', 150);
            $table->string('original_name', 191);
            $table->string('mime', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->string('disk', 30);
            $table->string('path', 255);
            $table->char('sha256', 64);
            $table->string('status', 20)->default('active')->comment('active, archived — nunca excluído fisicamente');
            $table->char('uploaded_by', 26);
            $table->datetimes();

            $table->index(['company_id', 'patient_id', 'status']);
            $table->foreign(['company_id', 'patient_id'])->references(['company_id', 'id'])->on('patients')->restrictOnDelete();
            $table->foreign(['company_id', 'encounter_id'])->references(['company_id', 'id'])->on('encounters')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE medical_documents ADD CONSTRAINT medical_documents_type_check CHECK (type IN ('prescription','special_prescription','notification_record','certificate','exam_request','report'))");
        DB::statement("ALTER TABLE medical_documents ADD CONSTRAINT medical_documents_status_check CHECK (status IN ('issued','cancelled'))");
        DB::statement("ALTER TABLE patient_files ADD CONSTRAINT patient_files_status_check CHECK (status IN ('active','archived'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_files');
        Schema::dropIfExists('medical_documents');
    }
};
