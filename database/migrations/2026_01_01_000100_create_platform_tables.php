<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
            // Limites e recursos: max_users, max_doctors, max_branches, storage_mb,
            // ai_enabled, whatsapp_enabled, ai_monthly_messages ...
            $table->jsonb('limits')->default('{}');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        Schema::create('companies', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('legal_name', 200);
            $table->string('trade_name', 200);
            $table->string('document', 14)->comment('CNPJ (somente dígitos)');
            $table->string('slug', 80)->unique();
            $table->string('email', 190)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('status', 20)->default('trial');
            $table->foreignUlid('saas_plan_id')->nullable()->constrained('saas_plans')->restrictOnDelete();
            $table->timestampTz('trial_ends_at')->nullable();
            $table->jsonb('settings')->default('{}');
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('status');
        });

        DB::statement("ALTER TABLE companies ADD CONSTRAINT companies_status_check CHECK (status IN ('trial','active','suspended','cancelled'))");
        DB::statement('CREATE UNIQUE INDEX companies_document_unique ON companies (document) WHERE deleted_at IS NULL');

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
            $table->jsonb('settings')->default('{}');
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['company_id', 'code']);
            // Alvo de FKs compostas: garante que um registro que aponta para a
            // filial pertence à MESMA empresa (integridade multi-tenant no banco).
            $table->unique(['company_id', 'id']);
        });

        DB::statement("ALTER TABLE branches ADD CONSTRAINT branches_status_check CHECK (status IN ('active','inactive'))");
        DB::statement('CREATE UNIQUE INDEX branches_one_headquarters ON branches (company_id) WHERE is_headquarters AND deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('branches');
        Schema::dropIfExists('companies');
        Schema::dropIfExists('saas_plans');
    }
};
