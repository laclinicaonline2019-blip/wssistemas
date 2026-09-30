<?php

namespace App\Modules\Identity\Services;

use App\Core\Access\PermissionService;
use App\Core\Audit\AuditLogger;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Regras anti-escalonamento de privilégio compartilhadas pela gestão de
 * usuários e perfis.
 */
class AccessGuard
{
    public const COMPANY_WIDE = '__company_wide__';

    public function __construct(
        private readonly PermissionService $permissions,
        private readonly AuditLogger $audit,
    ) {}

    public function hasCompanyWide(User $actor, string $permission): bool
    {
        return $this->permissions->userHas($actor, $permission, self::COMPANY_WIDE);
    }

    /** @return list<string> permissões concedidas ao ator na filial (ou empresa toda se null) */
    public function actorPermissionsAt(User $actor, ?string $branchId): array
    {
        return $this->permissions->effective($actor, $branchId ?? self::COMPANY_WIDE);
    }

    /**
     * O ator só pode gerenciar um usuário se TODOS os vínculos do alvo
     * estiverem dentro das filiais que o ator acessa (mesma regra do
     * escopo User::manageableBy).
     */
    public function canManageUser(User $actor, User $target): bool
    {
        if ($actor->company_id !== $target->company_id) {
            return false;
        }

        $allowed = $actor->allowedBranchIds();

        if ($allowed === null) {
            return true;
        }

        $targetBranches = $target->roleAssignments()->pluck('branch_id')->all();

        if ($targetBranches === []) {
            return false; // sem vínculos: somente gestores da empresa toda
        }

        foreach ($targetBranches as $branchId) {
            if ($branchId === null || ! in_array($branchId, $allowed, true)) {
                return false;
            }
        }

        return true;
    }

    public function ensureCanManageUser(User $actor, User $target): void
    {
        if (! $this->canManageUser($actor, $target)) {
            $this->deny('user_outside_scope', ['target_user_id' => $target->id]);
        }
    }

    /**
     * @param  list<string>  $permissionKeys
     */
    public function ensureSubsetOfActor(User $actor, array $permissionKeys, ?string $branchId): void
    {
        $missing = array_diff($permissionKeys, $this->actorPermissionsAt($actor, $branchId));

        if ($missing !== []) {
            $this->deny('privilege_escalation', ['missing' => array_values($missing), 'branch_id' => $branchId]);
        }
    }

    /** @param  array<string, mixed>  $metadata */
    public function deny(string $reason, array $metadata = []): never
    {
        $this->audit->record('access.denied', result: 'denied', metadata: ['reason' => $reason, ...$metadata]);

        throw new AuthorizationException(match ($reason) {
            'privilege_escalation' => 'Você não pode conceder permissões que não possui.',
            'user_outside_scope' => 'Usuário fora do seu escopo de gestão.',
            'self_change' => 'Você não pode alterar seus próprios perfis ou bloquear a si mesmo.',
            default => 'Ação não permitida.',
        });
    }
}
