<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->nullable()->constrained('companies')->restrictOnDelete();
            $table->string('name', 150);
            $table->string('email', 190);
            $table->string('phone', 20)->nullable();
            $table->string('password');
            $table->boolean('is_super_admin')->default(false);
            $table->string('status', 20)->default('active');
            $table->text('two_factor_secret')->nullable()->comment('Criptografado (APP_KEY)');
            $table->text('two_factor_recovery_codes')->nullable()->comment('HMACs, criptografado');
            $table->dateTime('two_factor_confirmed_at')->nullable();
            $table->unsignedSmallInteger('failed_login_attempts')->default(0);
            $table->dateTime('locked_until')->nullable();
            $table->dateTime('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            $table->dateTime('password_changed_at')->nullable();
            $table->boolean('must_change_password')->default(false);
            $table->rememberToken();
            $table->datetimes();
            $table->softDeletesDatetime();

            $table->index(['company_id', 'status']);
            $table->unique(['company_id', 'id']);

            // E-mail único (sem diferenciar maiúsculas) entre usuários não excluídos.
            $table->string('active_email', 190)->nullable()
                ->storedAs('CASE WHEN deleted_at IS NULL THEN lower(email) END')->unique();
        });

        DB::statement("ALTER TABLE users ADD CONSTRAINT users_status_check CHECK (status IN ('active','blocked','invited'))");
        // Super admin não pertence a nenhuma clínica; usuário de clínica sempre pertence a uma.
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_tenant_check CHECK ((is_super_admin = TRUE AND company_id IS NULL) OR (is_super_admin = FALSE AND company_id IS NOT NULL))');

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email', 190)->primary();
            $table->string('token');
            $table->dateTime('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id', 128)->primary();
            $table->string('user_id', 26)->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->ulidMorphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->dateTime('last_used_at')->nullable();
            $table->dateTime('expires_at')->nullable()->index();
            $table->datetimes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
