<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('key', 100)->unique();
            $table->string('module', 50)->index();
            $table->string('description', 255);
            $table->string('scope', 20)->default('tenant');
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE permissions ADD CONSTRAINT permissions_scope_check CHECK (scope IN ('tenant','platform'))");

        Schema::create('roles', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('key', 60);
            $table->string('name', 120);
            $table->string('description', 255)->nullable();
            $table->boolean('is_system')->default(false);
            $table->boolean('is_locked')->default(false);
            $table->timestampsTz();

            $table->unique(['company_id', 'key']);
            $table->unique(['company_id', 'id']);
        });

        Schema::create('role_permission', function (Blueprint $table) {
            $table->foreignUlid('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignUlid('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->primary(['role_id', 'permission_id']);
        });

        Schema::create('user_role_assignments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('company_id');
            $table->ulid('user_id');
            $table->ulid('role_id');
            $table->ulid('branch_id')->nullable()->comment('NULL = todas as filiais da empresa');
            $table->ulid('assigned_by')->nullable();
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            // FKs compostas: usuário, perfil e filial obrigatoriamente da mesma empresa.
            $table->foreign(['company_id', 'user_id'])->references(['company_id', 'id'])->on('users')->cascadeOnDelete();
            $table->foreign(['company_id', 'role_id'])->references(['company_id', 'id'])->on('roles')->cascadeOnDelete();
            $table->foreign(['company_id', 'branch_id'])->references(['company_id', 'id'])->on('branches')->restrictOnDelete();
            $table->foreign('assigned_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['company_id', 'user_id']);
        });

        DB::statement('ALTER TABLE user_role_assignments ADD CONSTRAINT user_role_assignments_unique UNIQUE NULLS NOT DISTINCT (user_id, role_id, branch_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('user_role_assignments');
        Schema::dropIfExists('role_permission');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
    }
};
