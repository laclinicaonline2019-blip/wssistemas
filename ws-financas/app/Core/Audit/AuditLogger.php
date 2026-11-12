<?php

namespace App\Core\Audit;

use App\Core\Tenancy\TenantContext;
use App\Models\AuditLog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Trilha de auditoria append-only com cadeia HMAC: cada registro guarda o hash do anterior.
 * `wsf:audit:verify` recalcula a cadeia e acusa qualquer alteração/remoção feita direto no banco.
 */
class AuditLogger
{
    private const REDACT = ['password', 'password_confirmation', 'two_factor_secret', 'token', 'access_token', 'api_key', 'document', 'phone', 'code'];

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

        Cache::lock('audit-chain', 10)->block(10, function () use ($row) {
            DB::transaction(function () use ($row) {
                $prev = AuditLog::query()->orderByDesc('id')->value('hash');
                $row['prev_hash'] = $prev;
                $row['hash'] = self::hashOf($row);
                AuditLog::query()->create($row);
            });
        });
    }

    public static function hashOf(array $row): string
    {
        $payload = json_encode([
            $row['prev_hash'] ?? null, $row['account_id'] ?? null, $row['user_id'] ?? null, $row['action'], $row['subject_type'] ?? null,
            $row['subject_id'] ?? null, $row['data'] ?? null, $row['ip'] ?? null, $row['channel'] ?? 'web', (string) $row['created_at'],
        ], JSON_UNESCAPED_UNICODE);

        return hash_hmac('sha256', $payload, (string) (config('wsf.security.audit_hmac_key') ?: config('app.key')));
    }

    /** @return array{ok: bool, checked: int, broken_at: ?int} */
    public static function verify(): array
    {
        $prev = null;
        $n = 0;
        foreach (AuditLog::query()->orderBy('id')->cursor() as $log) {
            $n++;
            $row = $log->getAttributes();
            $row['data'] = $log->data;
            $row['created_at'] = $log->created_at->format('Y-m-d H:i:s');
            if ($log->prev_hash !== $prev || ! hash_equals($log->hash, self::hashOf($row))) {
                return ['ok' => false, 'checked' => $n, 'broken_at' => (int) $log->id];
            }
            $prev = $log->hash;
        }

        return ['ok' => true, 'checked' => $n, 'broken_at' => null];
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
