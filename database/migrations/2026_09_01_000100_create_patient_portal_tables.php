<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 10 — portal do paciente.
 *
 * Login próprio do paciente (separado dos usuários da clínica), criado por convite
 * da recepção. Tokens de ativação/redefinição guardados só como hash.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_accounts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('company_id', 26);
            $table->char('patient_id', 26);
            $table->string('email', 190)->nullable()->comment('Login alternativo ao CPF e e-mail de redefinição');
            $table->string('password')->nullable()->comment('Vazio até a ativação');
            $table->string('status', 20)->default('invited')->comment('invited, active, blocked');
            $table->unsignedSmallInteger('failed_login_attempts')->default(0);
            $table->dateTime('locked_until')->nullable();
            $table->dateTime('activated_at')->nullable();
            $table->dateTime('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            $table->char('created_by', 26)->nullable();
            $table->datetimes();

            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'patient_id']);
            $table->unique(['company_id', 'email']);
            $table->foreign(['company_id', 'patient_id'])->references(['company_id', 'id'])->on('patients')->restrictOnDelete();
        });

        Schema::create('patient_account_tokens', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('company_id', 26);
            $table->char('account_id', 26);
            $table->string('purpose', 20)->comment('activation, reset');
            $table->char('token_hash', 64)->unique()->comment('SHA-256 do token — o token em si nunca é gravado');
            $table->dateTime('expires_at');
            $table->dateTime('used_at')->nullable();
            $table->char('created_by', 26)->nullable();
            $table->dateTime('created_at');

            $table->index(['company_id', 'account_id']);
            $table->foreign(['company_id', 'account_id'])->references(['company_id', 'id'])->on('patient_accounts')->cascadeOnDelete();
        });

        Schema::table('patient_files', function (Blueprint $table) {
            $table->boolean('visible_to_patient')->default(false)->comment('Liberado no portal do paciente');
        });

        DB::statement("ALTER TABLE patient_accounts ADD CONSTRAINT patient_accounts_status_check CHECK (status IN ('invited','active','blocked'))");
        DB::statement("ALTER TABLE patient_account_tokens ADD CONSTRAINT patient_account_tokens_purpose_check CHECK (purpose IN ('activation','reset'))");
    }

    public function down(): void
    {
        Schema::table('patient_files', fn (Blueprint $table) => $table->dropColumn('visible_to_patient'));
        Schema::dropIfExists('patient_account_tokens');
        Schema::dropIfExists('patient_accounts');
    }
};
