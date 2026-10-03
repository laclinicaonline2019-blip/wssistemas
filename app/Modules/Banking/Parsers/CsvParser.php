<?php

namespace App\Modules\Banking\Parsers;

use App\Core\Support\Format;

/**
 * CSV de extrato (exportado do internet banking). Detecta o separador (; , tab) e as colunas pelo
 * cabeçalho: data, descrição/histórico, valor (com sinal) OU crédito + débito, e documento/identificador.
 * Aceita valores no formato brasileiro (1.234,56), "(150,00)" e sufixo C/D. Linhas de saldo são ignoradas.
 */
class CsvParser
{
    private const COLUMNS = [
        'date' => ['data', 'date', 'dt', 'data lancamento', 'data do lancamento', 'data movimento'],
        'description' => ['descricao', 'historico', 'lancamento', 'description', 'memo', 'detalhes', 'titulo'],
        'amount' => ['valor', 'amount', 'valor (r$)', 'valor r$', 'quantia'],
        'credit' => ['credito', 'entrada', 'entradas', 'credito (r$)'],
        'debit' => ['debito', 'saida', 'saidas', 'debito (r$)'],
        'reference' => ['documento', 'doc', 'n documento', 'no documento', 'numero documento', 'identificador', 'id', 'referencia', 'nr. documento'],
    ];

    public function parse(string $content): ParsedStatement
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $content = mb_check_encoding($content, 'UTF-8') ? $content : mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        $rows = preg_split('/\r\n|\r|\n/', trim($content));
        $delimiter = collect([';', "\t", ','])->sortByDesc(fn ($d) => substr_count($rows[0] ?? '', $d))->first();

        // Cabeçalho: primeira linha (até a 10ª) que tenha data e valor/crédito.
        $map = null;
        foreach (array_slice($rows, 0, 10, true) as $i => $row) {
            $map = $this->header(str_getcsv($row, $delimiter, '"', ''));
            if ($map) {
                $rows = array_slice($rows, $i + 1);
                break;
            }
        }
        if (! $map) {
            throw new StatementParseException('Cabeçalho do CSV não reconhecido. Use colunas "Data", "Descrição" e "Valor" (ou "Crédito" e "Débito").');
        }

        $lines = [];
        foreach ($rows as $row) {
            if (trim($row) === '') {
                continue;
            }
            $c = str_getcsv($row, $delimiter, '"', '');
            $date = $this->date($c[$map['date']] ?? '');
            $description = trim((string) ($c[$map['description'] ?? -1] ?? ''));
            if (! $date || preg_match('/^saldo\b/i', Format::searchable($description))) {
                continue; // linha de saldo ou rodapé
            }
            $amount = isset($map['amount']) ? $this->money($c[$map['amount']] ?? '') : null;
            if ($amount === null) {
                $credit = isset($map['credit']) ? $this->money($c[$map['credit']] ?? '') : null;
                $debit = isset($map['debit']) ? $this->money($c[$map['debit']] ?? '') : null;
                $amount = $credit ? abs($credit) : ($debit ? -abs($debit) : null);
            }
            if (! $amount) {
                continue;
            }
            $lines[] = ['date' => $date, 'amount_cents' => $amount, 'description' => mb_substr($description ?: 'Lançamento', 0, 255),
                'reference' => isset($map['reference']) ? (trim((string) ($c[$map['reference']] ?? '')) ?: null) : null];
        }

        if ($lines === []) {
            throw new StatementParseException('Nenhuma movimentação encontrada no CSV.');
        }
        $dates = array_column($lines, 'date');

        return new ParsedStatement($lines, min($dates), max($dates));
    }

    private function header(array $cells): ?array
    {
        $map = [];
        foreach ($cells as $i => $cell) {
            $name = trim(preg_replace('/[^a-z0-9 ().$]/', '', Format::searchable($cell)));
            foreach (self::COLUMNS as $key => $names) {
                if (! isset($map[$key]) && in_array($name, $names, true)) {
                    $map[$key] = $i;
                }
            }
        }

        return isset($map['date']) && (isset($map['amount']) || isset($map['credit']) || isset($map['debit'])) ? $map : null;
    }

    private function money(string $v): ?int
    {
        $v = trim($v);
        if ($v === '') {
            return null;
        }
        $negative = (bool) preg_match('/^\(.*\)$|\bD$|^-/i', $v);
        $cents = Format::parseMoney(preg_replace('/\s*[CD]$/i', '', trim($v, '()')));

        return $cents === null ? null : ($negative ? -abs($cents) : abs($cents));
    }

    /** dd/mm/aaaa, dd/mm/aa, aaaa-mm-dd */
    private function date(string $v): ?string
    {
        $v = trim($v);
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{2,4})/', $v, $m)) {
            $y = strlen($m[3]) === 2 ? '20'.$m[3] : $m[3];

            return checkdate((int) $m[2], (int) $m[1], (int) $y) ? "{$y}-{$m[2]}-{$m[1]}" : null;
        }

        return preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $v, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? "{$m[1]}-{$m[2]}-{$m[3]}" : null;
    }
}
