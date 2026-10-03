<?php

namespace App\Modules\Platform\Services;

use App\Core\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Backup do sistema (Fase 20).
 *
 * - Banco: dump SQL gerado pelo próprio PHP (MySQL/MariaDB — a hospedagem compartilhada nem sempre libera
 *   mysqldump; colunas geradas e gatilhos tratados), compactado (.sql.gz). PostgreSQL: usa pg_dump.
 * - Anexos/arquivos privados (storage/app/companies): .zip.
 * - Com BACKUP_PASSWORD: criptografia AES-256-GCM em blocos (.enc), chave derivada por PBKDF2.
 * - Retenção: mantém os N mais recentes. Guarde cópias FORA do servidor (download pela plataforma).
 */
class BackupService
{
    private const MAGIC = 'AVXB1';

    private const CHUNK = 1048576;

    public function __construct(private readonly AuditLogger $audit) {}

    public function dir(): string
    {
        $dir = storage_path('app/backups');
        if (! is_dir($dir)) {
            mkdir($dir, 0750, true);
        }

        return $dir;
    }

    /** @return array{path: string, size: int, tables: int} */
    public function database(): array
    {
        $stamp = now()->format('Ymd-His');
        $gz = $this->dir()."/aivexa-db-{$stamp}.sql.gz";
        $tables = DB::getDriverName() === 'pgsql' ? $this->pgDump($gz) : $this->mysqlDump($gz);
        $path = $this->maybeEncrypt($gz);
        $this->prune('aivexa-db-', (int) config('aivexa.backup.keep', 14));
        $this->audit->record('backup.created', null, metadata: ['kind' => 'database', 'file' => basename($path), 'size' => filesize($path), 'tables' => $tables], actorType: 'system');

        return ['path' => $path, 'size' => filesize($path), 'tables' => $tables];
    }

    /** @return array{path: string, size: int, files: int} */
    public function files(): array
    {
        $root = storage_path('app');
        $zipPath = $this->dir().'/aivexa-arquivos-'.now()->format('Ymd-His').'.zip';
        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Não foi possível criar o arquivo do backup.');
        }
        $count = 0;
        foreach (['companies', 'private'] as $sub) {
            if (! is_dir("{$root}/{$sub}")) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("{$root}/{$sub}", \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if ($file->isFile()) {
                    $zip->addFile($file->getPathname(), substr($file->getPathname(), strlen($root) + 1));
                    $count++;
                }
            }
        }
        if ($count === 0) {
            $zip->addFromString('LEIA-ME.txt', 'Nenhum arquivo privado no momento do backup.');
        }
        $zip->close();
        $path = $this->maybeEncrypt($zipPath);
        $this->prune('aivexa-arquivos-', (int) config('aivexa.backup.keep_files', 4));
        $this->audit->record('backup.created', null, metadata: ['kind' => 'files', 'file' => basename($path), 'size' => filesize($path), 'files' => $count], actorType: 'system');

