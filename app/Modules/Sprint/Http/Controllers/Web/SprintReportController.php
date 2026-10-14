<?php

namespace App\Modules\Sprint\Http\Controllers\Web;

use App\Core\Support\Format;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Modules\Sprint\Models\SprintInvoice;
use App\Modules\Sprint\Models\SprintProfessional;
use App\Modules\Sprint\Models\SprintSale;
use App\Modules\Sprint\Models\SprintSplit;
use App\Modules\Sprint\Services\SprintService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Relatórios de repasse (por profissional), valores da clínica, comissão, tarifas e NFS-e. */
class SprintReportController extends Controller
{
    public function __construct(private readonly SprintService $sprint, private readonly TenantContext $context) {}

    public function index(Request $request): View
    {
        [$from, $to, $start, $end] = $this->period($request);
        $saleIds = SprintSale::query()->accessibleBranches($this->context->allowedBranchIds())->select('id');
        $base = fn () => SprintSplit::query()->whereIn('sale_id', $saleIds)->where('status', '!=', 'reversed')->whereBetween('created_at', [$start, $end]);

        $byProfessional = $base()->where('party', 'professional')
            ->selectRaw('professional_id, mode, status, SUM(amount_cents) AS total, COUNT(DISTINCT sale_id) AS sales')
            ->groupBy('professional_id', 'mode', 'status')->get()->groupBy('professional_id');
        $parties = $base()->selectRaw('party, mode, status, SUM(amount_cents) AS total')->groupBy('party', 'mode', 'status')->get();
        $sum = fn (string $party, ?callable $filter = null) => (int) $parties->where('party', $party)->filter($filter ?? fn () => true)->sum('total');

        $invoices = SprintInvoice::query()->whereIn('sale_id', $saleIds)->where('status', 'authorized')->whereBetween('issued_at', [$start, $end]);

        return view('sprint.reports', [
            'from' => $from, 'to' => $to,
            'professionals' => SprintProfessional::query()->whereIn('id', $byProfessional->keys())->orderBy('name')->get()->keyBy('id'),
            'byProfessional' => $byProfessional,
            'totals' => [
                'gross' => $sum('professional') + $sum('house') + $sum('platform') + $sum('fee'),
                'professional' => $sum('professional'),
                'professional_native' => $sum('professional', fn ($r) => $r->mode === 'native'),
                'professional_pending' => $sum('professional', fn ($r) => $r->status === 'pending'),
                'house' => $sum('house'),
                'platform' => $sum('platform'),
                'platform_native' => $sum('platform', fn ($r) => $r->mode === 'native'),
                'fee' => $sum('fee'),
                'nfse_cents' => (int) (clone $invoices)->sum('value_cents'),
                'nfse_count' => (clone $invoices)->count(),
            ],
            'methods' => SprintSplit::query()->whereIn('sprint_splits.sale_id', $saleIds)->where('sprint_splits.status', '!=', 'reversed')
                ->whereBetween('sprint_splits.created_at', [$start, $end])->where('party', 'house')
                ->join('sprint_payments as p', 'p.id', '=', 'sprint_splits.payment_id')
                ->selectRaw('p.method, p.channel, SUM(sprint_splits.amount_cents) AS house, COUNT(*) AS qty')->groupBy('p.method', 'p.channel')->get(),
        ]);
    }

    public function settle(Request $request, SprintProfessional $professional): RedirectResponse
    {
        $f = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from']]);
        $payable = $this->sprint->settle($request->user(), $professional, $f['from'], $f['to']);

        return redirect()->route('payables.show', $payable)->with('success', 'Repasse fechado: conta a pagar de '.Format::money($payable->amount_cents).' gerada para '.$professional->name.'.');
    }

    /** Extrato da divisão em CSV (Excel abre direto: separador ";" e BOM UTF-8). */
    public function export(Request $request): StreamedResponse
    {
        [$from, $to, $start, $end] = $this->period($request);
        $tz = 'America/Sao_Paulo';
        $rows = SprintSplit::query()->with(['sale:id,number,description,patient_id,customer_name', 'sale.patient:id,name,social_name', 'professional:id,name'])
            ->whereIn('sale_id', SprintSale::query()->accessibleBranches($this->context->allowedBranchIds())->select('id'))
            ->whereBetween('created_at', [$start, $end])->orderBy('created_at')->get();

        return response()->streamDownload(function () use ($rows, $tz) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Data', 'Venda', 'Cliente', 'Descrição', 'Parte', 'Profissional', 'Valor (R$)', 'Tipo', 'Situação'], ';');
            foreach ($rows as $s) {
                fputcsv($out, [
                    $s->created_at->timezone($tz)->format('d/m/Y H:i'), $s->sale->code(), $s->sale->customerLabel(), $s->sale->description,
                    SprintSplit::PARTIES[$s->party], $s->professional?->name, number_format($s->amount_cents / 100, 2, ',', ''),
                    $s->mode === 'native' ? 'Separado pelo gateway' : 'Interno', SprintSplit::STATUSES[$s->status],
                ], ';');
            }
            fclose($out);
        }, "melhor-sprint-{$from}-a-{$to}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array{0: string, 1: string, 2: CarbonImmutable, 3: CarbonImmutable} */
    private function period(Request $request): array
    {
        $f = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        $tz = 'America/Sao_Paulo';
        $from = $f['from'] ?? CarbonImmutable::now($tz)->startOfMonth()->toDateString();
        $to = $f['to'] ?? CarbonImmutable::now($tz)->toDateString();

        return [$from, $to, CarbonImmutable::parse($from, $tz)->startOfDay()->utc(), CarbonImmutable::parse($to, $tz)->endOfDay()->utc()];
    }
}
