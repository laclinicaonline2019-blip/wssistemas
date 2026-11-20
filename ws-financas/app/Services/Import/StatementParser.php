<?php

namespace App\Services\Import;

use App\Core\Support\BusinessRule;
use Carbon\CarbonImmutable;
use ZipArchive;

/**
 * Leitura de extratos bancários SEM bibliotecas externas (funciona na hospedagem compartilhada):
 *  - OFX (SGML ou XML) — padrão dos bancos brasileiros;
 *  - CSV (separador ; , ou tab; colunas reconhecidas pelo cabeçalho);
 *  - Excel .xlsx (lido como ZIP + XML; primeira planilha);
 *  - PDF com TEXTO (extrato gerado pelo banco). PDF escaneado (imagem) não é lido — é preciso exportar em OFX/CSV/Excel.
 *
 * Devolve linhas normalizadas: data (AAAA-MM-DD), descrição, valor em centavos (positivo), tipo (receita|despesa) e uma
 * identificação estável (FITID do banco ou conteúdo + ordem) usada para não importar a mesma transação duas vezes.
 */
class StatementParser
{
    public const MAX_ROWS = 2000;

    /** @return array{format:string, rows:list<array{data:string, desc:string, valor_cents:int, tipo:string, id:string}>} */
    public function parse(string $path, string $filename): array
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $head = (string) file_get_contents($path, false, null, 0, 1024);
        $format = match (true) {
            str_starts_with($head, '%PDF') => 'pdf',
            str_starts_with($head, "PK\x03\x04") => 'xlsx',
            $ext === 'ofx' || $ext === 'qfx' || stripos($head, 'OFXHEADER') !== false || stripos($head, '<OFX>') !== false => 'ofx',
            in_array($ext, ['csv', 'txt'], true) => 'csv',
            default => throw new BusinessRule('Formato não reconhecido. Envie o extrato em OFX, CSV, Excel (.xlsx) ou PDF.', 'importacao'),
        };
        if ($format === 'xlsx' && $ext === 'xls') {
            throw new BusinessRule('Arquivo .xls antigo: abra no Excel e salve como .xlsx (ou exporte em CSV).', 'importacao');
        }
        $rows = match ($format) {
            'ofx' => $this->ofx((string) file_get_contents($path)),
            'csv' => $this->table($this->csvRows($this->utf8((string) file_get_contents($path)))),
            'xlsx' => $this->table($this->xlsxRows($path)),
            'pdf' => $this->pdf($path),
        };
        $rows = array_values(array_filter($rows, fn ($r) => $r['valor_cents'] > 0 && $r['data'] !== '' && $r['desc'] !== ''));
        if (! $rows) {
            throw new BusinessRule($format === 'pdf'
                ? 'Não encontrei transações neste PDF. Se for um extrato escaneado (imagem) ou protegido, exporte o extrato em OFX, CSV ou Excel no site do banco.'
                : 'Não encontrei transações no arquivo. Confira se é o extrato (com data, descrição e valor).', 'importacao');
        }
        if (count($rows) > self::MAX_ROWS) {
            throw new BusinessRule('Arquivo com mais de '.self::MAX_ROWS.' transações: divida o período em partes menores.', 'importacao');
        }

