<?php

namespace App\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Support\BusinessRule;
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
        'usage_counters', 'audit_logs', 'password_reset_tokens'];

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

    /** Restaura (substitui TODOS os dados da conexão). */
    public function restore(string $file, ?string $connection = null): array
    {
        $data = $this->read($file);
        $db = DB::connection($connection);
        $driver = $db->getDriverName();
        $counts = [];

        $fk = fn (bool $on) => match ($driver) {
            'mysql', 'mariadb' => $db->statement('SET FOREIGN_KEY_CHECKS='.($on ? 1 : 0)),
            'sqlite' => $db->statement('PRAGMA foreign_keys = '.($on ? 'ON' : 'OFF')),
            'pgsql' => $db->statement("SET session_replication_role = '".($on ? 'origin' : 'replica')."'"),
            default => null,
        };
        $fk(false);
        try {
            $db->transaction(function () use ($db, $data, &$counts) {
                foreach (array_reverse(self::TABLES) as $t) {
                    $db->table($t)->delete();
                }
                foreach (self::TABLES as $t) {
                    $rows = $data['tables'][$t] ?? [];
                    foreach (array_chunk($rows, 300) as $chunk) {
                        $db->table($t)->insert($chunk);
                    }
                    $counts[$t] = count($rows);
                }
            });
        } finally {
            $fk(true);
        }

        return $counts;
    }

    /** Teste de restauração: restaura num SQLite em memória e compara as contagens com as do arquivo. */
    public function verify(string $file): array
    {
        $data = $this->read($file);
        config(['database.connections.wsf_verify' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        DB::purge('wsf_verify');
        Artisan::call('migrate', ['--database' => 'wsf_verify', '--force' => true]);
        $restored = $this->restore($file, 'wsf_verify');
        $problems = [];
        foreach (self::TABLES as $t) {
            $expected = count($data['tables'][$t] ?? []);
            $actual = DB::connection('wsf_verify')->table($t)->count();
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
