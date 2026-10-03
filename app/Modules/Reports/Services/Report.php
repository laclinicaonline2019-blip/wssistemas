<?php

namespace App\Modules\Reports\Services;

/**
 * Resultado de um relatório: colunas tipadas (text, int, money, date, pct), linhas e resumo.
 * Valores monetários em centavos; a formatação fica com a tela/exportação.
 */
final class Report
{
    /**
     * @param  array<string, array{0: string, 1: string}>  $columns  chave → [rótulo, tipo]
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, string>  $summary  rótulo → valor já formatado
     * @param  array<string, mixed>|null  $totals  linha de totais (mesmas chaves das colunas)
     */
    public function __construct(
        public readonly string $key,
        public readonly string $title,
        public readonly array $columns,
        public readonly array $rows,
        public readonly array $summary = [],
        public readonly ?array $totals = null,
        public readonly ?string $note = null,
        public readonly bool $truncated = false,
    ) {}
}
