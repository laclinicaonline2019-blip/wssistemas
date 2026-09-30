<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            // Sem FKs de propósito: a trilha precisa sobreviver a qualquer alteração nas entidades.
            $table->char('company_id', 26)->nullable();
            $table->char('branch_id', 26)->nullable();
            $table->char('user_id', 26)->nullable();
            $table->string('actor_type', 20)->default('user');
            $table->string('action', 100);
            $table->string('auditable_type', 100)->nullable();
            $table->string('auditable_id', 64)->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('result', 20)->default('success');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('request_id', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->dateTime('created_at')->useCurrent();
            // Encadeamento criptográfico (HMAC-SHA256) por empresa: qualquer alteração ou
            // exclusão de registro quebra a cadeia e é detectada por `aivexa:audit:verify`.
            $table->char('prev_hash', 64)->nullable();
            $table->char('hash', 64)->nullable();

            $table->index(['company_id', 'created_at']);
            $table->index(['company_id', 'auditable_type', 'auditable_id']);
            $table->index(['company_id', 'user_id', 'created_at']);
            $table->index(['action', 'created_at']);
        });

        Schema::create('audit_chain_heads', function (Blueprint $table) {
            $table->string('scope', 26)->primary()->comment('company_id ou "platform"');
            $table->unsignedBigInteger('last_id')->nullable();
            $table->char('last_hash', 64);
            $table->dateTime('updated_at')->nullable();
        });

        DB::statement("ALTER TABLE audit_logs ADD CONSTRAINT audit_logs_result_check CHECK (result IN ('success','failure','denied'))");

        self::installImmutabilityTriggers();
    }

    public function down(): void
    {
        self::dropImmutabilityTriggers();
        Schema::dropIfExists('audit_chain_heads');
        Schema::dropIfExists('audit_logs');
    }

    /**
     * Trilha append-only: UPDATE/DELETE bloqueados no próprio banco.
     *
     * Em hospedagem compartilhada (MySQL com binlog e sem privilégio SUPER) a
     * criação de triggers pode ser negada. Nesse caso a migration NÃO falha: a
     * imutabilidade continua garantida pela aplicação e o painel de saúde
     * sinaliza que a proteção no banco não está ativa.
     */
    public static function installImmutabilityTriggers(): void
    {
        try {
            if (DB::getDriverName() === 'pgsql') {
                DB::unprepared(<<<'SQL'
                    CREATE OR REPLACE FUNCTION audit_logs_block_mutation() RETURNS trigger AS $$
                    BEGIN
                        RAISE EXCEPTION 'audit_logs é somente inserção (append-only)';
                    END;
                    $$ LANGUAGE plpgsql;
                    CREATE TRIGGER audit_logs_immutable BEFORE UPDATE OR DELETE ON audit_logs
                        FOR EACH ROW EXECUTE FUNCTION audit_logs_block_mutation();
                    CREATE TRIGGER audit_logs_no_truncate BEFORE TRUNCATE ON audit_logs
                        FOR EACH STATEMENT EXECUTE FUNCTION audit_logs_block_mutation();
                SQL);

                return;
            }

            foreach (['UPDATE' => 'audit_logs_no_update', 'DELETE' => 'audit_logs_no_delete'] as $event => $name) {
                DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON audit_logs FOR EACH ROW
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs é somente inserção (append-only)'");
            }
        } catch (Throwable $e) {
            Log::warning('Triggers de imutabilidade da auditoria não criados (privilégio insuficiente?): '.$e->getMessage());
        }
    }

    public static function dropImmutabilityTriggers(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_truncate ON audit_logs');
            DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_immutable ON audit_logs');
            DB::unprepared('DROP FUNCTION IF EXISTS audit_logs_block_mutation()');

            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_delete');
    }
};
