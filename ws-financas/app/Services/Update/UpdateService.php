<?php

namespace App\Services\Update;

use App\Core\Audit\AuditLogger;
use App\Core\Support\BusinessRule;
use App\Services\BackupService;
use App\Services\Install\WebInstaller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;
use ZipArchive;

/**
 * Atualização pelo painel, sem SSH, em etapas curtas (cada uma é uma requisição — cabe no
 * max_execution_time da hospedagem compartilhada) e retomáveis:
 *
 *   validar → backup do banco → extrair → conferir arquivos → manutenção → trocar pastas
 *   → (requisição seguinte, já com o código novo) migrar → conferir → concluir
 *
 * A troca é feita por RENOMEAR pastas (instantâneo, mesmo disco); a versão anterior fica guardada
 * para reverter. Falha em "migrar"/"conferir" → reversão automática (código + banco do backup).
 * .env e storage/ nunca são alterados. Nenhum comando do navegador é executado: só este fluxo fixo.
 */
class UpdateService
{
    public const STEPS = [
        'validar' => 'Validar pacote',
        'backup' => 'Backup do banco de dados',
        'extrair' => 'Extrair arquivos (área temporária)',
        'conferir_arquivos' => 'Conferir integridade (SHA-256)',
        'manutencao' => 'Ativar modo de manutenção',
        'trocar' => 'Trocar arquivos do sistema',
        'migrar' => 'Atualizar o banco (migrações)',
        'conferir' => 'Conferir funcionamento',
        'concluir' => 'Concluir e sair da manutenção',
    ];

    public function __construct(private readonly PackageInspector $inspector, private readonly BackupService $backups, private readonly AuditLogger $audit) {}

    // ------------------------------------------------------------------ caminhos

    public function root(): string
    {
        $dir = storage_path('app/updates');
        foreach ([$dir, $dir.'/pacotes', $dir.'/execucoes'] as $d) {
            if (! is_dir($d)) {
                @mkdir($d, 0700, true);
            }
        }
        if (! is_file($dir.'/.htaccess')) {
            @file_put_contents($dir.'/.htaccess', WebInstaller::DENY_HTACCESS);
        }

        return $dir;
    }

    public function inbox(): string
    {
        return $this->root().'/pacotes';
    }

    public function runDir(string $id): string
    {
        if (! preg_match('/^[0-9a-z]{26}$/', $id)) {
            throw new BusinessRule('Execução inválida.');
        }

        return $this->root().'/execucoes/'.$id;
    }

    /** @return list<array{name:string, size:int, at:int}> */
    public function packages(): array
    {
        $list = [];
        foreach (glob($this->inbox().'/*.zip') ?: [] as $f) {
            $list[] = ['name' => basename($f), 'size' => (int) filesize($f), 'at' => (int) filemtime($f)];
        }
        usort($list, fn ($a, $b) => $b['at'] <=> $a['at']);

        return $list;
    }

    public function packagePath(string $name): string
    {
        if (! preg_match('/^[A-Za-z0-9._\-]{1,120}\.zip$/', $name) || ! is_file($this->inbox().'/'.$name)) {
            throw new BusinessRule('Pacote não encontrado em storage/app/updates/pacotes/.');
        }

        return $this->inbox().'/'.$name;
    }

    public function canWriteCode(): bool
    {
        foreach (array_merge(PackageInspector::DIRS, ['.']) as $d) {
            $p = base_path($d);
            if (file_exists($p) && ! is_writable($p)) {
                return false;
            }
        }

        return true;
    }

    // ------------------------------------------------------------------ início

    public function start(string $package, bool $allowUnsigned, ?string $userId): array
    {
        $this->assertNoActiveRun();
        if (! $this->canWriteCode()) {
            throw new BusinessRule('O PHP não tem permissão para trocar os arquivos do sistema nesta hospedagem. Use a atualização manual pelo Gerenciador de Arquivos (docs/INSTALACAO-CPANEL.md).');
        }
        $zip = $this->packagePath($package);
        $info = $this->inspector->inspect($zip, (string) config('app.version'));
        if (! $info['signed'] && ! $allowUnsigned) {
            throw new BusinessRule('Pacote sem assinatura: marque a confirmação de que conferiu o código SHA-256.');
        }
        $id = strtolower((string) Str::ulid());
        $dir = $this->runDir($id);
        mkdir($dir, 0700, true);
        $state = ['id' => $id, 'package' => $package, 'sha256' => $info['sha256'], 'prefix' => $info['prefix'], 'from' => config('app.version'),
            'to' => $info['manifest']['version'], 'signed' => $info['signed'], 'status' => 'running', 'step' => 'validar', 'done' => [],
            'swapped' => [], 'log' => [], 'user_id' => $userId, 'started_at' => now()->toIso8601String()];
        $this->save($state);
        $this->audit->log('atualizacao.iniciar', null, ['de' => $state['from'], 'para' => $state['to'], 'sha256' => $info['sha256'], 'assinado' => $info['signed']], userId: $userId, channel: 'sistema');

        return $state;
    }

