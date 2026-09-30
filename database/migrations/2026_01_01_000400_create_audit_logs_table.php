<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            // Sem FKs de propósito: a trilha precisa sobreviver a qualquer alteração
            // nas entidades auditadas.
            $table->ulid('company_id')->nullable();
            $table->ulid('branch_id')->nullable();
            $table->ulid('user_id')->nullable();
            $table->string('actor_type', 20)->default('user');
            $table->string('action', 100);
            $table->string('auditable_type', 100)->nullable();
            $table->string('auditable_id', 64)->nullable();
            $table->jsonb('old_values')->nullable();
            $table->jsonb('new_values')->nullable();
            $table->string('result', 20)->default('success');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('request_id', 64)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['company_id', 'created_at']);
            $table->index(['company_id', 'auditable_type', 'auditable_id']);
            $table->index(['company_id', 'user_id', 'created_at']);
            $table->index(['action', 'created_at']);
        });

        DB::statement("ALTER TABLE audit_logs ADD CONSTRAINT audit_logs_result_check CHECK (result IN ('success','failure','denied'))");

        // Trilha append-only: UPDATE/DELETE bloqueados no próprio banco.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_logs_block_mutation() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'audit_logs é somente inserção (append-only)';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER audit_logs_immutable
                BEFORE UPDATE OR DELETE ON audit_logs
                FOR EACH ROW EXECUTE FUNCTION audit_logs_block_mutation();

            CREATE TRIGGER audit_logs_no_truncate
                BEFORE TRUNCATE ON audit_logs
                FOR EACH STATEMENT EXECUTE FUNCTION audit_logs_block_mutation();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_truncate ON audit_logs');
        DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_immutable ON audit_logs');
        Schema::dropIfExists('audit_logs');
        DB::unprepared('DROP FUNCTION IF EXISTS audit_logs_block_mutation()');
    }
};
