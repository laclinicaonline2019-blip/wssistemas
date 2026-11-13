<?php

namespace App\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Support\BusinessRule;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Backup lógico feito pelo próprio PHP (funciona na hospedagem compartilhada, sem mysqldump):
 * todas as tabelas em JSON, compactado (gzip) e criptografado (AES-256-GCM, chave derivada de BACKUP_PASSWORD).
 * `verify()` restaura o arquivo num banco SQLite temporário e confere as contagens — teste de restauração automático.
 */
class BackupService
{
    public const TABLES = ['plans', 'accounts', 'users', 'memberships', 'companies', 'company_access', 'subscriptions', 'invoices', 'subscription_events', 'webhook_events',
        'platform_settings', 'bank_accounts', 'entries', 'debts', 'company_settings', 'local_imports', 'whatsapp_contacts', 'whatsapp_messages', 'whatsapp_pendings',
        'usage_counters', 'audit_logs', 'audit_chain_head', 'password_reset_tokens'];

    /** Chave primária de cada tabela (para repor só o que falta, sem sobrescrever). */
    private const PK = ['company_settings' => 'company_id', 'platform_settings' => 'key', 'whatsapp_pendings' => 'phone_hash', 'password_reset_tokens' => 'email'];

    private const MAGIC = 'WSFB1';

    public function __construct(private readonly AuditLogger $audit) {}

    public function dir(): string
    {
        $dir = storage_path('app/backups');
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        return $dir;
    }

    /** @return array{file:string, counts:array<string,int>, bytes:int} */
    public function create(): array
    {
        $data = ['format' => 1, 'created_at' => now()->toIso8601String(), 'app_version' => config('app.version', '1.0.0'), 'tables' => []];
        $counts = [];
        foreach (self::TABLES as $t) {
            if (! Schema::hasTable($t)) {
                continue;
            }
            $rows = [];
            foreach (DB::table($t)->orderBy(Schema::getColumnListing($t)[0])->cursor() as $r) {
                $rows[] = (array) $r;
            }
            $data['tables'][$t] = $rows;
            $counts[$t] = count($rows);
        }
        $payload = $this->encrypt(gzencode(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 6));
        $file = $this->dir().'/wsf-'.now()->format('Ymd-His').'.wsfbak';
        file_put_contents($file, $payload);
        chmod($file, 0600);
        $this->prune();
        $this->audit->log('backup.criar', null, ['arquivo' => basename($file), 'bytes' => strlen($payload)], channel: 'sistema');

        return ['file' => $file, 'counts' => $counts, 'bytes' => strlen($payload)];
    }

    public function read(string $file): array
    {
        $raw = @file_get_contents($file);
        if ($raw === false) {
            throw new BusinessRule('Arquivo de backup não encontrado.');
        }
        $json = gzdecode($this->decrypt($raw));
        $data = $json === false ? null : json_decode($json, true);
        if (! is_array($data) || ($data['format'] ?? null) !== 1) {
            throw new BusinessRule('Backup inválido ou corrompido.');
        }

        return $data;
    }

    /**
     * Restauração SEM PERDA (1.1.1): nunca apaga, nunca sobrescreve.
     *  - tabela vazia no destino: recebe todos os registros do backup (banco novo, teste de restauração);
     *  - tabela com dados: recebe só os registros cuja chave primária não existe; registros existentes e
     *    diferentes do backup são PRESERVADOS e informados como conflito;
     *  - auditoria: só é preenchida em destino vazio; com dados, apenas confere se o backup é um trecho inicial
     *    idêntico da cadeia atual (nunca insere no meio da cadeia nem apaga).
     *
     * @return array<string, array{inseridos:int, existentes:int, conflitos:int}>
     */
    public function restore(string $file, ?string $connection = null): array
    {
        $data = $this->read($file);
        $db = DB::connection($connection);
        $driver = $db->getDriverName();
        $report = [];

        $fk = fn (bool $on) => match ($driver) {
            'mysql', 'mariadb' => $db->statement('SET FOREIGN_KEY_CHECKS='.($on ? 1 : 0)),
            'sqlite' => $db->statement('PRAGMA foreign_keys = '.($on ? 'ON' : 'OFF')),
            default => null,
        };
        $fk(false);
        try {
            foreach (self::TABLES as $t) {
                if ($t === 'audit_chain_head' || ! $db->getSchemaBuilder()->hasTable($t)) {
                    continue;
                }
                $rows = $data['tables'][$t] ?? [];
                $pk = self::PK[$t] ?? 'id';
                $r = ['inseridos' => 0, 'existentes' => 0, 'conflitos' => 0];

                if ($t === 'audit_logs') {
                    $report[$t] = $this->restoreAudit($db, $rows, $data['tables']['audit_chain_head'] ?? []);

                    continue;
                }
                $existing = $db->table($t)->pluck($pk)->map(fn ($v) => (string) $v)->flip();
                $toInsert = [];
                foreach ($rows as $row) {
                    $key = (string) ($row[$pk] ?? '');
                    if (! isset($existing[$key])) {
                        $toInsert[] = $row;

                        continue;
                    }
                    $r['existentes']++;
                    $current = (array) $db->table($t)->where($pk, $row[$pk])->first();
                    if ($this->differs($current, $row)) {
                        $r['conflitos']++;
                    }
                }
                foreach (array_chunk($toInsert, 300) as $chunk) {
                    $db->table($t)->insert($chunk);
                }
                $r['inseridos'] = count($toInsert);
                $report[$t] = $r;
            }
        } finally {
            $fk(true);
        }

        return $report;
    }