    // ------------------------------------------------------------------ execução de uma etapa por requisição

    public function runNext(string $id): array
    {
        $lock = fopen($this->root().'/update.lock', 'c');
        if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
            return $this->load($id) + ['busy' => true];
        }
        @set_time_limit(300);
        @ignore_user_abort(true);
        try {
            $s = $this->load($id);
            if ($s['status'] !== 'running') {
                return $s;
            }
            $step = $this->nextStep($s);
            try {
                $msg = match ($step) {
                    'validar' => $this->stepValidate($s),
                    'backup' => $this->stepBackup($s),
                    'extrair' => $this->stepExtract($s),
                    'conferir_arquivos' => $this->stepChecksums($s),
                    'manutencao' => $this->stepDown($s),
                    'trocar' => $this->stepSwap($s),
                    'migrar' => $this->stepMigrate($s),
                    'conferir' => $this->stepVerify($s),
                    'concluir' => $this->stepFinish($s),
                };
                $s['done'][] = $step;
                $s['log'][] = ['at' => date('H:i:s'), 'step' => $step, 'ok' => true, 'msg' => $msg];
                $s['step'] = $this->nextStep($s) ?? 'concluir';
                if ($step === 'concluir') {
                    $s['status'] = 'done';
                    $s['finished_at'] = now()->toIso8601String();
                }
                $this->save($s);
            } catch (Throwable $e) {
                $s['log'][] = ['at' => date('H:i:s'), 'step' => $step, 'ok' => false, 'msg' => $this->safeMessage($e)];
                $this->save($s);
                $s = $this->fail($s, $step, $e);
            }

            return $s;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function nextStep(array $s): ?string
    {
        foreach (array_keys(self::STEPS) as $step) {
            if (! in_array($step, $s['done'], true)) {
                return $step;
            }
        }

        return null;
    }

    private function stepValidate(array &$s): string
    {
        $zip = $this->packagePath($s['package']);
        if (! hash_equals($s['sha256'], hash_file('sha256', $zip))) {
            throw new BusinessRule('O pacote foi alterado depois da análise.');
        }
        $this->inspector->inspect($zip, (string) $s['from']);

        return 'Pacote '.$s['to'].' válido'.($s['signed'] ? ' e assinado.' : ' (sem assinatura, confirmado pelo proprietário).');
    }

    private function stepBackup(array &$s): string
    {
        $r = $this->backups->create();
        $s['db_backup'] = $r['file'];
        $s['tables_before'] = $this->tables();
        $s['triggers_before'] = $this->triggers();
        $v = $this->backups->verify($r['file']);   // backup verificado: restaurado num banco temporário e conferido
        if (! $v['ok']) {
            throw new BusinessRule('O backup feito antes da atualização não passou no teste de restauração: '.implode('; ', $v['problems']));
        }

        return 'Backup criptografado e testado (restauração conferida): '.basename($r['file']).' ('.number_format($r['bytes'] / 1024, 0, ',', '.').' KB).';
    }

    private function stepExtract(array &$s): string
    {
        $staging = $this->runDir($s['id']).'/novo';
        $this->rrmdir($staging);
        mkdir($staging, 0700, true);
        $zip = new ZipArchive;
        if ($zip->open($this->packagePath($s['package'])) !== true) {
            throw new BusinessRule('Não foi possível abrir o pacote.');
        }
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = (string) $zip->getNameIndex($i);
        }
        if (! $zip->extractTo($staging, $names)) {
            $zip->close();
            throw new BusinessRule('Falha ao extrair o pacote (espaço em disco ou permissão).');
        }
        $zip->close();
        $s['staging'] = rtrim($staging.'/'.$s['prefix'], '/');

        return count($names).' itens extraídos.';
    }

