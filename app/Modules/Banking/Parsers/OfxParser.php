<?php

namespace App\Modules\Banking\Parsers;

use App\Core\Support\Format;

/**
 * OFX 1.x (SGML, sem fechamento das tags simples) e 2.x (XML). Bancos brasileiros costumam
 * gerar em Windows-1252/ISO-8859-1: o texto é convertido para UTF-8. Usa o FITID do banco
 * como identificador único do lançamento.
 */
class OfxParser
{
    public function parse(string $content): ParsedStatement
    {
        $content = $this->utf8($content);
        if (! preg_match('/<OFX>/i', $content)) {
            throw new StatementParseException('Arquivo não parece ser OFX (falta a tag <OFX>).');
        }

        preg_match_all('/<STMTTRN>(.*?)<\/STMTTRN>/is', $content, $blocks);
        $lines = [];
        foreach ($blocks[1] as $b) {
            $amount = Format::parseMoney($this->tag($b, 'TRNAMT'));
            $date = $this->date($this->tag($b, 'DTPOSTED'));
            if (! $amount || ! $date) {
                continue; // valor zero/ilegível: não é movimento
            }
            $name = $this->tag($b, 'NAME');
            $memo = $this->tag($b, 'MEMO');
            $description = trim(implode(' — ', array_unique(array_filter([$name, $memo])))) ?: ($this->tag($b, 'TRNTYPE') ?? 'Lançamento');
            $lines[] = ['date' => $date, 'amount_cents' => $amount, 'description' => mb_substr($description, 0, 255),
                'reference' => $this->tag($b, 'FITID') ?? $this->tag($b, 'CHECKNUM') ?? $this->tag($b, 'REFNUM')];
        }

        if ($lines === [] && ! preg_match('/<BANKTRANLIST>/i', $content)) {
            throw new StatementParseException('Nenhuma movimentação encontrada no OFX.');
        }

        $balance = null;
        if (preg_match('/<LEDGERBAL>(.*?)(<\/LEDGERBAL>|<AVAILBAL>|<\/STMTRS>)/is', $content, $m)) {
            $balance = Format::parseMoney($this->tag($m[1], 'BALAMT'));
            $balanceDate = $this->date($this->tag($m[1], 'DTASOF'));
        }

        return new ParsedStatement($lines, $this->date($this->tag($content, 'DTSTART')), $this->date($this->tag($content, 'DTEND')),
            $balance, $balanceDate ?? null, $this->tag($content, 'ACCTID'));
    }

    private function tag(string $s, string $tag): ?string
    {
        // SGML: <TAG>valor (até a próxima tag ou quebra de linha); XML: <TAG>valor</TAG>.
        return preg_match('/<'.$tag.'>\s*([^<\r\n]*)/i', $s, $m) && trim($m[1]) !== '' ? html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_XML1, 'UTF-8') : null;
    }

    /** 20261005, 20261005120000[-3:BRT] → 2026-10-05 */
    private function date(?string $v): ?string
    {
        return $v && preg_match('/^(\d{4})(\d{2})(\d{2})/', $v, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? "{$m[1]}-{$m[2]}-{$m[3]}" : null;
    }

    private function utf8(string $s): string
    {
        $s = preg_replace('/^\xEF\xBB\xBF/', '', $s);

        return mb_check_encoding($s, 'UTF-8') ? $s : mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
    }
}
