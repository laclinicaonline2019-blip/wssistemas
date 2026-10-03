<?php

namespace App\Modules\Reports\Services;

use App\Core\Support\Format;
use Dompdf\Dompdf;
use Dompdf\Options;

/** Exporta um relatório em CSV (Excel BR: ";" e BOM), XLSX ou PDF. */
class ReportExporter
{
    public const PDF_MAX_ROWS = 3000;

    public function __construct(private readonly XlsxWriter $xlsx) {}

    public function csv(Report $report): string
    {
        $out = fopen('php://temp', 'w+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_map(fn ($c) => $c[0], $report->columns), ';');
        foreach (array_merge($report->rows, $report->totals ? [$report->totals] : []) as $row) {
            fputcsv($out, array_map(fn ($k) => $this->csvValue($row[$k] ?? null, $report->columns[$k][1]), array_keys($report->columns)), ';');
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    public function xlsx(Report $report): string
    {
        $path = tempnam(sys_get_temp_dir(), 'rpt');
        try {
            $this->xlsx->write($report, $path);

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }
    }

    public function pdf(Report $report, array $meta): string
    {
        $html = view('reports.pdf', ['report' => $report, 'meta' => $meta, 'cell' => fn ($v, $t) => $this->display($v, $t), 'max' => self::PDF_MAX_ROWS])->render();
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('chroot', public_path());
        $pdf = new Dompdf($options);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('a4', count($report->columns) > 6 ? 'landscape' : 'portrait');
        $pdf->render();

        return $pdf->output();
    }

    /** Valor para a tela e o PDF. */
    public function display(mixed $v, string $type): string
    {
        if ($v === null || $v === '') {
            return '';
        }

        return match ($type) {
            'money' => Format::money((int) $v),
            'pct' => number_format((float) $v, 1, ',', '.').'%',
            'int' => number_format((int) $v, 0, ',', '.'),
            default => (string) $v,
        };
    }

    private function csvValue(mixed $v, string $type): string
    {
        if ($v === null) {
            return '';
        }

        return match ($type) {
            'money' => number_format(((int) $v) / 100, 2, ',', ''),
            'pct' => number_format((float) $v, 1, ',', ''),
            'int' => (string) (int) $v,
            default => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'".$v : (string) $v, // sem injeção de fórmula
        };
    }
}
