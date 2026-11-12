<?php

namespace App\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Support\BusinessRule;
use App\Core\Support\Months;
use App\Models\Company;
use App\Models\Debt;
use App\Models\Entry;
use App\Models\LocalImport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Importa o armazenamento local do WS Finanças original (chave "wsfin:v1" do navegador).
 * - Nunca apaga nada: só acrescenta.
 * - Idempotente: cada item guarda o id original (external_ref); reimportar não duplica.
 * - Itens de exemplo do painel antigo são ignorados.
 */
class ImportService
{
    public function __construct(private readonly FinanceService $finance, private readonly AuditLogger $audit) {}

    public function import(Company $company, string $json, ?string $userId): array
    {
        $items = json_decode($json, true);
        if (is_array($items) && isset($items['wsfin:v1'])) {
            $items = is_string($items['wsfin:v1']) ? json_decode($items['wsfin:v1'], true) : $items['wsfin:v1'];
        }
        if (! is_array($items) || ! array_is_list($items)) {
            throw new BusinessRule('Arquivo inválido: esperado o conteúdo exportado do WS Finanças (lista de lançamentos).', 'importacao');
        }
        if (count($items) > 50000) {
            throw new BusinessRule('Arquivo grande demais (máximo de 50.000 itens por importação).', 'importacao');
        }

        $sum = ['despesas' => 0, 'receitas' => 0, 'dividas' => 0, 'config' => 0, 'ja_existentes' => 0, 'exemplos_ignorados' => 0, 'invalidos' => 0];
        DB::transaction(function () use ($company, $items, &$sum) {
            $groups = [];
            foreach ($items as $x) {
                if (! is_array($x) || empty($x['id']) || empty($x['tipo'])) {
                    $sum['invalidos']++;

                    continue;
                }
                if (! empty($x['exemplo'])) {
                    $sum['exemplos_ignorados']++;

                    continue;
                }
                $ref = 'local:'.mb_substr((string) $x['id'], 0, 70);

                if ($x['tipo'] === 'config') {
                    $this->finance->saveSettings($company, isset($x['orc']) ? (int) round(((float) $x['orc']) * 100) : null, $x['estrategia'] ?? null);
                    $sum['config']++;

                    continue;
                }
                if ($x['tipo'] === 'divida') {
                    if (Debt::query()->where('company_id', $company->id)->where('external_ref', $ref)->exists()) {
                        $sum['ja_existentes']++;

                        continue;
                    }
                    try {
                        $d = $this->finance->saveDebt($company, ['credor' => $x['credor'] ?? '', 'saldo' => (float) ($x['saldo'] ?? 0), 'juros' => (string) ($x['juros'] ?? 0), 'parcela' => (float) ($x['parcela'] ?? 0)]);
                        $d->forceFill(['external_ref' => $ref])->save();
                        $sum['dividas']++;
                    } catch (BusinessRule) {
                        $sum['invalidos']++;
                    }

                    continue;
                }
                if (! in_array($x['tipo'], ['despesa', 'receita'], true)) {
                    $sum['invalidos']++;

                    continue;
                }
                if (Entry::withTrashed()->where('company_id', $company->id)->where('external_ref', $ref)->exists()) {
                    $sum['ja_existentes']++;

                    continue;
                }
                $date = $x['tipo'] === 'despesa' ? ($x['venc'] ?? null) : ($x['data'] ?? null);
                if (! $date || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date)) {
                    $date = Months::valid($x['mes'] ?? null) ? $x['mes'].'-01' : null;
                }
                $valor = (int) round(((float) ($x['valor'] ?? 0)) * 100);
                if (! $date || $valor <= 0 || trim((string) ($x['desc'] ?? '')) === '') {
                    $sum['invalidos']++;

                    continue;
                }
                [$e] = $this->finance->create($company, ['tipo' => $x['tipo'], 'desc' => (string) $x['desc'], 'valor_cents' => $valor, 'cat' => $x['cat'] ?? null,
                    'data' => $date, 'pago' => ! empty($x['pago']), 'source' => 'import', 'external_ref' => $ref, 'exemplo' => true /* sem auditoria item a item */]);
                $e->forceFill(['is_sample' => false, 'competence' => Months::valid($x['mes'] ?? null) ? $x['mes'] : $e->competence,
                    'paid_at' => ! empty($x['pagoEm']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $x['pagoEm']) ? $x['pagoEm'] : $e->paid_at])->save();
                if (! empty($x['grupo'])) {
                    $groups[(string) $x['grupo']][] = $e->id;
                }
                $sum[$x['tipo'] === 'despesa' ? 'despesas' : 'receitas']++;
            }
            // contas fixas do painel antigo continuam agrupadas ("esta e as próximas")
            foreach ($groups as $ids) {
                if (count($ids) > 1) {
                    Entry::query()->whereIn('id', $ids)->update(['recurrence_group' => (string) Str::ulid(), 'recurrence_kind' => 'fixa']);
                }
            }
        });

        LocalImport::query()->create(['company_id' => $company->id, 'user_id' => $userId, 'checksum' => hash('sha256', $json), 'summary' => $sum]);
        $this->audit->log('dados.importar_local', $company, $sum);

        return $sum;
    }
}
