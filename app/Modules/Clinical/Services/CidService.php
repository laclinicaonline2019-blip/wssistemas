<?php

namespace App\Modules\Clinical\Services;

use App\Core\Support\BusinessRuleViolation;
use App\Modules\Clinical\Models\CidCode;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CidService
{
    /**
     * Busca priorizando favoritos do usuário e os códigos que ele mais usa.
     *
     * @return Collection<int, array{id: string, code: string, description: string, favorite: bool}>
     */
    public function search(User $user, string $term, int $limit = 15): Collection
    {
        $favorites = $this->favoriteIds($user);
        $usage = $this->usageCounts($user);

        return CidCode::query()->search($term)->limit(60)->get()
            ->sortBy([
                fn ($a, $b) => (int) in_array($b->id, $favorites, true) <=> (int) in_array($a->id, $favorites, true),
                fn ($a, $b) => ($usage[$b->code] ?? 0) <=> ($usage[$a->code] ?? 0),
                fn ($a, $b) => strcmp($a->code, $b->code),
            ])
            ->take($limit)->values()
            ->map(fn (CidCode $c) => ['id' => $c->id, 'code' => $c->code, 'description' => $c->description, 'version' => $c->version, 'favorite' => in_array($c->id, $favorites, true)]);
    }

    /** @return Collection<int, CidCode> favoritos + mais usados do médico */
    public function shortcuts(User $user, int $limit = 12): Collection
    {
        $ids = $this->favoriteIds($user);
        $mostUsed = array_keys(array_slice($this->usageCounts($user), 0, $limit, true));

        return CidCode::query()->where(fn ($q) => $q->whereIn('id', $ids)->orWhereIn('code', $mostUsed))->limit($limit * 2)->get()
            ->sortByDesc(fn ($c) => in_array($c->id, $ids, true))->take($limit)->values();
    }

    public function toggleFavorite(User $user, string $cidId): bool
    {
        CidCode::query()->findOrFail($cidId);
        $exists = DB::table('cid_favorites')->where('user_id', $user->id)->where('cid_code_id', $cidId)->exists();

        if ($exists) {
            DB::table('cid_favorites')->where('user_id', $user->id)->where('cid_code_id', $cidId)->delete();

            return false;
        }

        DB::table('cid_favorites')->insert(['company_id' => $user->company_id, 'user_id' => $user->id, 'cid_code_id' => $cidId, 'created_at' => now()]);

        return true;
    }

    /**
     * Importa a tabela CID-10 do DATASUS (CID-10-SUBCATEGORIAS.CSV / CID-10-CATEGORIAS.CSV,
     * separador ";", ISO-8859-1) ou um CSV simples "codigo;descricao". Atualiza descrições
     * existentes e NUNCA altera diagnósticos já registrados (que guardam cópia do texto).
     *
     * @return array{created: int, updated: int, skipped: int}
     */
    public function import(string $path, string $version = 'CID-10'): array
    {
        $raw = file_get_contents($path);

        if ($raw === false || $raw === '') {
            throw new BusinessRuleViolation('Arquivo vazio ou ilegível.');
        }

        if (! mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1');
        }

        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
        $lines = preg_split('/\r\n|\r|\n/', trim($raw));
        $header = array_map(fn ($h) => strtoupper(trim($h, ' "')), str_getcsv(array_shift($lines), ';'));
        $codeIdx = array_search('SUBCAT', $header, true);
        $codeIdx = $codeIdx === false ? array_search('CAT', $header, true) : $codeIdx;
        $descIdx = array_search('DESCRICAO', $header, true);
        $sexIdx = array_search('RESTRSEXO', $header, true);

        if ($codeIdx === false || $descIdx === false) {
            // CSV simples sem cabeçalho reconhecido: primeira linha é dado.
            array_unshift($lines, implode(';', $header));
            [$codeIdx, $descIdx, $sexIdx] = [0, 1, false];
        }

        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0];

        DB::transaction(function () use ($lines, $codeIdx, $descIdx, $sexIdx, $version, &$stats) {
            foreach ($lines as $line) {
                $cols = str_getcsv($line, ';');
                $code = strtoupper(trim($cols[$codeIdx] ?? '', ' "'));
                $description = trim($cols[$descIdx] ?? '', ' "');

                if (! preg_match('/^[A-Z]\d{2}(\.?\d{1,2})?$/', $code) || $description === '') {
                    $stats['skipped']++;

                    continue;
                }

                // DATASUS grava "A000"; exibimos "A00.0".
                if (strlen($code) === 4 && ! str_contains($code, '.')) {
                    $code = substr($code, 0, 3).'.'.substr($code, 3);
                }

                $sex = $sexIdx !== false ? strtoupper(trim($cols[$sexIdx] ?? '')) : '';
                $cid = CidCode::query()->firstOrNew(['version' => $version, 'code' => $code]);
                $stats[$cid->exists ? 'updated' : 'created']++;
                $cid->fill(['description' => mb_substr($description, 0, 255), 'sex_restriction' => in_array($sex, ['F', 'M'], true) ? $sex : null, 'is_active' => true, 'is_sample' => false])->save();
            }
        });

        return $stats;
    }

    private function favoriteIds(User $user): array
    {
        return DB::table('cid_favorites')->where('user_id', $user->id)->pluck('cid_code_id')->all();
    }

    /** @return array<string, int> código → nº de usos pelo médico do usuário */
    private function usageCounts(User $user): array
    {
        return DB::table('encounter_diagnoses as d')
            ->join('encounter_versions as v', fn ($j) => $j->on('v.encounter_id', '=', 'd.encounter_id')->on('v.version', '=', 'd.version'))
            ->where('d.company_id', $user->company_id)->where('v.author_id', $user->id)
            ->select('d.code', DB::raw('count(*) as total'))->groupBy('d.code')->orderByDesc('total')->limit(50)
            ->pluck('total', 'code')->map(fn ($v) => (int) $v)->all();
    }
}