        return ['format' => $format, 'rows' => $this->withIds($rows)];
    }

    // ------------------------------------------------------------------ OFX

    private function ofx(string $raw): array
    {
        $raw = $this->utf8($raw);
        preg_match_all('/<STMTTRN>(.*?)(?:<\/STMTTRN>|(?=<STMTTRN>)|(?=<\/BANKTRANLIST>))/is', $raw, $m);
        $out = [];
        foreach ($m[1] as $block) {
            $tag = function (string $name) use ($block): string {
                return preg_match('/<'.$name.'>([^<\r\n]*)/i', $block, $x) ? trim(html_entity_decode($x[1], ENT_QUOTES | ENT_XML1, 'UTF-8')) : '';
            };
            $amount = $this->amount($tag('TRNAMT'), true);
            if ($amount === null) {
                continue;
            }
            $date = preg_match('/^(\d{4})(\d{2})(\d{2})/', $tag('DTPOSTED'), $d) ? "{$d[1]}-{$d[2]}-{$d[3]}" : '';
            $desc = trim($tag('MEMO') !== '' ? $tag('MEMO') : $tag('NAME'));
            $out[] = ['data' => $this->validDate($date), 'desc' => $this->cleanDesc($desc), 'valor_cents' => abs($amount),
                'tipo' => $amount < 0 ? 'despesa' : 'receita', 'fitid' => $tag('FITID')];
        }

        return $out;
    }

    // ------------------------------------------------------------------ CSV / Excel (tabela)

    /** @return list<list<string>> */
    private function csvRows(string $text): array
    {
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text;
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        $sample = implode("\n", array_slice($lines, 0, 20));
        $delim = collect([';', ',', "\t"])->sortByDesc(fn ($d) => substr_count($sample, $d))->first();
        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) !== '') {
                $rows[] = array_map('trim', str_getcsv($line, $delim, '"', ''));
            }
        }

        return $rows;
    }

    /** Primeira planilha do .xlsx, como linhas de texto. @return list<list<string>> */
    private function xlsxRows(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new BusinessRule('Não foi possível abrir o arquivo do Excel.', 'importacao');
        }
        try {
            $shared = [];
            if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
                $sx = $this->xml($xml);
                foreach ($sx->si as $si) {
                    $shared[] = isset($si->t) ? (string) $si->t : implode('', array_map('strval', iterator_to_array($si->xpath('.//*[local-name()="t"]') ?: [], false)));
                }
            }
            $sheet = 'xl/worksheets/sheet1.xml';
            if (($wb = $zip->getFromName('xl/workbook.xml')) !== false && ($rels = $zip->getFromName('xl/_rels/workbook.xml.rels')) !== false) {
                $first = $this->xml($wb)->xpath('//*[local-name()="sheet"]')[0] ?? null;
                $rid = $first ? (string) ($first->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'] ?? '') : '';
                foreach ($this->xml($rels)->xpath('//*[local-name()="Relationship"]') ?: [] as $r) {
                    if ((string) $r['Id'] === $rid) {
                        $sheet = 'xl/'.ltrim(preg_replace('#^/?xl/#', '', (string) $r['Target']) ?? '', '/');
                    }
                }
            }
            $data = $zip->getFromName($sheet);
            if ($data === false) {
                throw new BusinessRule('Planilha não encontrada no arquivo do Excel.', 'importacao');
            }
            $rows = [];
            foreach ($this->xml($data)->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]') ?: [] as $row) {
                $line = [];
                foreach ($row->xpath('./*[local-name()="c"]') ?: [] as $c) {
                    $col = $this->colIndex((string) $c['r']);
                    $t = (string) $c['t'];
                    $v = (string) ($c->v ?? '');
                    $val = match ($t) {
                        's' => $shared[(int) $v] ?? '',
                        'inlineStr' => implode('', array_map('strval', $c->xpath('.//*[local-name()="t"]') ?: [])),
                        default => $v,
                    };
                    $line[$col] = trim($val);
                }
                if ($line) {
                    $max = max(array_keys($line));
                    $rows[] = array_map(fn ($i) => $line[$i] ?? '', range(0, $max));
                }
            }

            return $rows;
        } finally {
            $zip->close();
        }
    }

    /** Reconhece as colunas pelo cabeçalho (data, descrição/histórico, valor ou crédito/débito, tipo D/C). */
    private function table(array $rows): array
    {
        $headerAt = null;
        $map = [];
        foreach (array_slice($rows, 0, 15, true) as $i => $r) {
            $m = $this->headerMap($r);
            if (isset($m['data']) && (isset($m['valor']) || isset($m['credito']) || isset($m['debito']))) {
                [$headerAt, $map] = [$i, $m];
                break;
            }
        }
        if ($headerAt === null) {
            throw new BusinessRule('Não reconheci as colunas. O arquivo precisa ter um cabeçalho com Data, Descrição (ou Histórico) e Valor (ou Crédito/Débito).', 'importacao');
        }
        $out = [];
        foreach (array_slice($rows, $headerAt + 1) as $r) {
            $date = $this->date($r[$map['data']] ?? '');
            if ($date === '') {
                continue;   // linhas de saldo, totais, rodapé
            }
            $desc = $this->cleanDesc(isset($map['desc']) ? ($r[$map['desc']] ?? '') : '');
            if (preg_match('/^(saldo|saldo anterior|saldo do dia|saldo final|total)\b/iu', $desc)) {
                continue;
            }
            if (isset($map['valor'])) {
                $v = $this->amount($r[$map['valor']] ?? '', true);
                if ($v !== null && isset($map['tipo'])) {
                    $t = mb_strtolower(trim($r[$map['tipo']] ?? ''));
                    if (in_array($t, ['d', 'débito', 'debito', 'saída', 'saida', 'despesa', '-'], true)) {
                        $v = -abs($v);
                    } elseif (in_array($t, ['c', 'crédito', 'credito', 'entrada', 'receita', '+'], true)) {
                        $v = abs($v);
                    }
                }
            } else {
                $c = $this->amount($r[$map['credito']] ?? '', false) ?? 0;
                $d = $this->amount($r[$map['debito']] ?? '', false) ?? 0;
                $v = $c > 0 ? $c : ($d !== 0 ? -abs($d) : null);
            }
            if ($v === null || $v === 0) {
                continue;
            }
            $out[] = ['data' => $date, 'desc' => $desc ?: 'Transação', 'valor_cents' => abs($v), 'tipo' => $v < 0 ? 'despesa' : 'receita', 'fitid' => ''];
        }

        return $out;
    }

    private function headerMap(array $r): array
    {
        $m = [];
        foreach ($r as $i => $h) {
            $k = mb_strtolower(trim(\Illuminate\Support\Str::ascii((string) $h)));
            $set = function (string $key) use (&$m, $i) {
                $m[$key] ??= $i;
            };
            match (true) {
                (bool) preg_match('/^(data|date|dt|data (do )?lancamento|data (da )?transacao|data mov\w*)$/', $k) => $set('data'),
                (bool) preg_match('/^(descricao|historico|lancamento|memo|descricao do lancamento|detalhes?|estabelecimento|titulo)$/', $k) => $set('desc'),
                (bool) preg_match('/^(valor|valor \(r\$\)|valor r\$|amount|quantia|montante)$/', $k) => $set('valor'),
                (bool) preg_match('/^(credito|creditos|entrada|entradas|valor credito)( \(r\$\))?$/', $k) => $set('credito'),
                (bool) preg_match('/^(debito|debitos|saida|saidas|valor debito)( \(r\$\))?$/', $k) => $set('debito'),
                (bool) preg_match('/^(tipo|d\/c|c\/d|natureza|sinal)$/', $k) => $set('tipo'),
                default => null,
            };
        }

        return $m;
    }

    // ------------------------------------------------------------------ PDF (somente texto)

    private function pdf(string $path): array
    {
        $raw = (string) file_get_contents($path);
        if (preg_match('/\/Encrypt\b/', $raw)) {
            throw new BusinessRule('Este PDF está protegido por senha. Exporte o extrato em OFX, CSV ou Excel no site do banco.', 'importacao');
        }
        $text = '';
        preg_match_all('/<<(.*?)>>\s*stream\r?\n(.*?)\r?\nendstream/s', $raw, $m, PREG_SET_ORDER);
        foreach ($m as [, $dict, $data]) {
            if (preg_match('/\/Subtype\s*\/Image/', $dict)) {
                continue;
            }
            if (str_contains($dict, '/FlateDecode')) {
                $data = @gzuncompress($data) ?: (@gzinflate($data) ?: (@gzinflate(substr($data, 2)) ?: ''));
            }
            if ($data !== '' && str_contains($data, 'BT')) {
                $text .= $this->pdfText($data)."\n";
            }
        }
        $out = [];
        $year = (int) date('Y');
        foreach (preg_split('/\n/', $text) ?: [] as $line) {
            $line = trim(preg_replace('/\s+/u', ' ', $line) ?? '');
            // 05/11/2026 MERCADO CENTRAL -123,45  |  05/11 PIX RECEBIDO 1.500,00 C
            if (! preg_match('/^(\d{2}\/\d{2}(?:\/\d{2,4})?)\s+(.+?)\s+(-?R?\$?\s?-?[\d.]+,\d{2})\s*(-|D|C)?$/u', $line, $x)) {
                continue;
            }
            $date = $this->date(strlen($x[1]) === 5 ? $x[1].'/'.$year : $x[1]);
            $v = $this->amount($x[3], true);
            if ($date === '' || $v === null) {
                continue;
            }
            if (($x[4] ?? '') === 'D' || ($x[4] ?? '') === '-') {
                $v = -abs($v);
            } elseif (($x[4] ?? '') === 'C') {
                $v = abs($v);
            }
            $desc = $this->cleanDesc($x[2]);
            if (preg_match('/^(saldo|total)\b/iu', $desc)) {
                continue;
            }
            $out[] = ['data' => $date, 'desc' => $desc, 'valor_cents' => abs($v), 'tipo' => $v < 0 ? 'despesa' : 'receita', 'fitid' => ''];
        }

        return $out;
    }

    /** Texto dos operadores Tj/TJ/'/" de um fluxo de conteúdo (quebra de linha nos operadores de posição e fim de texto). */
    private function pdfText(string $content): string
    {
        $out = '';
        preg_match_all('/\[(.*?)\]\s*TJ|\((?:\\\\.|[^\\\\)])*\)\s*(?:Tj|\'|")|(T\*|Td|TD|Tm|ET)(?=\s|$)/s', $content, $m, PREG_SET_ORDER);
        foreach ($m as $t) {
            if (! empty($t[2])) {
                $out .= "\n";

                continue;
            }
            if (isset($t[1]) && $t[1] !== '') {
                preg_match_all('/\((?:\\\\.|[^\\\\)])*\)|(-?\d+(?:\.\d+)?)/s', $t[1], $parts, PREG_SET_ORDER);
                foreach ($parts as $p) {
                    if (isset($p[1]) && $p[1] !== '') {
                        $out .= (float) $p[1] < -200 ? ' ' : '';   // espaçamento grande entre palavras
                    } else {
                        $out .= $this->pdfString($p[0]);
                    }
                }
            } else {
                $out .= $this->pdfString(rtrim(preg_replace('/\s*(Tj|\'|")$/', '', $t[0]) ?? ''));
            }
        }

        return $out;
    }

    private function pdfString(string $s): string
    {
        $s = substr($s, 1, -1);
        $s = preg_replace_callback('/\\\\([nrtbf()\\\\]|[0-7]{1,3})/', fn ($m) => match ($m[1]) {
            'n' => "\n", 'r' => '', 't' => ' ', 'b', 'f' => '', '(' => '(', ')' => ')', '\\' => '\\', default => chr(octdec($m[1]) & 0xFF),
        }, $s) ?? $s;

        return mb_check_encoding($s, 'UTF-8') ? $s : mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
    }

    // ------------------------------------------------------------------ apoio

    /** Identificação estável de cada transação: FITID do banco; sem ele, conteúdo + ordem entre linhas iguais. */
    private function withIds(array $rows): array
    {
        $seen = [];
        foreach ($rows as &$r) {
            $base = $r['data'].'|'.$r['valor_cents'].'|'.$r['tipo'].'|'.mb_strtolower($r['desc']);
            $seen[$base] = ($seen[$base] ?? 0) + 1;
            $r['id'] = 'imp:'.substr(hash('sha256', ($r['fitid'] !== '' ? 'fitid|'.$r['fitid'].'|'.$r['data'].'|'.$r['valor_cents'] : $base.'|'.$seen[$base])), 0, 40);
            unset($r['fitid']);
        }

        return $rows;
    }

    /** "1.234,56" | "-1234.56" | "R$ 10,00" | "(50,00)" → centavos com sinal. */
    private function amount(string $v, bool $signed): ?int
    {
        $v = trim(str_replace(["\u{00A0}", ' ', 'R$', 'r$'], '', $v));
        if ($v === '') {
            return null;
        }
        $neg = str_starts_with($v, '-') || str_ends_with($v, '-') || (str_starts_with($v, '(') && str_ends_with($v, ')'));
        $v = trim($v, '-+()');
        if (preg_match('/^\d{1,3}(\.\d{3})*,\d{1,2}$|^\d+,\d{1,2}$/', $v)) {
            $v = str_replace(['.', ','], ['', '.'], $v);
        } elseif (preg_match('/^\d{1,3}(,\d{3})*\.\d{1,2}$/', $v)) {
            $v = str_replace(',', '', $v);
        } elseif (! preg_match('/^\d+(\.\d+)?$/', $v)) {
            return null;
        }
        $c = (int) round((float) $v * 100);

        return $signed && $neg ? -$c : $c;
    }

    /** dd/mm/aaaa, dd/mm/aa, dd-mm-aaaa, aaaa-mm-dd ou número de série do Excel → AAAA-MM-DD ('' se inválida). */
    private function date(string $v): string
    {
        $v = trim($v);
        if (preg_match('/^(\d{1,2})[\/.-](\d{1,2})[\/.-](\d{2}|\d{4})$/', $v, $m)) {
            $y = strlen($m[3]) === 2 ? 2000 + (int) $m[3] : (int) $m[3];

            return checkdate((int) $m[2], (int) $m[1], $y) ? sprintf('%04d-%02d-%02d', $y, $m[2], $m[1]) : '';
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $v, $m)) {
            return $this->validDate("{$m[1]}-{$m[2]}-{$m[3]}");
        }
        if (preg_match('/^\d{5}(\.\d+)?$/', $v) && (float) $v > 30000 && (float) $v < 80000) {
            return CarbonImmutable::create(1899, 12, 30)->addDays((int) $v)->toDateString();   // série do Excel
        }

        return '';
    }

    private function validDate(string $d): string
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $d : '';
    }

    private function cleanDesc(string $d): string
    {
        $d = trim(preg_replace('/\s+/u', ' ', strip_tags($d)) ?? '');

        return mb_substr($d, 0, 180);
    }

    private function utf8(string $s): string
    {
        return mb_check_encoding($s, 'UTF-8') ? $s : mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
    }

    private function colIndex(string $ref): int
    {
        $letters = preg_replace('/\d/', '', $ref) ?: 'A';
        $n = 0;
        foreach (str_split(strtoupper($letters)) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }

        return $n - 1;
    }

    private function xml(string $s): \SimpleXMLElement
    {
        $prev = libxml_use_internal_errors(true);
        try {
            // sem entidades externas (XXE): o conteúdo vem do usuário
            $x = simplexml_load_string($s, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
            if ($x === false) {
                throw new BusinessRule('Arquivo do Excel inválido ou corrompido.', 'importacao');
            }

            return $x;
        } finally {
            libxml_use_internal_errors($prev);
        }
    }
}
