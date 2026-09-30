<?php

namespace App\Core\Support;

use Illuminate\Support\Facades\DB;

/**
 * Sequências numéricas por empresa (ex.: nº de prontuário), sem lacunas por
 * concorrência: incremento sob lock de linha dentro de transação.
 */
class SequenceGenerator
{
    public function next(string $companyId, string $name): int
    {
        return DB::transaction(function () use ($companyId, $name) {
            DB::table('company_sequences')->insertOrIgnore(['company_id' => $companyId, 'name' => $name, 'value' => 0]);

            $current = (int) DB::table('company_sequences')
                ->where('company_id', $companyId)->where('name', $name)
                ->lockForUpdate()->value('value');

            DB::table('company_sequences')->where('company_id', $companyId)->where('name', $name)->update(['value' => $current + 1]);

            return $current + 1;
        });
    }
}
