<?php

namespace App\Modules\Reports\Services;

use RuntimeException;
use ZipArchive;

/**
 * Planilha Excel (.xlsx / Office Open XML) mínima, sem dependências: uma aba, cabeçalho em
 * negrito, números como número (dinheiro com 2 casas, percentual) e texto como texto
 * (inlineStr — protegido contra fórmulas, pois nunca é gravado como fórmula).
 */
class XlsxWriter
{
    public function write(Report $report, string $path): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Não foi possível gerar a planilha.');
        }

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="'.$this->esc(mb_substr(preg_replace('/[\[\]\*\?\/\\\\:]/', ' ', $report->title), 0, 31)).'" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        // Estilos: 0 normal, 1 cabeçalho (negrito), 2 dinheiro, 3 percentual, 4 total dinheiro (negrito), 5 total texto (negrito).
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.00"/></numFmts>'
            .'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            .'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="6"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            .'<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="10" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="164" fontId="1" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs></styleSheet>');

        $keys = array_keys($report->columns);
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        $r = 1;
        $xml .= '<row r="1">'.implode('', array_map(fn ($k, $i) => $this->str($i, 1, $report->columns[$k][0], 1), $keys, array_keys($keys))).'</row>';
        foreach ($report->rows as $row) {
            $r++;
            $xml .= '<row r="'.$r.'">';
            foreach ($keys as $i => $k) {
                $xml .= $this->cell($i, $r, $row[$k] ?? null, $report->columns[$k][1], false);
            }
            $xml .= '</row>';
        }
        if ($report->totals) {
            $r++;
            $xml .= '<row r="'.$r.'">';
            foreach ($keys as $i => $k) {
                $xml .= $this->cell($i, $r, $report->totals[$k] ?? null, $report->columns[$k][1], true);
            }
            $xml .= '</row>';
        }
        $xml .= '</sheetData></worksheet>';
        $zip->addFromString('xl/worksheets/sheet1.xml', $xml);
        $zip->close();
    }

    private function cell(int $col, int $row, mixed $v, string $type, bool $bold): string
    {
        if ($v === null || $v === '') {
            return '';
        }
        $ref = $this->col($col).$row;

        return match ($type) {
            'money' => '<c r="'.$ref.'" s="'.($bold ? 4 : 2).'"><v>'.number_format(((int) $v) / 100, 2, '.', '').'</v></c>',
            'int' => '<c r="'.$ref.'"'.($bold ? ' s="5"' : '').'><v>'.(int) $v.'</v></c>',
            'pct' => '<c r="'.$ref.'" s="3"><v>'.round((float) $v / 100, 4).'</v></c>',
            default => $this->str($col, $row, (string) $v, $bold ? 5 : 0),
        };
    }

    private function str(int $col, int $row, string $v, int $style): string
    {
        return '<c r="'.$this->col($col).$row.'" t="inlineStr"'.($style ? ' s="'.$style.'"' : '').'><is><t xml:space="preserve">'.$this->esc($v).'</t></is></c>';
    }

    private function col(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
            $s = chr(65 + ($i - 1) % 26).$s;
        }

        return $s;
    }

    private function esc(string $v): string
    {
        // Remove caracteres de controle inválidos em XML.
        return htmlspecialchars(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $v), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
