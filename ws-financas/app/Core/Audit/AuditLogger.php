<?php

namespace App\Core\Audit;

use App\Core\Support\BusinessRule;
use App\Core\Tenancy\TenantContext;
use App\Models\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Trilha de auditoria append-only com cadeia HMAC.
 *
 * Gravação (1.1.1):
 *  - serializada NO BANCO: SELECT … FOR UPDATE na linha única audit_chain_head, mantido até o COMMIT da transação
 *    (inclusive quando a auditoria está dentro de uma transação de negócio). Sem Cache::lock, sem expiração;
 *  - o formato vem do banco (audit_chain_head.write_version), lido sob o bloqueio: depois de ativado o v2, nenhum
 *    processo (web, WhatsApp, fila, cron) grava v1 — e os triggers do MySQL recusam se alguém tentar;
 *  - v1 = formato histórico (inalterado); v2 = HMAC sobre JSON canônico RFC 8785 com versão, chave e data UTC.
 *
 * Verificação: ChainVerifier (status separados, compatibilidade v1 limitada, lotes, âncoras).
 */
class AuditLogger
{
    public const V2_DOMAIN = "wsf-audit-v2\n";

    private const REDACT = ['password', 'password_confirmation', 'two_factor_secret', 'token', 'access_token', 'api_key', 'document', 'phone', 'code'];

    private static ?bool $hasHead = null;

    public function __construct(private readonly TenantContext $ctx) {}

    public function log(string $action, ?object $subject = null, array $data = [], ?string $accountId = null, ?string $userId = null, string $channel = 'web'): void
    {
        $row = [
            'account_id' => $accountId ?? $this->ctx->accountIdOrNull(),
            'user_id' => $userId ?? $this->ctx->userId() ?? auth()->id(),
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey() !== null ? (string) $subject->getKey() : null,
            'data' => $this->redact($data) ?: null,
            'ip' => app()->runningInConsole() ? null : request()->ip(),
            'channel' => $channel,
            'created_at' => now()->format('Y-m-d H:i:s'),
        ];

        if (! self::headAvailable()) {
            $this->legacyLog($row);   // banco ainda sem a migração 1.1.1 (instante entre a troca de arquivos e a migração)

            return;
        }

        DB::transaction(function () use ($row) {
            // Bloqueio único no topo. (Bloquear antes o último registro, como a 1.1.0 faz, causa deadlock entre processos
            // novos: o gap lock da leitura descendente é compartilhável — medido no Percona 5.7, ver docs/AUDITORIA.md.)
            $head = DB::table('audit_chain_head')->where('id', 1)->lockForUpdate()->first();
            if (! $head) {
                throw new BusinessRule('Auditoria: topo da cadeia ausente (audit_chain_head). Gravação recusada.');
            }
            $row['prev_hash'] = (int) $head->last_id === 0 ? null : $head->last_hash;

            if ((int) $head->write_version >= 2) {
                $keyId = self::keyId();
                if ($head->key_id !== null && ! hash_equals((string) $head->key_id, $keyId)) {
                    throw new BusinessRule('Auditoria: a chave AUDIT_HMAC_KEY não corresponde à chave histórica. Gravação recusada (procedimento de chave em docs/AUDITORIA.md).');
                }
                $tree = Jcs::prepare($row['data']);                 // exatamente o que será gravado e relido
                $row['created_at_utc'] = CarbonImmutable::now('UTC')->format('Y-m-d\\TH:i:s.u\\Z');
                $row['hash_version'] = 2;
                $row['hash'] = self::hashV2($row, $tree, $keyId);
                $row['data'] = $tree === null ? null : Jcs::toStorableJson($tree);
            } else {
                $row['hash'] = self::hashOf($row);                  // v1: formato histórico, inalterado
                $row['data'] = $row['data'] === null ? null : json_encode($row['data']);   // mesma codificação do cast "array" da 1.1.0
            }

            $id = DB::table('audit_logs')->insertGetId($row);
            DB::table('audit_chain_head')->where('id', 1)->update(['last_id' => $id, 'last_hash' => $row['hash'], 'updated_at' => now()]);
        });
    }