    private function stepChecksums(array &$s): string
    {
        $root = $s['staging'];
        $sums = (string) @file_get_contents($root.'/CHECKSUMS.sha256');
        $manifest = json_decode((string) @file_get_contents($root.'/wsf-update.json'), true) ?: [];
        if (! empty($manifest['checksums_sha256']) && ! hash_equals((string) $manifest['checksums_sha256'], hash('sha256', $sums))) {
            throw new BusinessRule('Lista de verificação (CHECKSUMS) não confere com o manifesto.');
        }
        $n = 0;
        foreach (preg_split('/\r?\n/', trim($sums)) as $line) {
            if (! preg_match('/^([a-f0-9]{64})\s+\*?(.+)$/', $line, $m)) {
                continue;
            }
            $file = $root.'/'.$m[2];
            if (str_contains($m[2], '..') || ! is_file($file) || ! hash_equals($m[1], hash_file('sha256', $file))) {
                throw new BusinessRule('Arquivo corrompido ou ausente no pacote: '.mb_substr($m[2], 0, 100));
            }
            $n++;
        }
        if ($n === 0) {
            throw new BusinessRule('Pacote sem lista de verificação.');
        }

        return $n.' arquivos conferidos.';
    }

    private function stepDown(array &$s): string
    {
        Artisan::call('down', ['--retry' => 60, '--refresh' => 30]);
        $s['maintenance'] = true;

        return 'Site em manutenção (somente esta tela de atualização continua acessível).';
    }

    /** Troca por rename (atômico por pasta). Usa só funções nativas: o código da aplicação muda no meio desta requisição. */
    private function stepSwap(array &$s): string
    {
        class_exists(JsonResponse::class);
        $base = base_path();
        $new = $s['staging'];
        $old = $this->runDir($s['id']).'/anterior';
        @mkdir($old, 0700, true);
        $items = array_merge(PackageInspector::DIRS, PackageInspector::FILES);
        $s['swapped'] = [];
        foreach ($items as $item) {
            if (! file_exists($new.'/'.$item)) {
                continue;
            }
            if (file_exists($base.'/'.$item)) {
                if (! @rename($base.'/'.$item, $old.'/'.$item)) {
                    throw new BusinessRule('Sem permissão para mover '.$item.'.');
                }
                $s['swapped'][] = ['item' => $item, 'had_old' => true, 'state' => 'old_moved'];
            } else {
                $s['swapped'][] = ['item' => $item, 'had_old' => false, 'state' => 'old_moved'];
            }
            $this->save($s);
            if (! @rename($new.'/'.$item, $base.'/'.$item)) {
                throw new BusinessRule('Sem permissão para instalar '.$item.'.');
            }
            $s['swapped'][count($s['swapped']) - 1]['state'] = 'new_in_place';
            $this->save($s);
        }
        // Itens próprios do site dentro de public/ (ex.: .well-known do SSL, .user.ini do cPanel) são mantidos.
        $kept = [];
        foreach (array_diff(scandir($old.'/public') ?: [], ['.', '..']) as $f) {
            if (! file_exists($base.'/public/'.$f)) {
                $this->rcopy($old.'/public/'.$f, $base.'/public/'.$f);
                $kept[] = $f;
            }
        }
        foreach (['bootstrap/cache/packages.php', 'bootstrap/cache/services.php', 'bootstrap/cache/config.php', 'bootstrap/cache/routes-v7.php', 'bootstrap/cache/events.php'] as $c) {
            @unlink($base.'/'.$c);
        }
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        return count($s['swapped']).' itens trocados'.($kept ? '; mantidos em public/: '.implode(', ', $kept) : '').'.';
    }

    private function stepMigrate(array &$s): string
    {
        $s['batch_before'] = (int) (DB::table('migrations')->max('batch') ?? 0);
        $this->save($s);
        Artisan::call('migrate', ['--force' => true]);
        $out = trim(Artisan::output());
        $s['batch_after'] = (int) (DB::table('migrations')->max('batch') ?? 0);
        $this->clearCaches();

        return $out !== '' ? mb_substr(preg_replace('/\s+/', ' ', $out), 0, 500) : 'Nenhuma alteração no banco.';
    }

    /** Caches compilados (views, rotas, config) da versão que sai: arquivos restaurados com data antiga não seriam recompilados. */
    private function clearCaches(): void
    {
        foreach (glob(storage_path('framework/views/*.php')) ?: [] as $f) {
            @unlink($f);
        }
        foreach (['config:clear', 'route:clear'] as $c) {
            try {
                Artisan::call($c);
            } catch (Throwable) {
            }
        }
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
    }

