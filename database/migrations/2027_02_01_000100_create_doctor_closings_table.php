<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 15 — fechamento mensal médico × clínica.
 *
 * O demonstrativo é um RETRATO imutável (JSON + SHA-256) do mês: produção, recebimentos,
 * convênios e repasse. Correção = nova versão (a anterior fica "substituída"), nunca edição.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doctor_closings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('company_id', 26);
            $table->char('doctor_id', 26);
            $table->char('period', 7)->comment('AAAA-MM');
            $table->unsignedSmallInteger('version')->default(1);
            $table->string('status', 20)->default('closed')->comment('closed, confirmed, disputed, superseded');
            $table->json('data');
            $table->char('hash', 64);
            $table->bigInteger('doctor_share_cents')->default(0);
            $table->bigInteger('to_pay_cents')->default(0)->comment('Repasse interno a pagar pela clínica');
            $table->char('payable_id', 26)->nullable();
            $table->char('closed_by', 26);
            $table->dateTime('closed_at');
            $table->char('responded_by', 26)->nullable();
            $table->dateTime('responded_at')->nullable();
            $table->string('response_notes', 1000)->nullable();
            $table->datetimes();

            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'doctor_id', 'period', 'version'], 'doctor_closings_version_unique');
            $table->index(['company_id', 'period']);
            $table->foreign(['company_id', 'doctor_id'])->references(['company_id', 'id'])->on('doctors')->restrictOnDelete();
            $table->foreign(['company_id', 'payable_id'])->references(['company_id', 'id'])->on('payables')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE doctor_closings ADD CONSTRAINT doctor_closings_check CHECK (status IN ('closed','confirmed','disputed','superseded'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('doctor_closings');
    }
};
