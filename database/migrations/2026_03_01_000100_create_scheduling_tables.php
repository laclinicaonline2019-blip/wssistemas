<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Fase 4 — salas, grade de horários, serviços, bloqueios, feriados,
 * agendamentos e fila de atendimento (senhas).
 *
 * Datas/horas de eventos em UTC (DATETIME); horários de grade (TIME) no fuso da filial.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('doctors', function (Blueprint $table) {
            $table->unsignedSmallInteger('daily_limit')->nullable()->after('bio')->comment('Máximo de pacientes por dia (todas as unidades)');
        });

        Schema::create('rooms', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('company_id', 26);
            $table->char('branch_id', 26);
            $table->string('name', 80);
            $table->string('number', 20)->nullable();
            $table->char('specialty_id', 26)->nullable();
            $table->char('doctor_id', 26)->nullable()->comment('Médico preferencial');
            $table->string('equipment', 500)->nullable();
            $table->string('status', 20)->default('active');
            $table->datetimes();

            $table->unique(['company_id', 'id']);
            $table->unique(['branch_id', 'name', 'number']);
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'branch_id'])->references(['company_id', 'id'])->on('branches')->restrictOnDelete();
            $table->foreign(['company_id', 'specialty_id'])->references(['company_id', 'id'])->on('specialties')->restrictOnDelete();
            $table->foreign(['company_id', 'doctor_id'])->references(['company_id', 'id'])->on('doctors')->restrictOnDelete();
        });

        // Serviços/tipos de atendimento do médico com valor particular.
        Schema::create('doctor_services', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('company_id', 26);
            $table->char('doctor_id', 26);
            $table->string('name', 100)->comment('Consulta, Retorno, Teleconsulta…');
            $table->unsignedSmallInteger('duration_minutes')->nullable()->comment('NULL = duração do slot da grade');
            $table->unsignedBigInteger('price_cents')->default(0)->comment('Valor particular');
            $table->boolean('accepts_private')->default(true);
            $table->boolean('accepts_insurance')->default(false);
            $table->boolean('is_telemedicine')->default(false);
            $table->boolean('is_return')->default(false);
            $table->boolean('is_active')->default(true);
            $table->datetimes();

            $table->unique(['company_id', 'id']);
            $table->unique(['doctor_id', 'name']);
            $table->foreign(['company_id', 'doctor_id'])->references(['company_id', 'id'])->on('doctors')->cascadeOnDelete();
        });

        // Grade: um registro por período de atendimento (ex.: seg 08:00-12:00).
        Schema::create('schedule_templates', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('company_id', 26);
            $table->char('doctor_id', 26);
            $table->char('branch_id', 26);
            $table->char('room_id', 26)->nullable();
            $table->char('specialty_id', 26)->nullable()->comment('Período exclusivo de uma especialidade (limite por especialidade)');
            $table->unsignedTinyInteger('weekday')->comment('0 = domingo … 6 = sábado');
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedSmallInteger('slot_minutes');
            $table->unsignedSmallInteger('max_patients')->nullable()->comment('Limite do período; NULL = nº de slots');
            $table->unsignedSmallInteger('max_overbooks')->default(0)->comment('Encaixes permitidos no período');
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->datetimes();

            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'doctor_id', 'weekday']);
            $table->foreign(['company_id', 'doctor_id'])->references(['company_id', 'id'])->on('doctors')->cascadeOnDelete();
            $table->foreign(['company_id', 'branch_id'])->references(['company_id', 'id'])->on('branches')->restrictOnDelete();
            $table->foreign(['company_id', 'room_id'])->references(['company_id', 'id'])->on('rooms')->restrictOnDelete();
            $table->foreign(['company_id', 'specialty_id'])->references(['company_id', 'id'])->on('specialties')->restrictOnDelete();
        });

        // Bloqueios: férias, congressos, manutenção. doctor_id NULL = unidade inteira.
        Schema::create('schedule_blocks', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('company_id', 26);
            $table->char('doctor_id', 26)->nullable();
            $table->char('branch_id', 26)->nullable()->comment('NULL = todas as unidades');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('type', 20)->default('block');
            $table->string('reason', 200);
            $table->char('created_by', 26)->nullable();
            $table->datetimes();

            $table->index(['company_id', 'starts_at', 'ends_at']);
            $table->foreign(['company_id', 'doctor_id'])->references(['company_id', 'id'])->on('doctors')->cascadeOnDelete();
            $table->foreign(['company_id', 'branch_id'])->references(['company_id', 'id'])->on('branches')->restrictOnDelete();
        });

        Schema::create('holidays', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('company_id', 26);
            $table->char('branch_id', 26)->nullable()->comment('NULL = todas as unidades');
            $table->date('date');
            $table->string('name', 120);
            $table->datetimes();

            $table->index(['company_id', 'date']);
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'branch_id'])->references(['company_id', 'id'])->on('branches')->cascadeOnDelete();
        });

        Schema::create('appointments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('company_id', 26);
            $table->char('branch_id', 26);
            $table->char('doctor_id', 26);
            $table->char('patient_id', 26);
            $table->char('service_id', 26)->nullable();
            $table->char('template_id', 26)->nullable();
            $table->char('room_id', 26)->nullable();
            $table->string('protocol', 20);
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('status', 20)->default('scheduled');
            $table->boolean('is_overbook')->default(false);
            $table->string('channel', 20)->default('reception');
            $table->string('payer_type', 20)->default('private');
            $table->char('patient_insurance_id', 26)->nullable();
            $table->unsignedBigInteger('price_cents')->default(0)->comment('Valor no momento do agendamento');
            $table->string('notes', 500)->nullable();
            $table->string('idempotency_key', 80)->nullable();
            $table->dateTime('confirmed_at')->nullable();
            $table->dateTime('arrived_at')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->char('cancelled_by', 26)->nullable();
            $table->char('created_by', 26)->nullable();
            $table->datetimes();

            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'protocol']);
            $table->unique(['company_id', 'idempotency_key']);
            $table->index(['company_id', 'branch_id', 'starts_at']);
            $table->index(['company_id', 'doctor_id', 'starts_at']);
            $table->index(['company_id', 'patient_id', 'starts_at']);
            $table->foreign(['company_id', 'branch_id'])->references(['company_id', 'id'])->on('branches')->restrictOnDelete();
            $table->foreign(['company_id', 'doctor_id'])->references(['company_id', 'id'])->on('doctors')->restrictOnDelete();
            $table->foreign(['company_id', 'patient_id'])->references(['company_id', 'id'])->on('patients')->restrictOnDelete();
            $table->foreign(['company_id', 'service_id'])->references(['company_id', 'id'])->on('doctor_services')->restrictOnDelete();
            $table->foreign(['company_id', 'room_id'])->references(['company_id', 'id'])->on('rooms')->restrictOnDelete();

            // Barreira final contra dupla marcação: mesmo médico + mesmo início, entre
            // agendamentos ativos que não são encaixe (NULL não colide). Sobreposição de
            // durações diferentes é impedida pelo BookingService sob lock do médico.
            $table->boolean('holds_slot')->nullable()
                ->storedAs("CASE WHEN is_overbook = FALSE AND status NOT IN ('cancelled','no_show') THEN TRUE END");
            $table->unique(['doctor_id', 'starts_at', 'holds_slot'], 'appointments_slot_unique');
        });

        Schema::create('queue_tickets', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('company_id', 26);
            $table->char('branch_id', 26);
            $table->char('appointment_id', 26)->nullable();
            $table->char('patient_id', 26)->nullable();
            $table->char('doctor_id', 26)->nullable();
            $table->char('room_id', 26)->nullable();
            $table->date('service_date');
            $table->string('type', 30);
            $table->string('prefix', 3);
            $table->unsignedInteger('number');
            $table->string('code', 10);
            $table->boolean('is_priority')->default(false);
            $table->string('status', 20)->default('waiting');
            $table->unsignedSmallInteger('call_count')->default(0);
            $table->dateTime('arrived_at');
            $table->dateTime('called_at')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->char('created_by', 26)->nullable();
            $table->string('notes', 255)->nullable();
            $table->datetimes();

            $table->unique(['company_id', 'id']);
            $table->unique(['branch_id', 'service_date', 'prefix', 'number']);
            $table->index(['company_id', 'branch_id', 'service_date', 'status']);
            $table->foreign(['company_id', 'branch_id'])->references(['company_id', 'id'])->on('branches')->restrictOnDelete();
            $table->foreign(['company_id', 'appointment_id'])->references(['company_id', 'id'])->on('appointments')->restrictOnDelete();
            $table->foreign(['company_id', 'patient_id'])->references(['company_id', 'id'])->on('patients')->restrictOnDelete();
            $table->foreign(['company_id', 'doctor_id'])->references(['company_id', 'id'])->on('doctors')->restrictOnDelete();
            $table->foreign(['company_id', 'room_id'])->references(['company_id', 'id'])->on('rooms')->restrictOnDelete();
        });

        // Histórico de chamadas exibido no painel da TV.
        Schema::create('queue_calls', function (Blueprint $table) {
            $table->id();
            $table->char('company_id', 26);
            $table->char('branch_id', 26);
            $table->char('ticket_id', 26);
            $table->string('code', 10);
            $table->string('display_name', 60)->nullable()->comment('Identificação reduzida (LGPD)');
            $table->string('room_label', 80)->nullable();
            $table->string('doctor_label', 120)->nullable();
            $table->char('called_by', 26)->nullable();
            $table->dateTime('called_at');

            $table->index(['branch_id', 'called_at']);
            $table->foreign(['company_id', 'ticket_id'])->references(['company_id', 'id'])->on('queue_tickets')->cascadeOnDelete();
        });

        DB::statement("ALTER TABLE rooms ADD CONSTRAINT rooms_status_check CHECK (status IN ('active','inactive','maintenance'))");
        DB::statement('ALTER TABLE schedule_templates ADD CONSTRAINT schedule_templates_period_check CHECK (end_time > start_time AND slot_minutes > 0 AND weekday <= 6)');
        DB::statement('ALTER TABLE schedule_blocks ADD CONSTRAINT schedule_blocks_period_check CHECK (ends_at > starts_at)');
        DB::statement("ALTER TABLE appointments ADD CONSTRAINT appointments_status_check CHECK (status IN ('scheduled','confirmed','arrived','in_service','completed','cancelled','no_show'))");
        DB::statement('ALTER TABLE appointments ADD CONSTRAINT appointments_period_check CHECK (ends_at > starts_at)');
        DB::statement("ALTER TABLE queue_tickets ADD CONSTRAINT queue_tickets_status_check CHECK (status IN ('waiting','called','in_service','done','skipped','cancelled'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('queue_calls');
        Schema::dropIfExists('queue_tickets');
        Schema::dropIfExists('appointments');
        Schema::dropIfExists('holidays');
        Schema::dropIfExists('schedule_blocks');
        Schema::dropIfExists('schedule_templates');
        Schema::dropIfExists('doctor_services');
        Schema::dropIfExists('rooms');
        Schema::table('doctors', fn (Blueprint $table) => $table->dropColumn('daily_limit'));
    }
};
