<?php

namespace App\Core\Tenancy;

use Closure;

/**
 * Contexto do cliente (tenant) da requisição/rotina atual.
 *
 * Montado SEMPRE no servidor a partir do usuário autenticado e do vínculo (membership) — nunca a partir de
 * dados enviados pelo navegador. Models com BelongsToAccount falham fechado sem contexto.
 */
final class TenantContext
{
    private ?string $accountId = null;

    /** @var list<string>|null null = todas as empresas do cliente */
    private ?array $companyIds = null;

    private ?string $role = null;

    private ?string $userId = null;

    private bool $system = false;

    /** @param list<string>|null $companyIds */
    public function set(string $accountId, ?array $companyIds = null, ?string $role = null, ?string $userId = null): void
    {
        $this->accountId = $accountId;
        $this->companyIds = $companyIds === null ? null : array_values($companyIds);
        $this->role = $role;
        $this->userId = $userId;
        $this->system = false;
    }

    public function clear(): void
    {
        $this->accountId = $this->role = $this->userId = null;
        $this->companyIds = null;
        $this->system = false;
    }

    public function hasAccount(): bool
    {
        return $this->accountId !== null;
    }

    public function accountId(): string
    {
        return $this->accountId ?? throw new TenantContextMissing;
    }

    public function accountIdOrNull(): ?string
    {
        return $this->accountId;
    }

    /** @return list<string>|null */
    public function companyIds(): ?array
    {
        return $this->companyIds;
    }

    public function canAccessCompany(string $companyId): bool
    {
        return $this->companyIds === null || in_array($companyId, $this->companyIds, true);
    }

    public function role(): ?string
    {
        return $this->role;
    }

    public function userId(): ?string
    {
        return $this->userId;
    }

    public function isSystem(): bool
    {
        return $this->system;
    }

    /** Executa dentro do contexto de um cliente (webhooks, rotinas), restaurando o anterior. */
    public function runFor(string $accountId, Closure $callback, ?array $companyIds = null, ?string $role = 'system', ?string $userId = null): mixed
    {
        $prev = [$this->accountId, $this->companyIds, $this->role, $this->userId, $this->system];
        $this->set($accountId, $companyIds, $role, $userId);

        try {
            return $callback();
        } finally {
            [$this->accountId, $this->companyIds, $this->role, $this->userId, $this->system] = $prev;
        }
    }

    /** Sem filtro de cliente: uso restrito ao painel do proprietário, instalador e rotinas globais. */
    public function runAsSystem(Closure $callback): mixed
    {
        $prev = [$this->accountId, $this->companyIds, $this->role, $this->userId, $this->system];
        $this->clear();
        $this->system = true;

        try {
            return $callback();
        } finally {
            [$this->accountId, $this->companyIds, $this->role, $this->userId, $this->system] = $prev;
        }
    }
}