    private function stepVerify(array &$s): string
    {
        $version = trim((string) @file_get_contents(base_path('VERSION')));
        if ($version !== $s['to']) {
            throw new BusinessRule('Versão instalada ('.$version.') diferente da esperada ('.$s['to'].').');
        }
        DB::select('select 1');
        foreach (['users', 'accounts', 'companies', 'entries', 'subscriptions', 'audit_logs'] as $t) {
            DB::table($t)->limit(1)->get();
        }
        $pending = array_diff(array_keys(app('migrator')->getMigrationFiles([database_path('migrations')])), app('migrator')->getRepository()->getRan());
        if ($pending) {
            throw new BusinessRule('Ainda há migrações pendentes: '.implode(', ', $pending));
        }
        if (app('router')->getRoutes()->count() < 20) {
            throw new BusinessRule('Rotas do sistema não carregaram.');
        }

        return 'Banco, tabelas principais, migrações e rotas conferidos na versão '.$version.'.';
    }

    private function stepFinish(array &$s): string
    {
        Artisan::call('up');
        $s['maintenance'] = false;
        $this->rrmdir($this->runDir($s['id']).'/novo');
        $this->pruneOldRuns($s['id']);
        $this->audit->log('atualizacao.concluir', null, ['de' => $s['from'], 'para' => $s['to']], userId: $s['user_id'], channel: 'sistema');

        return 'Atualizado de '.$s['from'].' para '.$s['to'].'. A versão anterior foi guardada para reversão.';
    }

    // ------------------------------------------------------------------ falhas e reversão

    private function fail(array $s, string $step, Throwable $e): array
    {
        $before = array_search($step, array_keys(self::STEPS), true) < array_search('trocar', array_keys(self::STEPS), true);
        try {
            if ($step === 'trocar') {
                $this->undoSwap($s);
                $this->clearCaches();
            } elseif (! $before && $step !== 'concluir') {
                $this->rollbackMigrations($s);
                $this->undoSwap($s);
                $this->clearCaches();
                if (! empty($s['db_backup']) && $step === 'migrar') {
                    // migração interrompida: desfaz só o que ESTA execução criou (triggers e tabelas novas) e repõe,
                    // sem sobrescrever nem apagar nada, registros que existiam no backup e faltem agora
                    $this->dropNewTriggers($s);
                    $this->dropNewTables($s, true);
                    $this->backups->restore($s['db_backup']);
                    $s['log'][] = ['at' => date('H:i:s'), 'step' => 'reversao', 'ok' => true, 'msg' => 'Estruturas criadas pela migração interrompida removidas; nenhum registro apagado ou sobrescrito.'];
                }
            }
            if (! empty($s['maintenance']) || is_file(storage_path('framework/down'))) {
                Artisan::call('up');
                $s['maintenance'] = false;
            }
            $s['status'] = $before ? 'failed' : 'failed_rolled_back';
            $s['log'][] = ['at' => date('H:i:s'), 'step' => 'reversao', 'ok' => true, 'msg' => $before ? 'Nada foi alterado no sistema.' : 'Versão anterior restaurada automaticamente.'];
        } catch (Throwable $e2) {
            $s['status'] = 'failed_manual';
            $s['log'][] = ['at' => date('H:i:s'), 'step' => 'reversao', 'ok' => false, 'msg' => 'Reversão automática não concluída: '.$this->safeMessage($e2).' — siga a reversão manual em docs/INSTALACAO-CPANEL.md.'];
        }
        $this->rrmdir($this->runDir($s['id']).'/novo');
        $s['error'] = $this->safeMessage($e);
        $this->save($s);
        $this->audit->log('atualizacao.falhou', null, ['de' => $s['from'], 'para' => $s['to'], 'etapa' => $step, 'erro' => $s['error'], 'situacao' => $s['status']], userId: $s['user_id'], channel: 'sistema');

        return $s;
    }