    /** Ativa o formato v2 (irreversível — garantido também por trigger). */
    public function activateV2(?string $userId): void
    {
        if ((string) config('wsf.security.audit_hmac_key') === '') {
            throw new BusinessRule('Defina AUDIT_HMAC_KEY no .env antes de ativar o formato v2 (o v2 não usa a APP_KEY como alternativa).');
        }
        DB::transaction(function () {
            $head = DB::table('audit_chain_head')->where('id', 1)->lockForUpdate()->first();
            if ((int) $head->write_version >= 2) {
                throw new BusinessRule('O formato v2 já está ativo.');
            }
            DB::table('audit_chain_head')->where('id', 1)->update([
                'write_version' => 2, 'key_id' => self::keyId(), 'activated_v2_at' => CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s.u\Z'),
                'activated_v2_from_id' => (int) $head->last_id + 1, 'updated_at' => now(),
            ]);
        });
        $this->log('auditoria.ativar_v2', null, ['formato' => 2, 'chave' => self::keyId()], userId: $userId, channel: app()->runningInConsole() ? 'sistema' : 'web');
    }

    // ------------------------------------------------------------------ formatos

    /** v1 — formato histórico (1.0.0/1.1.0), mantido byte a byte para os registros antigos. */
    public static function hashOf(array $row): string
    {
        $payload = json_encode([
            $row['prev_hash'] ?? null, $row['account_id'] ?? null, $row['user_id'] ?? null, $row['action'], $row['subject_type'] ?? null,
            $row['subject_id'] ?? null, $row['data'] ?? null, $row['ip'] ?? null, $row['channel'] ?? 'web', (string) $row['created_at'],
        ], JSON_UNESCAPED_UNICODE);

        return hash_hmac('sha256', $payload, self::legacyKey());
    }

    /** v2 — HMAC-SHA256( "wsf-audit-v2\n" + JCS(payload) ), com versão, chave e data UTC dentro do que é assinado. */
    public static function hashV2(array $row, mixed $dataTree, string $keyId): string
    {
        return hash_hmac('sha256', self::V2_DOMAIN.Jcs::encode(self::v2Payload($row, $dataTree, $keyId)), self::v2Key());
    }

    public static function v2Payload(array $row, mixed $dataTree, string $keyId): \stdClass
    {
        $p = new \stdClass;
        $p->v = 2;
        $p->key_id = $keyId;
        $p->prev_hash = $row['prev_hash'] ?? null;
        $p->account_id = $row['account_id'] ?? null;
        $p->user_id = $row['user_id'] ?? null;
        $p->action = (string) $row['action'];
        $p->subject_type = $row['subject_type'] ?? null;
        $p->subject_id = $row['subject_id'] ?? null;
        $p->data = $dataTree;
        $p->ip = $row['ip'] ?? null;
        $p->channel = (string) ($row['channel'] ?? 'web');
        $p->created_at_utc = (string) $row['created_at_utc'];

        return $p;
    }

    /** Impressão da chave v2 (identifica a chave sem revelá-la). */
    public static function keyId(): string
    {
        return substr(hash_hmac('sha256', 'wsf-audit-key-id', self::v2Key()), 0, 16);
    }

    public static function legacyKey(): string
    {
        return (string) (config('wsf.security.audit_hmac_key') ?: config('app.key'));
    }

    public static function v2Key(): string
    {
        $k = (string) config('wsf.security.audit_hmac_key');
        if ($k === '') {
            throw new BusinessRule('AUDIT_HMAC_KEY ausente: o formato v2 exige chave própria.');
        }

        return $k;
    }

    /** Verificação completa (compatibilidade com a 1.1.0): ok/checked/broken_at. Detalhes em ChainVerifier. */
    public static function verify(): array
    {
        $r = app(ChainVerifier::class)->run(null, null, false);

        return ['ok' => $r['ok'], 'checked' => $r['checked'], 'broken_at' => $r['first_problem_id'], 'summary' => $r['summary']];
    }

    public static function headAvailable(): bool
    {
        return self::$hasHead ??= Schema::hasTable('audit_chain_head');
    }

    public static function resetHeadCache(): void
    {
        self::$hasHead = null;
    }

    // ------------------------------------------------------------------ apoio

    private function legacyLog(array $row): void
    {
        DB::transaction(function () use ($row) {
            $row['prev_hash'] = AuditLog::query()->orderByDesc('id')->lockForUpdate()->value('hash');
            $row['hash'] = self::hashOf($row);
            AuditLog::query()->create($row);
        });
    }

    private function redact(array $data): array
    {
        foreach ($data as $k => $v) {
            if (in_array((string) $k, self::REDACT, true)) {
                $data[$k] = '[protegido]';
            } elseif (is_array($v)) {
                $data[$k] = $this->redact($v);
            }
        }

        return $data;
    }
}
