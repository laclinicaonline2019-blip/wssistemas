<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Compatível com MySQL 5.7+/MariaDB 10.3+ (produção HostGator) e PostgreSQL 13+.
 * Unicidade condicional ("somente registros não excluídos") é feita com colunas
 * geradas + índice único, pois o MySQL não tem índices parciais.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saas_plans', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('code', 50)->unique();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->unsignedBigInteger('price_monthly_cents')->default(0);
            $table->unsignedBigInteger('price_yearly_cents')->default(0);
            $table->unsignedInteger('trial_days')->default(0);
            // max_users, max_doctors, max_branches, storage_mb, ai_enabled, whatsapp_enabled...
            $table->json('limits')->nullable();
            $table->boolean('is_active')->default(true);
            $table->datetimes();
        });

        Schema::create('companies', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('legal_name', 200);
            $table->string('trade_name', 200);
            $table->string('document', 14)->comment('CNPJ (somente dígitos)');
            $table->string('slug', 80)->unique();
            $table->string('email', 190)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('status', 20)->default('trial')->index();
            $table->foreignUlid('saas_plan_id')->nullable()->constrained('saas_plans')->restrictOnDelete();
            $table->dateTime('trial_ends_at')->nullable();
            $table->json('settings')->nullable();
            $table->datetimes();
            $table->softDeletesDatetime();

            // CNPJ único entre empresas não excluídas.
            $table->string('active_document', 14)->nullable()
                ->storedAs('CASE WHEN deleted_at IS NULL THEN document END')->unique();
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('name', 150);
            $table->string('code', 30);
            $table->boolean('is_headquarters')->default(false);
            $table->string('document', 14)->nullable()->comment('CNPJ da filial');
            $table->string('email', 190)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('zip_code', 8)->nullable();
            $table->string('street', 190)->nullable();
            $table->string('number', 20)->nullable();
            $table->string('complement', 100)->nullable();
            $table->string('district', 100)->nullable();
            $table->string('city', 100)->nullable();
            $table->char('state', 2)->nullable();
            $table->string('timezone', 64)->default('America/Sao_Paulo');
            $table->string('status', 20)->default('active');
            $table->json('settings')->nullable();
            $table->datetimes();
            $table->softDeletesDatetime();

            $table->unique(['company_id', 'code']);
            // Alvo de FKs compostas: um registro que aponta para a filial pertence à MESMA empresa.
            $table->unique(['company_id', 'id']);

            // Uma única matriz ativa por empresa.
            // (RTRIM: o MariaDB não aceita colunas CHAR puras em expressões geradas.)
            $table->string('headquarters_key', 26)->nullable()
                ->storedAs('CASE WHEN is_headquarters = TRUE AND deleted_at IS NULL THEN RTRIM(company_id) END')->unique();
        });

        self::check('companies', 'companies_status_check', "status IN ('trial','active','suspended','cancelled')");
        self::check('branches', 'branches_status_check', "status IN ('active','inactive')");
    }

    public function down(): void
    {
        Schema::dropIfExists('branches');
        Schema::dropIfExists('companies');
        Schema::dropIfExists('saas_plans');
    }

    /** CHECK constraints: aplicadas no PostgreSQL, MariaDB 10.2+ e MySQL 8.0.16+ (ignoradas no MySQL 5.7). */
    public static function check(string $table, string $name, string $expression): void
    {
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expression})");
    }
};
