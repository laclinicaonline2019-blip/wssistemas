<?php

namespace App\Core\Audit;

use App\Models\AuditLog;
use Illuminate\Support\Facades\DB;

/**
 * Verificação da cadeia de auditoria, em lotes retomáveis e com limites de processamento.
 *
 * Status por registro (separados):
 *   integro_v2 · integro_v1 · integro_v1_compat (com o método registrado)
 *   ligacao_quebrada · bifurcacao · hmac_invalido · rebaixamento · chave_diferente · nao_verificavel
 * Status da cadeia: topo_divergente · exclusao_do_final (âncora externa) — além dos acima.
 */
class ChainVerifier
{
    public const OK_STATUSES = ['integro_v2', 'integro_v1', 'integro_v1_compat'];

    public const LABELS = [
        'integro_v2' => 'íntegro (v2)', 'integro_v1' => 'íntegro (v1)', 'integro_v1_compat' => 'íntegro (v1, por compatibilidade)',
        'ligacao_quebrada' => 'ligação quebrada', 'bifurcacao' => 'bifurcação da cadeia', 'hmac_invalido' => 'HMAC inválido (conteúdo alterado)',
        'rebaixamento' => 'rebaixamento v2→v1', 'chave_diferente' => 'chave diferente da histórica', 'nao_verificavel' => 'não verificável (limite atingido)',
        'topo_divergente' => 'topo da cadeia divergente', 'exclusao_do_final' => 'exclusão do final da cadeia (âncora)',
    ];

    public function __construct(private readonly AuditAnchor $anchors) {}

    public function dir(): string
    {
        $d = storage_path('app/auditoria');
        if (! is_dir($d)) {
            @mkdir($d, 0700, true);
        }

        return $d;
    }

    /**
     * Processa lotes até terminar ou atingir o limite de tempo/tentativas. Com $persist, retoma de onde parou.
     *
     * @return array{ok: bool, done: bool, checked: int, total: int, summary: array<string,int>, first_problem_id: ?int, problems: list<array>, compat: list<array>, report: ?string}
     */
    public function run(?float $timeLimit = null, ?int $batch = null, bool $persist = true, bool $logEvent = false, ?string $userId = null): array
    {
        $timeLimit ??= (float) config('wsf.audit.verify_time_limit', 60);
        $batch ??= (int) config('wsf.audit.verify_batch', 500);
        $maxPerRecord = (int) config('wsf.audit.max_attempts_per_record', 50000);
        $maxPerRun = (int) config('wsf.audit.max_attempts_per_run', 2000000);
        $started = microtime(true);

        $state = $persist ? $this->loadState() : null;
        $state ??= ['cursor' => 0, 'prev_hash' => null, 'first' => true, 'seen_v2' => false, 'checked' => 0, 'summary' => [], 'problems' => [], 'compat' => [], 'attempts' => 0, 'started_at' => now()->toIso8601String()];

        $head = AuditLogger::headAvailable() ? DB::table('audit_chain_head')->where('id', 1)->first() : null;
        $v2From = $head?->activated_v2_from_id ? (int) $head->activated_v2_from_id : null;
        $currentKeyId = (string) config('wsf.security.audit_hmac_key') !== '' ? AuditLogger::keyId() : null;
        $legacy = new LegacyVerifier($maxPerRecord);
        $total = AuditLog::query()->count();
        $done = false;

        while (true) {
            $rows = AuditLog::query()->where('id', '>', $state['cursor'])->orderBy('id')->limit($batch)->get();
            if ($rows->isEmpty()) {
                $done = true;
                break;
            }
            foreach ($rows as $log) {
                $status = $this->check($log, $state, $legacy, $v2From, $head, $currentKeyId, $maxPerRun);
                $state['summary'][$status['status']] = ($state['summary'][$status['status']] ?? 0) + 1;
                if ($status['status'] === 'integro_v1_compat') {
                    $state['compat'][] = ['id' => (int) $log->id, 'action' => $log->action, 'method' => $status['method'], 'attempts' => $status['attempts']];
                } elseif (! in_array($status['status'], self::OK_STATUSES, true)) {
                    $state['problems'][] = ['id' => (int) $log->id, 'action' => $log->action, 'status' => $status['status'], 'detail' => $status['detail'] ?? null];
                }
                $state['prev_hash'] = $log->hash;
                $state['first'] = false;
                $state['cursor'] = (int) $log->id;
                $state['checked']++;
            }
            if ((microtime(true) - $started) >= $timeLimit) {
                break;
            }
        }

        $report = null;
        if ($done) {
            foreach ($this->chainLevelChecks($head, $state) as $p) {
                $state['problems'][] = $p;
                $state['summary'][$p['status']] = ($state['summary'][$p['status']] ?? 0) + 1;
            }
            $report = $persist ? $this->writeReport($state, $total) : null;
            if ($persist) {
                @unlink($this->dir().'/verificacao.json');
            }
        } elseif ($persist) {
            $this->saveState($state);
        }

        $problems = $state['problems'];
        $ok = $done && $problems === [];
        if ($done && $logEvent) {
            app(AuditLogger::class)->log('auditoria.verificacao', null, ['registros' => $state['checked'], 'resultado' => $ok ? 'integra' : 'problemas',
                'resumo' => $state['summary'], 'relatorio' => $report ? basename($report) : null], userId: $userId, channel: app()->runningInConsole() ? 'sistema' : 'web');
        }

        return ['ok' => $ok, 'done' => $done, 'checked' => $state['checked'], 'total' => $total, 'summary' => $state['summary'],
            'first_problem_id' => $problems[0]['id'] ?? null, 'problems' => $problems, 'compat' => $state['compat'], 'report' => $report];
    }