    /** Auditoria append-only: preenche só destino vazio (em ordem, respeitando os triggers); senão, só confere. */
    private function restoreAudit(Connection $db, array $rows, array $headRows): array
    {
        $r = ['inseridos' => 0, 'existentes' => 0, 'conflitos' => 0];
        $currentCount = $db->table('audit_logs')->count();
        if ($currentCount === 0) {
            usort($rows, fn ($a, $b) => $a['id'] <=> $b['id']);
            foreach ($rows as $row) {
                $db->table('audit_logs')->insert($row);
                $r['inseridos']++;
            }
            if ($db->getSchemaBuilder()->hasTable('audit_chain_head') && $rows) {
                $last = end($rows);
                $bh = $headRows[0] ?? [];
                $head = (array) $db->table('audit_chain_head')->where('id', 1)->first();
                $db->table('audit_chain_head')->where('id', 1)->update(array_filter([
                    'last_id' => $last['id'], 'last_hash' => $last['hash'],
                    'write_version' => max((int) ($head['write_version'] ?? 1), (int) ($bh['write_version'] ?? 1)),
                    'key_id' => $head['key_id'] ?? ($bh['key_id'] ?? null),
                    'activated_v2_at' => $head['activated_v2_at'] ?? ($bh['activated_v2_at'] ?? null),
                    'activated_v2_from_id' => $head['activated_v2_from_id'] ?? ($bh['activated_v2_from_id'] ?? null),
                ], fn ($v) => $v !== null));
            }

            return $r;
        }
        $current = $db->table('audit_logs')->pluck('hash', 'id');
        foreach ($rows as $row) {
            if (isset($current[$row['id']])) {
                $r['existentes']++;
                if (! hash_equals((string) $current[$row['id']], (string) $row['hash'])) {
                    $r['conflitos']++;
                }
            } else {
                $r['conflitos']++;   // registro do backup ausente na cadeia atual: informado, nunca reinserido no meio
            }
        }

        return $r;
    }

    private function differs(array $current, array $backup): bool
    {
        foreach ($backup as $k => $v) {
            if (array_key_exists($k, $current) && (string) $current[$k] !== (string) $v) {
                return true;
            }
        }

        return false;
    }

    /** Teste de restauração: restaura num SQLite em memória e compara as contagens com as do arquivo. */
    public function verify(string $file): array
    {
        $data = $this->read($file);
        config(['database.connections.wsf_verify' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        DB::purge('wsf_verify');
        Artisan::call('migrate', ['--database' => 'wsf_verify', '--force' => true]);
        $this->restore($file, 'wsf_verify');
        $restored = [];
        $problems = [];
        foreach (self::TABLES as $t) {
            if (! isset($data['tables'][$t])) {
                continue;
            }
            $restored[$t] = DB::connection('wsf_verify')->table($t)->count();
            $expected = count($data['tables'][$t]);
            $actual = $restored[$t];
            if ($t === 'audit_chain_head') {
                continue; // criada pela migração no banco temporário
            }
            if ($expected !== $actual) {
                $problems[] = "$t: esperado $expected, restaurado $actual";
            }
        }
        DB::purge('wsf_verify');
        $ok = $problems === [];
        $this->audit->log('backup.teste_restauracao', null, ['arquivo' => basename($file), 'ok' => $ok, 'problemas' => $problems], channel: 'sistema');

        return ['ok' => $ok, 'problems' => $problems, 'counts' => $restored];
    }

    public function latest(): ?string
    {
        $files = glob($this->dir().'/*.wsfbak') ?: [];
        rsort($files);

        return $files[0] ?? null;
    }

    private function prune(): void
    {
        $files = glob($this->dir().'/*.wsfbak') ?: [];
        rsort($files);
        foreach (array_slice($files, max(1, (int) config('wsf.backup.keep'))) as $old) {
            @unlink($old);
        }
    }

    private function key(string $salt): string
    {
        $password = (string) config('wsf.backup.password');
        if ($password === '') {
            if (! app()->environment('local', 'testing')) {
                throw new BusinessRule('Defina BACKUP_PASSWORD no .env para gerar/ler backups criptografados.');
            }
            $password = (string) config('app.key');
        }

        return hash_pbkdf2('sha256', $password, $salt, 100000, 32, true);
    }

    private function encrypt(string $plain): string
    {
        $salt = random_bytes(16);
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $this->key($salt), OPENSSL_RAW_DATA, $iv, $tag);

        return self::MAGIC.$salt.$iv.$tag.$cipher;
    }

    private function decrypt(string $raw): string
    {
        if (! str_starts_with($raw, self::MAGIC)) {
            throw new BusinessRule('Arquivo não é um backup do WS Finanças.');
        }
        $o = strlen(self::MAGIC);
        $salt = substr($raw, $o, 16);
        $iv = substr($raw, $o + 16, 12);
        $tag = substr($raw, $o + 28, 16);
        $plain = openssl_decrypt(substr($raw, $o + 44), 'aes-256-gcm', $this->key($salt), OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) {
            throw new BusinessRule('Não foi possível descriptografar (senha do backup incorreta ou arquivo alterado).');
        }

        return $plain;
    }
}