    /** Reverte uma atualização concluída, SEM PERDA DE DADOS.
     *  modo "codigo": desfaz as migrações dela (as estruturas de proteção da auditoria são mantidas) e volta os arquivos;
     *  modo "banco": volta os arquivos e repõe, a partir do backup, registros que existiam e faltem — nunca apaga nem sobrescreve. */
    public function revert(string $id, string $mode, ?string $userId): array
    {
        $s = $this->load($id);
        $last = collect($this->history())->firstWhere('status', 'done');
        if ($s['status'] !== 'done' || ! $last || $last['id'] !== $id || trim((string) @file_get_contents(base_path('VERSION'))) !== $s['to']) {
            throw new BusinessRule('Só é possível reverter a última atualização concluída, com a versão dela ainda instalada.');
        }
        if (! is_dir($this->runDir($id).'/anterior')) {
            throw new BusinessRule('Os arquivos da versão anterior não estão mais guardados.');
        }
        if (AuditLogger::headAvailable() && (int) DB::table('audit_chain_head')->where('id', 1)->value('write_version') >= 2
            && version_compare((string) $s['from'], '1.1.1', '<')) {
            throw new BusinessRule('O formato v2 da auditoria já foi ativado: não é possível voltar para a versão '.$s['from'].', que não o suporta (antirrebaixamento). Corrija "para frente" com uma nova versão.');
        }
        @set_time_limit(300);
        Artisan::call('down', ['--retry' => 60]);
        try {
            if ($mode === 'banco') {
                if (empty($s['db_backup']) || ! is_file($s['db_backup'])) {
                    throw new BusinessRule('Backup do banco desta atualização não encontrado.');
                }
            } else {
                $this->rollbackMigrations($s);
            }
            // guarda a versão nova e devolve a anterior
            $this->undoSwap($s, true);
            $this->clearCaches();
            if ($mode === 'banco') {
                $this->dropNewTables($s, false);           // estruturas da auditoria nunca são removidas numa reversão
                $this->backups->restore($s['db_backup']);  // repõe ausentes; não apaga nem sobrescreve
            }
            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }
            $s['status'] = 'reverted';
            $s['reverted_at'] = now()->toIso8601String();
            $s['log'][] = ['at' => date('H:i:s'), 'step' => 'reverter', 'ok' => true, 'msg' => 'Revertido para '.$s['from'].($mode === 'banco' ? '; registros ausentes repostos do backup (nada apagado ou sobrescrito).' : ' mantendo os dados.')];
            $this->save($s);
        } finally {
            Artisan::call('up');
        }
        $this->audit->log('atualizacao.reverter', null, ['de' => $s['to'], 'para' => $s['from'], 'modo' => $mode], userId: $userId, channel: 'sistema');