        return ['path' => $path, 'size' => filesize($path), 'files' => $count];
    }

    /** @return list<array{name: string, size: int, time: int}> */
    public function list(): array
    {
        return collect(glob($this->dir().'/aivexa-*') ?: [])->map(fn ($f) => ['name' => basename($f), 'size' => filesize($f), 'time' => filemtime($f)])
            ->sortByDesc('time')->values()->all();
    }

    public function path(string $name): string
    {
        if (! preg_match('/^aivexa-(db|arquivos)-\d{8}-\d{6}\.(sql\.gz|zip)(\.enc)?$/', $name) || ! is_file($p = $this->dir().'/'.$name)) {
            throw new RuntimeException('Backup não encontrado.');
        }

        return $p;
    }

    /** Descriptografa um .enc para o arquivo original (restauração). */
    public function decrypt(string $source, string $target, string $password): void
    {
        $in = fopen($source, 'rb');
        if (fread($in, strlen(self::MAGIC)) !== self::MAGIC) {
            throw new RuntimeException('Arquivo não é um backup criptografado do aivexaclinica.');
        }
        $key = hash_pbkdf2('sha256', $password, fread($in, 16), 200000, 32, true);
        $out = fopen($target, 'wb');
        while (($len = fread($in, 4)) !== '' && $len !== false && strlen($len) === 4) {
            $n = unpack('N', $len)[1];
            $nonce = fread($in, 12);
            $tag = fread($in, 16);
            $plain = openssl_decrypt(fread($in, $n), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
            if ($plain === false) {
                fclose($out);
                @unlink($target);
                throw new RuntimeException('Senha incorreta ou arquivo corrompido.');
            }
            fwrite($out, $plain);
        }
        fclose($in);
        fclose($out);
    }

    private function maybeEncrypt(string $path): string
    {
        $password = (string) config('aivexa.backup.password');
        if ($password === '') {
            return $path;
        }
        $salt = random_bytes(16);
        $key = hash_pbkdf2('sha256', $password, $salt, 200000, 32, true);
        $in = fopen($path, 'rb');
        $out = fopen($path.'.enc', 'wb');
        fwrite($out, self::MAGIC.$salt);
        while (! feof($in) && ($chunk = fread($in, self::CHUNK)) !== '' && $chunk !== false) {
            $nonce = random_bytes(12);
            $cipher = openssl_encrypt($chunk, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
            fwrite($out, pack('N', strlen($cipher)).$nonce.$tag.$cipher);
        }
        fclose($in);
        fclose($out);
        unlink($path);

        return $path.'.enc';
    }

    private function mysqlDump(string $gz): int
    {
        $pdo = DB::connection()->getPdo();
        $db = DB::connection()->getDatabaseName();
        $out = gzopen($gz, 'wb6');
        $w = fn (string $s) => gzwrite($out, $s);
        $w('-- aivexaclinica — backup do banco '.$db.' em '.now()->toIso8601String()."\n-- Restaurar: mysql BANCO < arquivo.sql (ou importar pelo phpMyAdmin)\n");
        $w("SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET UNIQUE_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");

        $snapshot = DB::transactionLevel() === 0;
        if ($snapshot) {
            $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        }
        $tables = array_map(fn ($r) => array_values((array) $r)[0], DB::select("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'"));
        try {
            foreach ($tables as $t) {
                $create = (array) DB::selectOne('SHOW CREATE TABLE `'.str_replace('`', '``', $t).'`');
                $w("DROP TABLE IF EXISTS `{$t}`;\n".array_values($create)[1].";\n");
                $cols = DB::table('information_schema.COLUMNS')->where('TABLE_SCHEMA', $db)->where('TABLE_NAME', $t)
                    ->where('EXTRA', 'not like', '%GENERATED%')->orderBy('ORDINAL_POSITION')->pluck('COLUMN_NAME')->all();
                $pk = DB::table('information_schema.KEY_COLUMN_USAGE')->where('TABLE_SCHEMA', $db)->where('TABLE_NAME', $t)->where('CONSTRAINT_NAME', 'PRIMARY')
                    ->orderBy('ORDINAL_POSITION')->pluck('COLUMN_NAME')->all() ?: [$cols[0]];
                $list = '`'.implode('`,`', $cols).'`';
                $offset = 0;
                do {
                    $rows = DB::table($t)->select($cols)->orderBy($pk[0])->when(count($pk) > 1, fn ($q) => $q->orderBy($pk[1]))->offset($offset)->limit(500)->get();
                    if ($rows->isNotEmpty()) {
                        $values = $rows->map(fn ($r) => '('.implode(',', array_map(fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), array_values((array) $r))).')');
                        $w("INSERT INTO `{$t}` ({$list}) VALUES\n".$values->implode(",\n").";\n");
                    }
                    $offset += 500;
                } while ($rows->count() === 500);
                $w("\n");
            }
            foreach (DB::select('SHOW TRIGGERS') as $tr) {
                $tr = (array) $tr;
                $w("DROP TRIGGER IF EXISTS `{$tr['Trigger']}`;\nDELIMITER ;;\nCREATE TRIGGER `{$tr['Trigger']}` {$tr['Timing']} {$tr['Event']} ON `{$tr['Table']}` FOR EACH ROW {$tr['Statement']};;\nDELIMITER ;\n");
            }
        } finally {
            if ($snapshot) {
                $pdo->exec('COMMIT');
            }
        }
        $w("\nSET FOREIGN_KEY_CHECKS=1;\nSET UNIQUE_CHECKS=1;\n");
        gzclose($out);

        return count($tables);
    }

    private function pgDump(string $gz): int
    {
        $c = DB::connection()->getConfig();
        $cmd = sprintf('PGPASSWORD=%s pg_dump -h %s -p %s -U %s --no-owner %s', escapeshellarg((string) ($c['password'] ?? '')), escapeshellarg((string) $c['host']),
            escapeshellarg((string) $c['port']), escapeshellarg((string) $c['username']), escapeshellarg((string) $c['database']));
        $proc = popen($cmd.' 2>/dev/null', 'r');
        if (! $proc) {
            throw new RuntimeException('pg_dump indisponível.');
        }
        $out = gzopen($gz, 'wb6');
        $bytes = 0;
        while (! feof($proc)) {
            $bytes += gzwrite($out, (string) fread($proc, self::CHUNK));
        }
        gzclose($out);
        if (pclose($proc) !== 0 || $bytes === 0) {
            @unlink($gz);
            throw new RuntimeException('pg_dump falhou (instale o cliente do PostgreSQL ou use o backup do provedor).');
        }

        return (int) DB::selectOne("SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema = 'public'")->n;
    }

    private function prune(string $prefix, int $keep): void
    {
        $files = collect(glob($this->dir().'/'.$prefix.'*') ?: [])->sortByDesc(fn ($f) => filemtime($f).Str::afterLast($f, '/'))->values();
        foreach ($files->slice(max(1, $keep)) as $old) {
            @unlink($old);
        }
    }
}
