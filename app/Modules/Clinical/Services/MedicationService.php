<?php

namespace App\Modules\Clinical\Services;

use App\Core\Support\BusinessRuleViolation;
use App\Modules\Clinical\Models\Medication;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;

class MedicationService
{
    public function save(User $actor, array $data, ?Medication $medication = null): Medication
    {
        if ($medication?->isGlobal() && ! $actor->is_super_admin) {
            throw new BusinessRuleViolation('Itens da base global não podem ser alterados pela clínica. Cadastre uma versão própria.', 'global_readonly', 403);
        }

        $medication ??= new Medication(['created_by' => $actor->id]);
        $medication->fill($data)->save();

        return $medication;
    }

    /**
     * Importa CSV ";" com cabeçalho: principio_ativo;nome_comercial;apresentacao;concentracao;
     * fabricante;via;posologia;controle. Em modo sistema grava na base global.
     *
     * @return array{created: int, skipped: int}
     */
    public function import(string $path, ?string $createdBy = null): array
    {
        $raw = (string) file_get_contents($path);

        if (! mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1');
        }

        $lines = preg_split('/\r\n|\r|\n/', trim(preg_replace('/^\xEF\xBB\xBF/', '', $raw)));
        $header = array_map(fn ($h) => strtolower(trim($h, ' "')), str_getcsv(array_shift($lines), ';'));
        $map = ['principio_ativo' => 'active_ingredient', 'nome_comercial' => 'commercial_name', 'apresentacao' => 'presentation',
            'concentracao' => 'concentration', 'fabricante' => 'manufacturer', 'via' => 'route', 'posologia' => 'default_posology', 'controle' => 'control_type'];

        if (! in_array('principio_ativo', $header, true)) {
            throw new BusinessRuleViolation('Cabeçalho inválido: a primeira coluna deve ser "principio_ativo".');
        }

        $stats = ['created' => 0, 'skipped' => 0];

        DB::transaction(function () use ($lines, $header, $map, $createdBy, &$stats) {
            foreach ($lines as $line) {
                $row = array_combine($header, array_pad(array_slice(str_getcsv($line, ';'), 0, count($header)), count($header), null));
                $data = [];
                foreach ($map as $csv => $attr) {
                    $value = trim((string) ($row[$csv] ?? ''));
                    $data[$attr] = $value === '' ? null : $value;
                }
                $data['control_type'] = array_key_exists((string) $data['control_type'], Medication::CONTROL_TYPES) ? $data['control_type'] : 'none';

                if (! $data['active_ingredient']) {
                    $stats['skipped']++;

                    continue;
                }

                Medication::create($data + ['created_by' => $createdBy]);
                $stats['created']++;
            }
        });

        return $stats;
    }
}
