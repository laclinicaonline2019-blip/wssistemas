<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Auditoria v2 — migração ADITIVA (versão 1.1.1).
 *
 * Não altera nem apaga nenhum valor existente:
 *  - audit_logs: + hash_version (NULL = v1) e + created_at_utc (NULL nos registros antigos);
 *  - índice único em prev_hash (impede bifurcação da cadeia);
 *  - tabela audit_chain_head (topo da cadeia, versão de gravação, chave), criada a partir do último registro;
 *  - MySQL/MariaDB: alterações com ALGORITHM=INPLACE, LOCK=NONE (compatível com 5.7; sem INSTANT) e triggers
 *    de proteção (append-only, guarda da cadeia, antirrebaixamento, avanço do topo).
 *
 * Verificações: antes (sem prev_hash duplicado) e depois (impressão SHA-256 de cada registro idêntica).
 * down() não remove nada: as estruturas de proteção nunca são desfeitas automaticamente.
 */
return new class extends Migration
{
    public function up(): void
    {
        $mysql = in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);

        // ---------------------------------------------------------- verificações prévias
        $dups = DB::table('audit_logs')->select('prev_hash')->groupBy('prev_hash')->havingRaw('COUNT(*) > 1')->get();
        if ($dups->isNotEmpty()) {
            throw new RuntimeException('Auditoria: existem registros com prev_hash repetido (bifurcação). Migração interrompida sem alterações.');
        }
        if ($mysql) {
            $this->probeTriggerPrivilege();   // antes de qualquer alteração: se não puder criar triggers, nada muda
        }
        $before = $mysql ? $this->fingerprints() : null;

        // ---------------------------------------------------------- colunas e índice (aditivos)
        if (! Schema::hasColumn('audit_logs', 'hash_version')) {
            if ($mysql) {
                DB::statement('ALTER TABLE audit_logs ADD COLUMN hash_version TINYINT UNSIGNED NULL, ADD COLUMN created_at_utc CHAR(27) NULL, ALGORITHM=INPLACE, LOCK=NONE');
            } else {
                Schema::table('audit_logs', function (Blueprint $t) {
                    $t->unsignedTinyInteger('hash_version')->nullable();
                    $t->char('created_at_utc', 27)->nullable();
                });
            }
        }
        if (! $this->hasIndex('audit_logs', 'audit_logs_prev_hash_unique')) {
            $mysql
                ? DB::statement('ALTER TABLE audit_logs ADD UNIQUE INDEX audit_logs_prev_hash_unique (prev_hash), ALGORITHM=INPLACE, LOCK=NONE')
                : Schema::table('audit_logs', fn (Blueprint $t) => $t->unique('prev_hash', 'audit_logs_prev_hash_unique'));
        }

        // ---------------------------------------------------------- topo da cadeia
        if (! Schema::hasTable('audit_chain_head')) {
            Schema::create('audit_chain_head', function (Blueprint $t) {
                $t->unsignedTinyInteger('id')->primary();
                $t->unsignedBigInteger('last_id')->default(0);
                $t->char('last_hash', 64)->nullable();
                $t->unsignedTinyInteger('write_version')->default(1);
                $t->char('key_id', 16)->nullable();
                $t->char('activated_v2_at', 27)->nullable();
                $t->unsignedBigInteger('activated_v2_from_id')->nullable();
                $t->timestamp('updated_at')->nullable();
            });
            $last = DB::table('audit_logs')->orderByDesc('id')->first(['id', 'hash']);
            DB::table('audit_chain_head')->insert(['id' => 1, 'last_id' => $last->id ?? 0, 'last_hash' => $last->hash ?? null, 'write_version' => 1]);
        }

        // ---------------------------------------------------------- triggers (MySQL/MariaDB)
        if ($mysql) {
            foreach (['audit_logs_no_update', 'audit_logs_no_delete', 'audit_logs_chain_guard', 'audit_logs_advance_head', 'audit_head_guard', 'audit_head_no_delete'] as $t) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$t}");
            }
            DB::unprepared(<<<'SQL'
CREATE TRIGGER audit_logs_no_update BEFORE UPDATE ON audit_logs FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs e append-only: UPDATE recusado';
END
SQL);
            DB::unprepared(<<<'SQL'
CREATE TRIGGER audit_logs_no_delete BEFORE DELETE ON audit_logs FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs e append-only: DELETE recusado';
END
SQL);
            DB::unprepared(<<<'SQL'
CREATE TRIGGER audit_logs_chain_guard BEFORE INSERT ON audit_logs FOR EACH ROW
BEGIN
  DECLARE v_last BIGINT UNSIGNED DEFAULT NULL;
  DECLARE v_hash CHAR(64) DEFAULT NULL;
  DECLARE v_ver TINYINT UNSIGNED DEFAULT NULL;
  SELECT last_id, last_hash, write_version INTO v_last, v_hash, v_ver FROM audit_chain_head WHERE id = 1 FOR UPDATE;
  IF v_last IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_chain_head ausente';
  END IF;
  IF (v_last = 0 AND NEW.prev_hash IS NOT NULL) OR (v_last > 0 AND (NEW.prev_hash IS NULL OR BINARY NEW.prev_hash <> BINARY v_hash)) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'prev_hash diferente do topo da cadeia (bifurcacao)';
  END IF;
  IF v_ver >= 2 AND (NEW.hash_version IS NULL OR NEW.hash_version < v_ver) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'rebaixamento: gravacao v1 apos ativacao do v2';
  END IF;
  IF NEW.hash_version IS NOT NULL AND NEW.hash_version >= 2 AND (NEW.created_at_utc IS NULL OR NEW.created_at_utc NOT REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}[.][0-9]{6}Z$') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'created_at_utc invalido';
  END IF;
END
SQL);
            DB::unprepared(<<<'SQL'
CREATE TRIGGER audit_logs_advance_head AFTER INSERT ON audit_logs FOR EACH ROW
  UPDATE audit_chain_head SET last_id = NEW.id, last_hash = NEW.hash WHERE id = 1
SQL);
            DB::unprepared(<<<'SQL'
CREATE TRIGGER audit_head_guard BEFORE UPDATE ON audit_chain_head FOR EACH ROW
BEGIN
  IF NEW.write_version < OLD.write_version THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'write_version nao pode diminuir';
  END IF;
  IF NEW.last_id < OLD.last_id THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'topo da cadeia nao pode retroceder';
  END IF;
  IF OLD.key_id IS NOT NULL AND (NEW.key_id IS NULL OR BINARY NEW.key_id <> BINARY OLD.key_id) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'key_id historico nao pode mudar';
  END IF;
  IF OLD.activated_v2_from_id IS NOT NULL AND (NEW.activated_v2_from_id IS NULL OR NEW.activated_v2_from_id <> OLD.activated_v2_from_id) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'marco de ativacao do v2 nao pode mudar';
  END IF;
END
SQL);
            DB::unprepared(<<<'SQL'
CREATE TRIGGER audit_head_no_delete BEFORE DELETE ON audit_chain_head FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_chain_head nao pode ser apagada';
END
SQL);
        }

        // ---------------------------------------------------------- verificação posterior
        if ($mysql && $before !== $this->fingerprints()) {
            throw new RuntimeException('Auditoria: a impressão SHA-256 dos registros mudou durante a migração. Interrompido.');
        }
    }

    /**
     * Confirma que o usuário do banco pode criar triggers (com binlog ligado o MySQL exige SUPER ou
     * log_bin_trust_function_creators=1). Usa uma tabela temporária de prova, removida em seguida.
     */
    private function probeTriggerPrivilege(): void
    {
        DB::statement('DROP TABLE IF EXISTS wsf_trigger_probe');
        DB::statement('CREATE TABLE wsf_trigger_probe (id INT NOT NULL PRIMARY KEY) ENGINE=InnoDB');
        try {
            DB::unprepared('CREATE TRIGGER wsf_trigger_probe_bi BEFORE INSERT ON wsf_trigger_probe FOR EACH ROW SET NEW.id = NEW.id');
            DB::unprepared('DROP TRIGGER wsf_trigger_probe_bi');
        } catch (Throwable $e) {
            throw new RuntimeException('Auditoria: o usuário do banco não pode criar triggers ('.$e->getMessage().'). '
                .'Com log binário ligado, o MySQL exige o privilégio SUPER ou log_bin_trust_function_creators=1 (peça ao suporte da hospedagem). '
                .'Migração interrompida antes de qualquer alteração na auditoria.');
        } finally {
            DB::statement('DROP TABLE IF EXISTS wsf_trigger_probe');
        }
    }

    /** Estruturas de proteção nunca são removidas automaticamente (reversão de código não desfaz a proteção). */
    public function down(): void {}

    /** @return array<int, string> impressão SHA-256 de cada registro (mesma fórmula da consulta de verificação manual). */
    private function fingerprints(): array
    {
        return DB::table('audit_logs')->orderBy('id')->selectRaw(
            "id, SHA2(CONCAT_WS('|', id, IFNULL(account_id,'<null>'), IFNULL(user_id,'<null>'), action, IFNULL(subject_type,'<null>'), IFNULL(subject_id,'<null>'), ".
            "IFNULL(CAST(data AS CHAR),'<null>'), IFNULL(ip,'<null>'), channel, IFNULL(prev_hash,'<null>'), hash, UNIX_TIMESTAMP(created_at)), 256) AS sha"
        )->pluck('sha', 'id')->all();
    }

    private function hasIndex(string $table, string $name): bool
    {
        return collect(Schema::getIndexes($table))->contains(fn ($i) => $i['name'] === $name);
    }
};
