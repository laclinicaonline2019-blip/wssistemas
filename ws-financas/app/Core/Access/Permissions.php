<?php

namespace App\Core\Access;

use App\Core\Tenancy\TenantContext;

/**
 * Permissões por papel, verificadas no SERVIDOR (middleware `ability:` e serviços).
 * O navegador só recebe a lista para esconder botões — nunca decide nada.
 */
final class Permissions
{
    public const ABILITIES = [
        'financeiro.ver' => 'Ver painel, contas e relatórios',
        'financeiro.lancar' => 'Lançar, editar e excluir receitas/despesas e dívidas',
        'relatorios.exportar' => 'Exportar relatórios (CSV/PDF)',
        'empresas.gerenciar' => 'Cadastrar empresas e contas bancárias',
        'usuarios.gerenciar' => 'Gerenciar usuários e permissões',
        'whatsapp.gerenciar' => 'Gerenciar números autorizados do WhatsApp',
        'dados.importar' => 'Importar dados locais',
        'assinatura.gerenciar' => 'Gerenciar assinatura e pagamentos',
        'lgpd.gerenciar' => 'Exportar dados e solicitar exclusão (LGPD)',
        'auditoria.ver' => 'Ver auditoria',
    ];

    public const ROLES = [
        'admin' => ['*'],
        'gestor' => ['financeiro.ver', 'financeiro.lancar', 'relatorios.exportar', 'empresas.gerenciar', 'whatsapp.gerenciar', 'dados.importar', 'auditoria.ver'],
        'financeiro' => ['financeiro.ver', 'financeiro.lancar', 'relatorios.exportar', 'dados.importar'],
        'consulta' => ['financeiro.ver'],
        'contador' => ['financeiro.ver', 'relatorios.exportar'],
        'system' => ['*'],
    ];

    public static function roleCan(?string $role, string $ability): bool
    {
        $list = self::ROLES[$role ?? ''] ?? [];

        return in_array('*', $list, true) || in_array($ability, $list, true);
    }

    public static function can(string $ability): bool
    {
        return self::roleCan(app(TenantContext::class)->role(), $ability);
    }

    /** @return list<string> */
    public static function abilitiesFor(?string $role): array
    {
        return array_values(array_filter(array_keys(self::ABILITIES), fn ($a) => self::roleCan($role, $a)));
    }
}
