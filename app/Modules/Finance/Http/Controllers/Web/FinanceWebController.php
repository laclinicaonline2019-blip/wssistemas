<?php

namespace App\Modules\Finance\Http\Controllers\Web;

use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\CashSession;
use App\Modules\Finance\Models\FinancialCategory;
use App\Modules\Finance\Models\FinancialTransaction;
use App\Modules\Finance\Models\Payable;
use App\Modules\Finance\Models\Receivable;
use App\Modules\Finance\Services\FinanceService;
use App\Modules\Organization\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Visão geral financeira, fluxo de caixa, exportação e plano de contas. */
class FinanceWebController extends Controller
{
    public function __construct(private readonly FinanceService $finance, private readonly TenantContext $context) {}

    public function overview(Request $request): View|StreamedResponse
    {
        $f = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'], 'branch_id' => ['nullable', 'string', 'size:26'], 'export' => ['nullable', Rule::in(['csv'])]]);
        $tz = 'America/Sao_Paulo';
        $from = $f['from'] ?? CarbonImmutable::now($tz)->startOfMonth()->toDateString();
        $to = $f['to'] ?? CarbonImmutable::now($tz)->toDateString();
        $branchId = $f['branch_id'] ?? null;
        $allowed = $this->context->allowedBranchIds();
        abort_if($branchId && $allowed !== null && ! in_array($branchId, $allowed, true), 403);

        if (($f['export'] ?? null) === 'csv') {
            return $this->export($from, $to, $branchId);
        }

        $today = CarbonImmutable::now($tz);
        $receivables = Receivable::query()->open()->accessibleBranches($allowed)->when($branchId, fn ($q, $b) => $q->where('branch_id', $b));
        $payables = Payable::query()->open()->accessibleBranches($allowed)->when($branchId, fn ($q, $b) => $q->where('branch_id', $b));

        return view('finance.overview', [
            'from' => $from, 'to' => $to, 'branchId' => $branchId,
            'flow' => $this->finance->cashFlow($from, $to, $branchId),
            'today' => $this->finance->cashFlow($today->toDateString(), $today->toDateString(), $branchId),
            'kpi' => [
                'receivable_open' => (clone $receivables)->sum(\DB::raw('amount_cents - discount_cents - paid_cents')),
                'receivable_overdue' => (clone $receivables)->where('due_date', '<', $today->toDateString())->sum(\DB::raw('amount_cents - discount_cents - paid_cents')),
                'receivable_overdue_count' => (clone $receivables)->where('due_date', '<', $today->toDateString())->count(),
                'payable_week' => (clone $payables)->whereBetween('due_date', [$today->toDateString(), $today->addDays(7)->toDateString()])->sum(\DB::raw('amount_cents - paid_cents')),
                'payable_overdue' => (clone $payables)->where('due_date', '<', $today->toDateString())->sum(\DB::raw('amount_cents - paid_cents')),
                'sessions_to_review' => CashSession::query()->accessibleBranches($allowed)->where('status', 'closed')->count(),
                'sessions_open' => CashSession::query()->accessibleBranches($allowed)->where('status', 'open')->count(),
            ],
            'branches' => Branch::query()->accessible($allowed)->orderByDesc('is_headquarters')->get(),
            'methods' => FinancialTransaction::METHODS,
        ]);
    }

    public function categories(): View
    {
        $this->finance->ensureCategories();

        return view('finance.categories', ['categories' => FinancialCategory::query()->orderBy('type')->orderBy('name')->get()]);
    }

    public function storeCategory(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(FinancialCategory::TYPES))],
            'name' => ['required', 'string', 'max:80', Rule::unique('financial_categories')->where('company_id', $this->context->companyId())->where('type', $request->input('type'))],
        ], [], ['name' => 'nome']);
        FinancialCategory::create($data);

        return back()->with('success', 'Categoria criada.');
    }

    public function toggleCategory(FinancialCategory $category): RedirectResponse
    {
        $category->update(['is_active' => ! $category->is_active]);

        return back()->with('success', $category->is_active ? 'Categoria reativada.' : 'Categoria desativada (lançamentos antigos são mantidos).');
    }

    /** CSV (separador ";", UTF-8 com BOM — abre direto no Excel). */
    private function export(string $from, string $to, ?string $branchId): StreamedResponse
    {
        $tz = 'America/Sao_Paulo';
        $start = CarbonImmutable::parse($from, $tz)->startOfDay()->utc();
        $end = CarbonImmutable::parse($to, $tz)->endOfDay()->utc();
        $categories = FinancialCategory::query()->pluck('name', 'id');

        // O arquivo é gerado depois que o middleware encerra o contexto da requisição: reabre o contexto da empresa.
        $companyId = $this->context->companyId();
        $allowed = $this->context->allowedBranchIds();

        return response()->streamDownload(fn () => $this->context->runFor($companyId, function () use ($start, $end, $branchId, $tz, $categories, $allowed) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Data', 'Tipo', 'Entrada/Saída', 'Forma', 'Valor', 'Categoria', 'Descrição', 'Unidade', 'Caixa', 'Usuário', 'Estorno de'], ';');

            FinancialTransaction::query()->with(['receivable:id,category_id', 'payable:id,category_id', 'creator:id,name'])
                ->when($allowed !== null, fn ($q) => $q->whereIn('branch_id', $allowed))
                ->whereBetween('occurred_at', [$start, $end])->when($branchId, fn ($q, $b) => $q->where('branch_id', $b))
                ->orderBy('occurred_at')->chunk(500, function ($rows) use ($out, $tz, $categories) {
                    $branches = Branch::query()->pluck('name', 'id');
                    foreach ($rows as $t) {
                        fputcsv($out, [
                            $t->occurred_at->timezone($tz)->format('d/m/Y H:i'), FinancialTransaction::KINDS[$t->kind], $t->direction === 'in' ? 'Entrada' : 'Saída',
                            $t->methodLabel(), number_format($t->signedCents() / 100, 2, ',', ''),
                            $categories[$t->receivable?->category_id ?? $t->payable?->category_id] ?? '', $this->safe($t->description),
                            $branches[$t->branch_id] ?? '', $t->cash_session_id ? 'sim' : '', $t->creator?->name, $t->reversal_of ?? '',
                        ], ';');
                    }
                });
            fclose($out);
        }), "financeiro-{$from}-a-{$to}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Evita injeção de fórmula ao abrir no Excel. */
    private function safe(?string $value): string
    {
        return $value !== null && preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : (string) $value;
    }
}