        return $s;
    }

    /** @return list<string> */
    private function triggers(): array
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return [];
        }

        return DB::table('information_schema.TRIGGERS')->whereRaw('TRIGGER_SCHEMA = DATABASE()')->pluck('TRIGGER_NAME')->all();
    }

    /** Triggers criados pela migração da auditoria 1.1.1 (dependem de audit_chain_head). */
    public const AUDIT_TRIGGERS = ['audit_logs_no_update', 'audit_logs_no_delete', 'audit_logs_chain_guard', 'audit_logs_advance_head', 'audit_head_guard', 'audit_head_no_delete'];

    private function dropNewTriggers(array $s): void
    {
        $before = $s['triggers_before'] ?? null;
        if ($before === null) {
            // execução iniciada por uma versão que não registrava os triggers (1.1.0): se ESTA execução criou
            // audit_chain_head, os triggers da auditoria também são dela (e não podem ficar sem a tabela)
            $headIsNew = isset($s['tables_before']) && ! in_array('audit_chain_head', $s['tables_before'], true);
            $before = $headIsNew ? array_values(array_diff($this->triggers(), self::AUDIT_TRIGGERS)) : $this->triggers();
        }
        foreach (array_diff($this->triggers(), $before) as $t) {
            if (preg_match('/^[A-Za-z0-9_]+$/', $t)) {
                DB::unprepared('DROP TRIGGER IF EXISTS `'.$t.'`');
            }
        }
        AuditLogger::resetHeadCache();
    }

    /** @return list<string> */
    private function tables(): array
    {
        return array_values(array_map(fn ($t) => (string) $t['name'], Schema::getTables()));
    }

    /** DDL do MySQL não é transacional: remove tabelas criadas por uma migração que falhou no meio. */
    /** Tabelas cuja remoção apagaria a proteção da auditoria: só saem se ESTA execução as criou e falhou na migração. */
    private const PROTECTED_TABLES = ['audit_chain_head'];

    private function dropNewTables(array $s, bool $includeProtected = false): void
    {
        if (empty($s['tables_before'])) {
            return;
        }
        $new = array_diff($this->tables(), $s['tables_before'], $includeProtected ? [] : self::PROTECTED_TABLES);
        if (! $new) {
            return;
        }
        Schema::disableForeignKeyConstraints();
        try {
            foreach ($new as $t) {
                Schema::dropIfExists($t);
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    private function rollbackMigrations(array $s): void
    {
        if (! isset($s['batch_before'])) {
            return;
        }
        $count = DB::table('migrations')->where('batch', '>', (int) $s['batch_before'])->count();
        if ($count > 0) {
            Artisan::call('migrate:rollback', ['--force' => true, '--step' => $count]);
        }
    }

    /** Desfaz as trocas registradas. $keepNew: guarda a versão nova em "revertido/". */
    private function undoSwap(array &$s, bool $keepNew = false): void
    {
        $base = base_path();
        $old = $this->runDir($s['id']).'/anterior';
        $parking = $this->runDir($s['id']).($keepNew ? '/revertido' : '/descartado');
        @mkdir($parking, 0700, true);
        foreach (array_reverse($s['swapped'] ?? []) as $sw) {
            $item = $sw['item'];
            if ($sw['state'] === 'new_in_place' && file_exists($base.'/'.$item)) {
                @rename($base.'/'.$item, $parking.'/'.$item);
            }
            if ($sw['had_old'] && file_exists($old.'/'.$item) && ! file_exists($base.'/'.$item)) {
                if (! @rename($old.'/'.$item, $base.'/'.$item)) {
                    throw new BusinessRule('Não foi possível devolver '.$item.'.');
                }
            }
        }
        foreach (['packages.php', 'services.php', 'config.php', 'routes-v7.php', 'events.php'] as $c) {
            @unlink($base.'/bootstrap/cache/'.$c);
        }
        if (! $keepNew) {
            $this->rrmdir($parking);
        }
        $s['swapped'] = [];
    }

    // ------------------------------------------------------------------ estado e histórico

    public function load(string $id): array
    {
        $f = $this->runDir($id).'/estado.json';
        $s = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
        if (! is_array($s)) {
            throw new BusinessRule('Execução de atualização não encontrada.');
        }

        return $s;
    }

    public function save(array $s): void
    {
        $f = $this->runDir($s['id']).'/estado.json';
        file_put_contents($f.'.tmp', json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        @chmod($f.'.tmp', 0600);
        rename($f.'.tmp', $f);
    }

    /** @return list<array> mais recente primeiro */
    public function history(): array
    {
        $runs = [];
        foreach (glob($this->root().'/execucoes/*/estado.json') ?: [] as $f) {
            $s = json_decode((string) file_get_contents($f), true);
            if (is_array($s)) {
                $runs[] = $s;
            }
        }
        usort($runs, fn ($a, $b) => strcmp($b['id'], $a['id']));

        return $runs;
    }

    public function activeRun(): ?array
    {
        return collect($this->history())->first(fn ($s) => $s['status'] === 'running');
    }

    private function assertNoActiveRun(): void
    {
        if ($this->activeRun()) {
            throw new BusinessRule('Já existe uma atualização em andamento. Continue ou aguarde ela terminar.');
        }
    }

    /** Mantém só a versão anterior da última atualização (espaço em disco). */
    private function pruneOldRuns(string $keepId): void
    {
        foreach ($this->history() as $s) {
            if ($s['id'] !== $keepId) {
                foreach (['anterior', 'revertido', 'novo', 'descartado'] as $d) {
                    $this->rrmdir($this->runDir($s['id']).'/'.$d);
                }
            }
        }
    }

    private function safeMessage(Throwable $e): string
    {
        $m = $e instanceof BusinessRule ? $e->getMessage() : class_basename($e).': '.$e->getMessage();

        return mb_substr(str_replace(base_path(), '…', $m), 0, 400);
    }

    private function rcopy(string $from, string $to): void
    {
        if (is_dir($from)) {
            @mkdir($to, 0755, true);
            foreach (array_diff(scandir($from) ?: [], ['.', '..']) as $f) {
                $this->rcopy($from.'/'.$f, $to.'/'.$f);
            }
        } else {
            @copy($from, $to);
        }
    }

    public function rrmdir(string $dir): void
    {
        if (! is_dir($dir) || is_link($dir)) {
            if (is_link($dir) || is_file($dir)) {
                @unlink($dir);
            }

            return;
        }
        if (! str_starts_with(realpath($dir) ?: '', realpath($this->root()) ?: '//')) {
            return; // nunca apaga fora de storage/app/updates
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() && ! $f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
