<?php

namespace App\Core\Tenancy;

use App\Core\Tenancy\Exceptions\TenantContextMissing;
use Closure;

/**
 * Contexto de tenant da requisição/job atual.
 *
 * Preenchido pelo backend (middleware ResolveTenant, jobs, comandos) a partir
 * do usuário autenticado — nunca a partir de dados enviados pelo frontend.
 * Os models com BelongsToCompany falham de forma fechada (exceção) quando
 * consultados sem contexto, evitando vazamento acidental entre clínicas.
 */
final class TenantContext
{
    private ?string $companyId = null;

    private ?string $branchId = null;

    /** @var list<string>|null null = todas as filiais da empresa */
    private ?array $allowedBranchIds = null;

    private bool $system = false;

    public function set(string $companyId, ?string $branchId = null, ?array $allowedBranchIds = null): void
    {
        $this->companyId = $companyId;
        $this->branchId = $branchId;
        $this->allowedBranchIds = $allowedBranchIds;
    }

    public function setBranch(?string $branchId): void
    {
        $this->branchId = $branchId;
    }

    public function clear(): void
    {
        $this->companyId = null;
        $this->branchId = null;
        $this->allowedBranchIds = null;
        $this->system = false;
    }

    public function hasCompany(): bool
    {
        return $this->companyId !== null;
    }

    public function companyId(): string
    {
        if ($this->companyId === null) {
            throw new TenantContextMissing;
        }

        return $this->companyId;
    }

    public function companyIdOrNull(): ?string
    {
        return $this->companyId;
    }

    public function branchId(): ?string
    {
        return $this->branchId;
    }

    /** @return list<string>|null */
    public function allowedBranchIds(): ?array
    {
        return $this->allowedBranchIds;
    }

    public function isSystem(): bool
    {
        return $this->system;
    }

    /**
     * Executa o callback dentro do contexto de uma empresa (jobs, comandos,
     * webhooks), restaurando o contexto anterior ao final.
     */
    public function runFor(string $companyId, Closure $callback, ?string $branchId = null): mixed
    {
        $previous = [$this->companyId, $this->branchId, $this->allowedBranchIds, $this->system];
        $this->set($companyId, $branchId);
        $this->system = false;

        try {
            return $callback();
        } finally {
            [$this->companyId, $this->branchId, $this->allowedBranchIds, $this->system] = $previous;
        }
    }

    /**
     * Executa sem escopo de tenant. Uso restrito a rotinas de plataforma
     * (Super Admin, instalador, seeders, tarefas agendadas globais).
     */
    public function runAsSystem(Closure $callback): mixed
    {
        $previous = [$this->companyId, $this->branchId, $this->allowedBranchIds, $this->system];
        $this->clear();
        $this->system = true;

        try {
            return $callback();
        } finally {
            [$this->companyId, $this->branchId, $this->allowedBranchIds, $this->system] = $previous;
        }
    }
}
