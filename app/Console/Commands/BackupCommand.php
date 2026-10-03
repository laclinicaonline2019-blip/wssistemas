<?php

namespace App\Console\Commands;

use App\Modules\Platform\Services\BackupService;
use Illuminate\Console\Command;

/** Backup do banco (e, com --files, dos anexos). Agendado diariamente; anexos aos domingos. */
class BackupCommand extends Command
{
    protected $signature = 'aivexa:backup {--files : também os anexos/arquivos privados} {--decrypt= : descriptografa um backup .enc} {--to= : destino da descriptografia}';

    protected $description = 'Gera backup do banco (e anexos) em storage/app/backups, com criptografia opcional';

    public function handle(BackupService $backup): int
    {
        if ($src = $this->option('decrypt')) {
            $password = (string) (config('aivexa.backup.password') ?: $this->secret('Senha do backup'));
            $to = $this->option('to') ?: preg_replace('/\.enc$/', '', $src);
            $backup->decrypt($src, $to, $password);
            $this->info("Descriptografado em {$to}");

            return self::SUCCESS;
        }
        $db = $backup->database();
        $this->info('Banco: '.basename($db['path']).' ('.round($db['size'] / 1048576, 2).' MB, '.$db['tables'].' tabelas)');
        if ($this->option('files')) {
            $f = $backup->files();
            $this->info('Arquivos: '.basename($f['path']).' ('.round($f['size'] / 1048576, 2).' MB, '.$f['files'].' arquivos)');
        }
        if (! config('aivexa.backup.password')) {
            $this->warn('Sem BACKUP_PASSWORD: o backup NÃO está criptografado.');
        }

        return self::SUCCESS;
    }
}
