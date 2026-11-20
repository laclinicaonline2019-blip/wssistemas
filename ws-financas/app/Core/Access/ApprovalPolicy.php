<?php

namespace App\Core\Access;

use App\Core\Tenancy\TenantContext;
use App\Models\CostCenter;
use App\Models\Membership;
use App\Models\User;
use App\Models\WhatsappContact;

/**
 * Regras de funcionário (1.1.7), decididas SEMPRE no servidor:
 *  - despesa lançada por quem "precisa de aprovação" fica PENDENTE (não é despesa efetiva) — acima do limite, se houver;
 *  - administrador e gestor nunca precisam de aprovação e são os aprovadores;
 *  - centros de custo permitidos (nulo = todos).
 *
 * Origem das regras: o vínculo do usuário (painel ou número ligado a um usuário) ou o próprio número avulso do WhatsApp.
 *
 * @phpstan-type Rules array{requires_approval:bool, limit:?int, cost_center_ids:?array, responsible:?string}
 */
final class ApprovalPolicy
{
    public const APPROVERS = ['admin', 'gestor'];

    /** @return Rules */
    public static function none(?string $responsible = null): array
    {
        return ['requires_approval' => false, 'limit' => null, 'cost_center_ids' => null, 'responsible' => $responsible];
    }

    /** @return Rules */
    public static function forUser(?string $userId, ?string $accountId = null): array
    {
        if (! $userId) {
            return self::none();
        }
        $accountId ??= app(TenantContext::class)->accountIdOrNull();
        $m = $accountId ? Membership::query()->withoutGlobalScopes()->where('account_id', $accountId)->where('user_id', $userId)->first() : null;
        $name = User::query()->whereKey($userId)->value('name');
        if (! $m) {
            return self::none($name);
        }

        return ['requires_approval' => (bool) $m->requires_approval && ! in_array($m->role, self::APPROVERS, true), 'limit' => $m->approval_limit_cents,
            'cost_center_ids' => self::ids($m->cost_center_ids), 'responsible' => $name];
    }

    /** Número do WhatsApp: ligado a um usuário herda as regras dele; avulso usa as regras do próprio número. @return Rules */
    public static function forContact(WhatsappContact $c): array
    {
        if ($c->user_id) {
            $r = self::forUser($c->user_id, $c->account_id);
            $r['responsible'] = trim(($r['responsible'] ?? '').' (WhatsApp final '.$c->phone_last4.')');

            return $r;
        }

        return ['requires_approval' => (bool) $c->requires_approval, 'limit' => $c->approval_limit_cents, 'cost_center_ids' => self::ids($c->cost_center_ids),
            'responsible' => ($c->label ?: 'WhatsApp').' (final '.$c->phone_last4.')'];
    }

    /** A despesa precisa de aprovação? (receitas não passam por aprovação) */
    public static function needsApproval(array $rules, ?string $role, string $type, int $amountCents): bool
    {
        if ($type !== 'despesa' || empty($rules['requires_approval']) || in_array($role, [...self::APPROVERS, 'system'], true)) {
            return false;
        }

        return $rules['limit'] === null || $amountCents > (int) $rules['limit'];
    }

    public static function canApprove(?string $role): bool
    {
        return in_array($role, self::APPROVERS, true);
    }

    /** Centros de custo ativos da empresa que estas regras permitem. */
    public static function allowedCostCenters(string $companyId, array $rules)
    {
        $q = CostCenter::query()->where('company_id', $companyId)->where('active', true)->orderBy('name');
        if ($rules['cost_center_ids'] !== null) {
            $q->whereIn('id', $rules['cost_center_ids'] ?: ['-']);
        }

        return $q->get();
    }

    private static function ids(mixed $v): ?array
    {
        return is_array($v) ? array_values(array_filter($v, 'is_string')) : null;
    }
}
