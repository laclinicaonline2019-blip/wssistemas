<?php

namespace App\Modules\Banking\Parsers;

/** Extrato lido de um arquivo/serviço, antes de gravar. Datas AAAA-MM-DD, valores em centavos (débito negativo). */
final class ParsedStatement
{
    /** @param  list<array{date: string, amount_cents: int, description: string, reference: ?string}>  $lines */
    public function __construct(
        public readonly array $lines,
        public readonly ?string $periodStart = null,
        public readonly ?string $periodEnd = null,
        public readonly ?int $balanceCents = null,
        public readonly ?string $balanceDate = null,
        public readonly ?string $accountNumber = null,
    ) {}
}
