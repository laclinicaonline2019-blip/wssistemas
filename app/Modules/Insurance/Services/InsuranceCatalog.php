<?php

namespace App\Modules\Insurance\Services;

use App\Core\Support\BusinessRuleViolation;
use App\Modules\Insurance\Models\Insurer;
use App\Modules\Insurance\Models\PriceItem;
use App\Modules\Insurance\Models\PriceTable;
use App\Modules\Insurance\Models\Procedure;
use App\Modules\Patients\Models\PatientInsurance;
use Illuminate\Support\Facades\DB;

/**
 * Regras de cadastro do convênio: preço vigente do procedimento (tabela do plano
 * tem prioridade sobre a geral), credenciamento e validade da carteirinha.
 */
class InsuranceCatalog
{
    /** Preço do procedimento para o convênio/plano na data (Y-m-d), ou null se não houver tabela vigente. */
    public function priceFor(string $insurerId, ?string $planId, string $procedureId, string $date): ?PriceItem
    {
        return PriceItem::query()
            ->join('insurance_price_tables as t', 't.id', '=', 'insurance_price_items.price_table_id')
            ->where('t.insurer_id', $insurerId)->where('t.is_active', true)
            ->where('insurance_price_items.procedure_id', $procedureId)
            ->where('t.valid_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('t.valid_until')->orWhere('t.valid_until', '>=', $date))
            ->where(fn ($q) => $q->whereNull('t.plan_id')->when($planId, fn ($q) => $q->orWhere('t.plan_id', $planId)))
            // Tabela específica do plano primeiro; depois a mais recente.
            ->orderByRaw('CASE WHEN t.plan_id IS NULL THEN 1 ELSE 0 END')->orderByDesc('t.valid_from')
            ->select('insurance_price_items.*')->first();
    }

    /**
     * Valida se a carteirinha pode ser usada com o médico na data: convênio cadastrado e ativo,
     * médico credenciado e carteirinha dentro da validade.
     */
    public function assertCoverage(PatientInsurance $insurance, ?string $doctorId, string $date): Insurer
    {
        if (! $insurance->is_active) {
            throw new BusinessRuleViolation('Carteirinha removida do cadastro do paciente.', 'insurance_inactive');
        }
        if ($insurance->isExpired($date)) {
            throw new BusinessRuleViolation('Carteirinha do convênio vencida na data do atendimento.', 'insurance_expired');
        }

        $insurer = $insurance->insurer_id ? Insurer::query()->find($insurance->insurer_id) : null;

        if (! $insurer) {
            throw new BusinessRuleViolation('O convênio desta carteirinha não está cadastrado (Convênios → cadastre e vincule no cadastro do paciente).', 'insurer_not_registered');
        }
        if (! $insurer->is_active) {
            throw new BusinessRuleViolation("O convênio {$insurer->name} está desativado.", 'insurer_inactive');
        }
        if ($doctorId && ! $insurer->acceptsDoctor($doctorId)) {
            throw new BusinessRuleViolation("Este médico não é credenciado no convênio {$insurer->name}.", 'doctor_not_credentialed');
        }

        return $insurer;
    }

    /** Tabelas do mesmo convênio/plano com vigência sobreposta geram preço ambíguo. */
    public function assertNoOverlap(string $insurerId, ?string $planId, string $from, ?string $until, ?string $ignoreId = null): void
    {
        $overlap = PriceTable::query()->where('insurer_id', $insurerId)->where('is_active', true)
            ->when($planId, fn ($q) => $q->where('plan_id', $planId), fn ($q) => $q->whereNull('plan_id'))
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $from))
            ->when($until, fn ($q) => $q->where('valid_from', '<=', $until))
            ->exists();

        if ($overlap) {
            throw new BusinessRuleViolation('Já existe tabela ativa deste convênio/plano com vigência no mesmo período. Encerre a anterior (data final) antes.', 'price_table_overlap');
        }
    }

    /**
     * Importa procedimentos de um CSV "codigo;descricao[;tipo]" (ex.: extraído da tabela TUSS da ANS).
     *
     * @return array{created: int, updated: int, skipped: int}
     */
    public function importProcedures(string $path, string $tableCode = '22'): array
    {
        $handle = fopen($path, 'rb');
        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0];
        $kinds = array_keys(Procedure::KINDS);

        DB::transaction(function () use ($handle, $tableCode, $kinds, &$stats) {
            while (($line = fgets($handle)) !== false) {
                $line = trim(mb_check_encoding($line, 'UTF-8') ? $line : mb_convert_encoding($line, 'UTF-8', 'ISO-8859-1'));
                $cols = str_getcsv($line, str_contains($line, ';') ? ';' : ',');
                $code = preg_replace('/\D/', '', (string) ($cols[0] ?? ''));
                $name = trim((string) ($cols[1] ?? ''));

                if ($code === '' || strlen($code) > 10 || $name === '') {
                    $stats['skipped']++;

                    continue;
                }

                $kind = in_array($cols[2] ?? null, $kinds, true) ? $cols[2] : (str_starts_with($code, '101') ? 'consultation' : 'exam');
                $existing = Procedure::query()->where('table_code', $tableCode)->where('code', $code)->first();

                if ($existing) {
                    $existing->update(['name' => mb_substr($name, 0, 150), 'is_sample' => false]);
                    $stats['updated']++;
                } else {
                    Procedure::create(['table_code' => $tableCode, 'code' => $code, 'name' => mb_substr($name, 0, 150), 'kind' => $kind]);
                    $stats['created']++;
                }
            }
        });

        fclose($handle);

        return $stats;
    }
}
