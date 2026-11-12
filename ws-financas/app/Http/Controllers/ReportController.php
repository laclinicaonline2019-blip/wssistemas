<?php

namespace App\Http\Controllers;

use App\Core\Audit\AuditLogger;
use App\Core\Support\BusinessRule;
use App\Core\Support\Months;
use App\Services\FinanceService;
use App\Services\ReportService;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Exportação de relatórios: CSV (Excel abre direto) e PDF. */
class ReportController extends Controller
{
    public function export(Request $request, ReportService $reports, FinanceService $finance, AuditLogger $audit): Response
    {
        $sel = (string) $request->query('empresa', '');
        $account = $request->attributes->get('account');
        if ($sel === 'todas' && ! $account->plan()?->has('consolidated')) {
            throw new BusinessRule('A visão consolidada está disponível nos planos Profissional e Empresarial.', 'plano');
        }
        $format = $request->query('formato') === 'pdf' ? 'pdf' : 'csv';
        if ($format === 'pdf' && ! $account->plan()?->has('advanced_reports')) {
            throw new BusinessRule('Relatórios em PDF estão disponíveis nos planos Profissional e Empresarial.', 'plano');
        }
        $companies = $finance->companies($sel ?: null);
        if ($sel === '') {
            $companies = $companies->take(1);
        }
        $from = Months::valid($request->query('de')) ? $request->query('de') : now()->format('Y-m');
        $to = Months::valid($request->query('ate')) && $request->query('ate') >= $from ? $request->query('ate') : $from;
        $r = $reports->period($companies, $from, $to);
        $audit->log('relatorio.exportar', null, ['formato' => $format, 'de' => $from, 'ate' => $to, 'empresas' => $companies->pluck('name')->all()]);
        $name = 'ws-financas-'.$from.($to !== $from ? '_a_'.$to : '');

        if ($format === 'csv') {
            return response()->streamDownload(function () use ($r) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF"); // BOM: acentos corretos no Excel
                fputcsv($out, ['Data', 'Empresa', 'Tipo', 'Descrição', 'Categoria', 'Valor', 'Situação', 'Pago em', 'Conta', 'Parcela/Fixa', 'Origem'], ';');
                foreach ($r['lancamentos'] as $l) {
                    fputcsv($out, [$l['data'], $l['empresa'], $l['tipo'], self::safe($l['descricao']), $l['categoria'], number_format($l['valor'], 2, ',', ''), $l['situacao'], $l['pago_em'], $l['conta'], $l['parcela'], $l['origem']], ';');
                }
                fclose($out);
            }, $name.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        $html = view('reports.pdf', ['r' => $r, 'companies' => $companies, 'account' => $account])->render();
        $pdf = new Dompdf((new Options)->set('isRemoteEnabled', false)->set('defaultFont', 'DejaVu Sans'));
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();

        return response($pdf->output(), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$name.'.pdf"']);
    }

    /** Evita injeção de fórmulas em planilhas (=, +, -, @). */
    private static function safe(string $v): string
    {
        return preg_match('/^[=+\-@\t\r]/', $v) ? "'".$v : $v;
    }
}
