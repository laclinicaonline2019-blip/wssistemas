<?php

namespace App\Core\Audit;

use App\Core\Tenancy\TenantContext;
use App\Modules\Platform\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Registro da trilha de auditoria (append-only).
 *
 * Grava diretamente via query builder para não depender de eventos de model
 * e funcionar também em contexto de plataforma (company_id nulo).
 */
class AuditLogger
{
    public const REDACTED = '[REDACTED]';

    /** Campos que nunca podem ir para a trilha em texto claro. */
    private const SENSITIVE_KEYS = [
        'password', 'password_confirmation', 'current_password', 'remember_token',
        'two_factor_secret', 'two_factor_recovery_codes', 'token', 'secret', 'api_key',
        'code', 'recovery_code',
    ];

    /**
     * Nível de transação "externo" a ignorar (os testes envolvem cada caso em
     * uma transação que não pertence à aplicação).
     */
    public static int $baseTransactionLevel = 0;

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditChain $chain = new AuditChain,
    ) {}

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        string $action,
        ?Model $subject = null,
        array $old = [],
        array $new = [],
        string $result = 'success',
        array $metadata = [],
        ?string $companyId = null,
        ?string $userId = null,
        string $actorType = 'user',
    ): void {
        $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();

        $companyId ??= match (true) {
            $subject instanceof Company => $subject->getKey(),
            $subject !== null && array_key_exists('company_id', $subject->getAttributes()) => $subject->getAttributes()['company_id'],
            default => null,
        } ?? $this->context->companyIdOrNull();
        // Portal do paciente: o ator é a conta do paciente — nunca um usuário da clínica que
        // por acaso esteja logado no mesmo navegador.
        if ($userId === null && $actorType === 'user' && ($portalAccount = $request?->attributes->get('portal_account_id'))) {
            $actorType = 'patient';
            $metadata['patient_account_id'] = $portalAccount;
        } else {
            $userId ??= Auth::id();
        }

        if ($userId === null && $actorType === 'user') {
            $actorType = app()->runningInConsole() && ! app()->runningUnitTests() ? 'system' : 'anonymous';
        }

        $row = [
            'company_id' => $companyId,
            'branch_id' => $this->context->branchId(),
            'user_id' => $userId,
            'actor_type' => $actorType,
            'action' => $action,
            'auditable_type' => $subject ? $this->subjectType($subject) : null,
            'auditable_id' => $subject?->getKey() !== null ? (string) $subject->getKey() : null,
            'old_values' => $old ? json_encode($this->redact($old), JSON_UNESCAPED_UNICODE) : null,
            'new_values' => $new ? json_encode($this->redact($new), JSON_UNESCAPED_UNICODE) : null,
            'result' => $result,
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? Str::limit((string) $request->userAgent(), 500, '') : null,
            'request_id' => $request?->attributes->get('request_id'),
            'metadata' => $metadata ? json_encode($this->redact($metadata), JSON_UNESCAPED_UNICODE) : null,
            'created_at' => now()->format('Y-m-d H:i:s'),
        ];

        $insert = fn () => $this->chain->append($row);

        // Eventos de negação/falha costumam ocorrer dentro de transações que serão
        // desfeitas pela própria exceção. Eles precisam sobreviver ao rollback.
        if ($result !== 'success' && DB::transactionLevel() > self::$baseTransactionLevel) {
            DB::afterRollBack($insert);
        }

        $insert();
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function redact(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::SENSITIVE_KEYS, true)) {
                $values[$key] = self::REDACTED;
            } elseif (is_array($value)) {
                $values[$key] = $this->redact($value);
            }
        }

        return $values;
    }

    private function subjectType(Model $subject): string
    {
        return method_exists($subject, 'auditType') ? $subject->auditType() : class_basename($subject);
    }
}