    /** @return array{status: string, method?: string, attempts?: int, detail?: string} */
    private function check(AuditLog $log, array &$state, LegacyVerifier $legacy, ?int $v2From, ?object $head, ?string $currentKeyId, int $maxPerRun): array
    {
        // 1) ligação
        $expected = $state['first'] ? null : $state['prev_hash'];
        $linkOk = $expected === null ? $log->prev_hash === null : ($log->prev_hash !== null && hash_equals($expected, (string) $log->prev_hash));
        if (! $linkOk) {
            $forkOf = $log->prev_hash ? AuditLog::query()->where('hash', $log->prev_hash)->where('id', '<', $log->id)->value('id') : null;

            return ['status' => $forkOf ? 'bifurcacao' : 'ligacao_quebrada', 'detail' => $forkOf ? 'aponta para o registro #'.$forkOf : null];
        }

        $version = $log->hash_version === null ? 1 : (int) $log->hash_version;
        if ($version >= 2) {
            $state['seen_v2'] = true;
        }

        // 2) rebaixamento
        if ($version < 2 && ($state['seen_v2'] || ($v2From !== null && $log->id >= $v2From))) {
            return ['status' => 'rebaixamento'];
        }

        // 3) HMAC
        if ($version === 2) {
            if ($currentKeyId === null || ($head?->key_id && ! hash_equals((string) $head->key_id, $currentKeyId))) {
                return ['status' => 'chave_diferente'];
            }
            if (! is_string($log->created_at_utc) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/', $log->created_at_utc)) {
                return ['status' => 'hmac_invalido', 'detail' => 'created_at_utc ausente ou fora do formato'];
            }
            $raw = $log->getRawOriginal('data');
            try {
                $tree = $raw === null ? null : json_decode((string) $raw, false, 512, JSON_THROW_ON_ERROR);
                $row = $log->getAttributes();
                $ok = hash_equals((string) $log->hash, AuditLogger::hashV2($row, $tree, (string) $currentKeyId));
            } catch (\Throwable) {
                $ok = false;
            }

            return ['status' => $ok ? 'integro_v2' : 'hmac_invalido'];
        }
        if ($version !== 1) {
            return ['status' => 'hmac_invalido', 'detail' => 'versão desconhecida'];
        }

        $row = $log->getAttributes();
        $row['data'] = $log->data;
        $row['created_at'] = $log->created_at?->format('Y-m-d H:i:s');
        if ($state['attempts'] >= $maxPerRun) {
            return ['status' => 'nao_verificavel', 'detail' => 'limite de tentativas da execução atingido'];
        }
        $r = $legacy->verify($row, (string) $log->hash);
        $state['attempts'] += $r['attempts'];
        if ($r['ok']) {
            return $r['method'] === 'ordem lida' ? ['status' => 'integro_v1'] : ['status' => 'integro_v1_compat', 'method' => $r['method'], 'attempts' => $r['attempts']];
        }

        return $r['exhausted'] ? ['status' => 'nao_verificavel', 'detail' => $r['attempts'].' tentativas'] : ['status' => 'hmac_invalido'];
    }

    /** Topo da cadeia e âncora externa (exclusão do final). */
    private function chainLevelChecks(?object $head, array $state): array
    {
        $problems = [];
        $last = AuditLog::query()->orderByDesc('id')->first(['id', 'hash']);
        if ($head && $last && ((int) $head->last_id !== (int) $last->id || ! hash_equals((string) $head->last_hash, (string) $last->hash))) {
            $problems[] = ['id' => (int) $last->id, 'action' => '—', 'status' => 'topo_divergente', 'detail' => 'topo registrado #'.$head->last_id];
        }
        $anchor = $this->anchors->latestValid();
        if ($anchor) {
            $at = AuditLog::query()->where('id', $anchor['last_id'])->value('hash');
            if ($at === null || ! hash_equals((string) $anchor['last_hash'], (string) $at) || AuditLog::query()->count() < (int) $anchor['total']) {
                $problems[] = ['id' => (int) $anchor['last_id'], 'action' => '—', 'status' => 'exclusao_do_final', 'detail' => 'âncora de '.$anchor['gerada_em']];
            }
        }

        return $problems;
    }

    private function writeReport(array $state, int $total): string
    {
        $report = ['sistema' => 'WS Finanças em Dia', 'versao' => config('app.version'), 'gerado_em' => now()->toIso8601String(), 'iniciado_em' => $state['started_at'],
            'registros' => $state['checked'], 'total_na_tabela' => $total, 'resumo' => $state['summary'], 'compatibilidade' => $state['compat'], 'problemas' => $state['problems'],
            'tentativas' => $state['attempts']];
        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $report['assinatura'] = hash_hmac('sha256', $json, AuditLogger::legacyKey());
        $file = $this->dir().'/relatorio-'.now()->format('Ymd-His').'.json';
        file_put_contents($file, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        @chmod($file, 0600);

        return $file;
    }

    public function latestReport(): ?array
    {
        $files = glob($this->dir().'/relatorio-*.json') ?: [];
        rsort($files);
        $r = $files ? json_decode((string) file_get_contents($files[0]), true) : null;

        return is_array($r) ? $r + ['arquivo' => basename($files[0])] : null;
    }

    public function pendingState(): ?array
    {
        return $this->loadState();
    }

    private function loadState(): ?array
    {
        $f = $this->dir().'/verificacao.json';
        $s = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;

        return is_array($s) ? $s : null;
    }

    private function saveState(array $s): void
    {
        $f = $this->dir().'/verificacao.json';
        file_put_contents($f, json_encode($s));
        @chmod($f, 0600);
    }
}
