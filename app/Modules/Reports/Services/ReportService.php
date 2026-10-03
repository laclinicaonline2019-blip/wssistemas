<?php

namespace App\Modules\Reports\Services;

use App\Core\Support\Format;
use App\Core\Tenancy\TenantContext;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Finance\Models\FinancialCategory;
use App\Modules\Finance\Models\FinancialTransaction;
use App\Modules\Finance\Models\Receivable;
use App\Modules\Insurance\Models\Guide;
use App\Modules\Insurance\Models\Insurer;
use App\Modules\Scheduling\Models\Appointment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Relatórios (Fase 15). Cada relatório respeita a empresa (escopo do tenant) e as unidades que o
 * usuário pode ver. Agregações em PHP sobre consultas simples — mesmo resultado em MySQL/MariaDB
 * e PostgreSQL. Exportação limitada a MAX_ROWS linhas por arquivo.
 */
class ReportService
{
    public const MAX_ROWS = 20000;

    public const TZ = 'America/Sao_Paulo';

    /** chave → [título, grupo, permissão, descrição] */
    public const REPORTS = [
        'appointments' => ['Agendamentos', 'Operacional', 'relatorio.operacional', 'Consultas do período com situação, canal e forma de atendimento; taxa de faltas.'],
        'doctor_production' => ['Produção por médico', 'Operacional', 'relatorio.operacional', 'Atendidos, faltas e cancelamentos por médico, com receita particular e de convênio.'],
        'cash_by_category' => ['Receitas e despesas por categoria', 'Financeiro', 'relatorio.financeiro', 'Movimentações do livro financeiro (regime de caixa), já descontados os estornos.'],
        'receipts_by_method' => ['Recebimentos por forma de pagamento', 'Financeiro', 'relatorio.financeiro', 'Quantidade, total e ticket médio por forma de pagamento.'],
        'receivables_aging' => ['Contas a receber em aberto', 'Financeiro', 'relatorio.financeiro', 'Saldos em aberto por faixa de atraso (a vencer, 1–30, 31–60, 61–90, mais de 90 dias).'],
        'insurance_billing' => ['Faturamento por convênio', 'Convênios', 'relatorio.financeiro', 'Guias do período: apresentado, pago, glosado e a receber por operadora.'],
        'diagnoses' => ['Atendimentos por CID-10', 'Clínico', 'relatorio.clinico', 'Diagnósticos dos atendimentos finalizados — dados agregados, sem identificar pacientes.'],
    ];

    public function __construct(private readonly TenantContext $context) {}

    /** @param  array{from?: ?string, to?: ?string, branch_id?: ?string, doctor_id?: ?string}  $f */
    public function run(string $key, array $f): Report
    {
        $from = CarbonImmutable::parse($f['from'] ?? now(self::TZ)->startOfMonth()->toDateString(), self::TZ)->startOfDay();
        $to = CarbonImmutable::parse($f['to'] ?? now(self::TZ)->toDateString(), self::TZ)->endOfDay();

        return match ($key) {
            'appointments' => $this->appointments($from, $to, $f),
            'doctor_production' => $this->doctorProduction($from, $to, $f),
            'cash_by_category' => $this->cashByCategory($from, $to, $f),
            'receipts_by_method' => $this->receiptsByMethod($from, $to, $f),
            'receivables_aging' => $this->aging($f),
            'insurance_billing' => $this->insuranceBilling($from, $to, $f),
            'diagnoses' => $this->diagnoses($from, $to, $f),
        };
    }

    private function appointments(CarbonImmutable $from, CarbonImmutable $to, array $f): Report
    {
        $q = $this->branchScope(Appointment::query(), $f)->with(['patient:id,name,social_name,record_number', 'doctor:id,name,social_name', 'service:id,name', 'branch:id,name'])
            ->whereBetween('starts_at', [$from->utc(), $to->utc()])->when($f['doctor_id'] ?? null, fn ($q, $d) => $q->where('doctor_id', $d))->orderBy('starts_at');
        $total = (clone $q)->count();
        $rows = [];
        $status = [];
        foreach ($q->limit(self::MAX_ROWS)->get() as $a) {
            $status[$a->status] = ($status[$a->status] ?? 0) + 1;
            $rows[] = [
                'date' => $a->starts_at->timezone(self::TZ)->format('d/m/Y'), 'time' => $a->starts_at->timezone(self::TZ)->format('H:i'),
                'record' => $a->patient?->record_number, 'patient' => $a->patient?->displayName(), 'doctor' => $a->doctor?->displayName(),
                'service' => $a->service?->name, 'branch' => $a->branch?->name, 'payer' => $a->payer_type === 'insurance' ? 'Convênio' : 'Particular',
                'channel' => Appointment::CHANNELS[$a->channel] ?? $a->channel, 'status' => $a->statusLabel(), 'price' => $a->price_cents,
            ];
        }
        $attended = ($status['completed'] ?? 0) + ($status['in_service'] ?? 0) + ($status['arrived'] ?? 0);
        $noShow = $status['no_show'] ?? 0;

        return new Report('appointments', self::REPORTS['appointments'][0], [
            'date' => ['Data', 'date'], 'time' => ['Hora', 'text'], 'record' => ['Prontuário', 'int'], 'patient' => ['Paciente', 'text'], 'doctor' => ['Médico', 'text'],
            'service' => ['Atendimento', 'text'], 'branch' => ['Unidade', 'text'], 'payer' => ['Forma', 'text'], 'channel' => ['Canal', 'text'], 'status' => ['Situação', 'text'], 'price' => ['Valor', 'money'],
        ], $rows, [
            'Agendamentos' => (string) $total, 'Atendidos' => (string) $attended, 'Faltas' => (string) $noShow, 'Cancelados' => (string) ($status['cancelled'] ?? 0),
            'Taxa de faltas' => $this->pct($noShow, $attended + $noShow),
        ], null, 'Taxa de faltas = faltas ÷ (atendidos + faltas).', $total > self::MAX_ROWS);
    }

    private function doctorProduction(CarbonImmutable $from, CarbonImmutable $to, array $f): Report
    {
        $by = [];
        $apps = $this->branchScope(Appointment::query(), $f)->whereBetween('starts_at', [$from->utc(), $to->utc()])
            ->when($f['doctor_id'] ?? null, fn ($q, $d) => $q->where('doctor_id', $d))->get(['doctor_id', 'status']);
        foreach ($apps as $a) {
            $r = &$by[$a->doctor_id];
            $r['total'] = ($r['total'] ?? 0) + 1;
            $k = match ($a->status) {
                'completed', 'in_service', 'arrived' => 'attended', 'no_show' => 'no_show', 'cancelled' => 'cancelled', default => 'scheduled'
            };
            $r[$k] = ($r[$k] ?? 0) + 1;
            unset($r);
        }

        // Receita particular: recebimentos (− estornos) das contas do médico no período.
        $txns = $this->branchScope(FinancialTransaction::query(), $f)->whereBetween('occurred_at', [$from->utc(), $to->utc()])
            ->whereIn('kind', ['receipt', 'reversal'])->whereNotNull('receivable_id')->with('receivable:id,doctor_id,payer_type')->get();
        foreach ($txns as $t) {
            $doctorId = $t->receivable?->doctor_id;
            if (! $doctorId || (($f['doctor_id'] ?? null) && $doctorId !== $f['doctor_id'])) {
                continue;
            }
            $key = $t->receivable->payer_type === 'insurance' ? 'insurance_received' : 'private';
            $by[$doctorId][$key] = ($by[$doctorId][$key] ?? 0) + $t->signedCents();
        }
        // Convênio: valor apresentado nas guias (por data de atendimento).
        $guides = $this->branchScope(Guide::query(), $f)->whereBetween('attendance_date', [$from->toDateString(), $to->toDateString()])->where('status', '!=', 'cancelled')
            ->when($f['doctor_id'] ?? null, fn ($q, $d) => $q->where('doctor_id', $d))->get(['doctor_id', 'total_cents']);
        foreach ($guides as $g) {
            $by[$g->doctor_id]['insurance'] = ($by[$g->doctor_id]['insurance'] ?? 0) + $g->total_cents;
        }

        $names = Doctor::withTrashed()->whereIn('id', array_keys($by))->get()->mapWithKeys(fn ($d) => [$d->id => $d->displayName()]);
        $rows = collect($by)->map(fn ($r, $id) => [
            'doctor' => $names[$id] ?? '—', 'total' => $r['total'] ?? 0, 'attended' => $r['attended'] ?? 0, 'no_show' => $r['no_show'] ?? 0, 'cancelled' => $r['cancelled'] ?? 0,
            'no_show_rate' => ($r['attended'] ?? 0) + ($r['no_show'] ?? 0) ? round(100 * ($r['no_show'] ?? 0) / (($r['attended'] ?? 0) + ($r['no_show'] ?? 0)), 1) : 0,
            'private' => $r['private'] ?? 0, 'insurance' => $r['insurance'] ?? 0,
        ])->sortBy('doctor')->values()->all();
        $sum = fn ($k) => array_sum(array_column($rows, $k));

        return new Report('doctor_production', self::REPORTS['doctor_production'][0], [
            'doctor' => ['Médico', 'text'], 'total' => ['Agendados', 'int'], 'attended' => ['Atendidos', 'int'], 'no_show' => ['Faltas', 'int'], 'cancelled' => ['Cancelados', 'int'],
            'no_show_rate' => ['% faltas', 'pct'], 'private' => ['Recebido particular', 'money'], 'insurance' => ['Convênio apresentado', 'money'],
        ], $rows, ['Médicos' => (string) count($rows), 'Recebido particular' => Format::money($sum('private')), 'Convênio apresentado' => Format::money($sum('insurance'))],
            ['doctor' => 'Total', 'total' => $sum('total'), 'attended' => $sum('attended'), 'no_show' => $sum('no_show'), 'cancelled' => $sum('cancelled'), 'private' => $sum('private'), 'insurance' => $sum('insurance')]);
    }

    private function cashByCategory(CarbonImmutable $from, CarbonImmutable $to, array $f): Report
    {
        $categories = FinancialCategory::query()->get(['id', 'name', 'type'])->keyBy('id');
        $by = [];
        $txns = $this->branchScope(FinancialTransaction::query(), $f)->whereBetween('occurred_at', [$from->utc(), $to->utc()])
            ->with(['receivable:id,category_id', 'payable:id,category_id', 'original:id,receivable_id,payable_id,kind', 'original.receivable:id,category_id', 'original.payable:id,category_id'])->get();
        foreach ($txns as $t) {
            if (in_array($t->kind, ['withdrawal', 'deposit'], true)) {
                continue; // sangria/suprimento: movimento interno do caixa
            }
            $base = $t->kind === 'reversal' ? $t->original : $t;
            $categoryId = $base?->receivable?->category_id ?? $base?->payable?->category_id;
            $name = $categoryId ? ($categories[$categoryId]->name ?? '—') : (($base?->kind ?? $t->kind) === 'fee' ? 'Tarifas de gateway' : 'Sem categoria');
            $type = $categoryId ? ($categories[$categoryId]->type === 'income' ? 'Receita' : 'Despesa') : (($base?->direction ?? $t->direction) === 'in' ? 'Receita' : 'Despesa');
            $by[$type.'|'.$name] = ($by[$type.'|'.$name] ?? 0) + $t->signedCents();
        }
        ksort($by);
        $rows = collect($by)->map(fn ($v, $k) => ['type' => explode('|', $k)[0], 'category' => explode('|', $k)[1], 'amount' => $v])->values()->all();
        $income = array_sum(array_map(fn ($r) => $r['type'] === 'Receita' ? $r['amount'] : 0, $rows));
        $expense = array_sum(array_map(fn ($r) => $r['type'] === 'Despesa' ? $r['amount'] : 0, $rows));

        return new Report('cash_by_category', self::REPORTS['cash_by_category'][0], ['type' => ['Tipo', 'text'], 'category' => ['Categoria', 'text'], 'amount' => ['Valor', 'money']], $rows,
            ['Receitas' => Format::money($income), 'Despesas' => Format::money($expense), 'Resultado' => Format::money($income + $expense)],
            ['type' => 'Resultado', 'amount' => $income + $expense], 'Despesas aparecem negativas. Sangrias e suprimentos de caixa não entram.');
    }

    private function receiptsByMethod(CarbonImmutable $from, CarbonImmutable $to, array $f): Report
    {
        $by = [];
        $txns = $this->branchScope(FinancialTransaction::query(), $f)->whereBetween('occurred_at', [$from->utc(), $to->utc()])
            ->where(fn ($q) => $q->where('kind', 'receipt')->orWhere(fn ($r) => $r->where('kind', 'reversal')->where('direction', 'out')->whereHas('original', fn ($o) => $o->where('kind', 'receipt'))))
            ->get(['method', 'kind', 'direction', 'amount_cents']);
        foreach ($txns as $t) {
            $by[$t->method]['count'] = ($by[$t->method]['count'] ?? 0) + ($t->kind === 'receipt' ? 1 : 0);
            $by[$t->method]['total'] = ($by[$t->method]['total'] ?? 0) + $t->signedCents();
        }
        $rows = collect($by)->map(fn ($r, $m) => ['method' => FinancialTransaction::METHODS[$m] ?? $m, 'count' => $r['count'], 'total' => $r['total'],
            'avg' => $r['count'] ? intdiv($r['total'], $r['count']) : 0])->sortByDesc('total')->values()->all();
        $total = array_sum(array_column($rows, 'total'));
        foreach ($rows as &$r) {
            $r['share'] = $total ? round(100 * $r['total'] / $total, 1) : 0;
        }
        unset($r);

        return new Report('receipts_by_method', self::REPORTS['receipts_by_method'][0],
            ['method' => ['Forma', 'text'], 'count' => ['Recebimentos', 'int'], 'total' => ['Total (− estornos)', 'money'], 'avg' => ['Ticket médio', 'money'], 'share' => ['% do total', 'pct']],
            $rows, ['Total recebido' => Format::money($total)], ['method' => 'Total', 'count' => array_sum(array_column($rows, 'count')), 'total' => $total]);
    }

    private function aging(array $f): Report
    {
        $today = CarbonImmutable::now(self::TZ)->startOfDay();
        $buckets = ['A vencer' => 0, '1–30 dias' => 0, '31–60 dias' => 0, '61–90 dias' => 0, 'Mais de 90 dias' => 0];
        $rows = [];
        $q = $this->branchScope(Receivable::query(), $f)->with('patient:id,name,social_name,record_number')->whereIn('status', ['open', 'partial'])
            ->when($f['doctor_id'] ?? null, fn ($q, $d) => $q->where('doctor_id', $d))->orderBy('due_date');
        foreach ($q->limit(self::MAX_ROWS)->get() as $r) {
            $balance = $r->amount_cents - $r->discount_cents - $r->paid_cents;
            $days = (int) CarbonImmutable::parse($r->due_date->toDateString(), self::TZ)->diffInDays($today, false);
            $bucket = match (true) {
                $days <= 0 => 'A vencer', $days <= 30 => '1–30 dias', $days <= 60 => '31–60 dias', $days <= 90 => '61–90 dias', default => 'Mais de 90 dias'
            };
            $buckets[$bucket] += $balance;
            $rows[] = ['due' => $r->due_date->format('d/m/Y'), 'description' => $r->description, 'patient' => $r->patient?->displayName(),
                'payer' => $r->payer_type === 'insurance' ? 'Convênio' : 'Particular', 'days' => max(0, $days), 'bucket' => $bucket, 'balance' => $balance];
        }

        return new Report('receivables_aging', self::REPORTS['receivables_aging'][0], [
            'due' => ['Vencimento', 'date'], 'description' => ['Descrição', 'text'], 'patient' => ['Paciente', 'text'], 'payer' => ['Forma', 'text'],
            'days' => ['Dias em atraso', 'int'], 'bucket' => ['Faixa', 'text'], 'balance' => ['Saldo', 'money'],
        ], $rows, array_map(fn ($v) => Format::money($v), $buckets) + ['Total em aberto' => Format::money(array_sum($buckets))],
            ['description' => 'Total', 'balance' => array_sum($buckets)], 'Posição na data de hoje (o período não se aplica).');
    }

    private function insuranceBilling(CarbonImmutable $from, CarbonImmutable $to, array $f): Report
    {
        $by = [];
        $guides = $this->branchScope(Guide::query(), $f)->whereBetween('attendance_date', [$from->toDateString(), $to->toDateString()])->where('status', '!=', 'cancelled')
            ->when($f['doctor_id'] ?? null, fn ($q, $d) => $q->where('doctor_id', $d))->get(['insurer_id', 'status', 'total_cents', 'paid_cents', 'glosa_cents']);
        foreach ($guides as $g) {
            $r = &$by[$g->insurer_id];
            $r['guides'] = ($r['guides'] ?? 0) + 1;
            $r['billed'] = ($r['billed'] ?? 0) + (in_array($g->status, ['billed', 'paid', 'partial', 'denied'], true) ? 1 : 0);
            $r['total'] = ($r['total'] ?? 0) + $g->total_cents;
            $r['paid'] = ($r['paid'] ?? 0) + $g->paid_cents;
            $r['glosa'] = ($r['glosa'] ?? 0) + $g->glosa_cents;
            unset($r);
        }
        $names = Insurer::query()->whereIn('id', array_keys($by))->pluck('name', 'id');
        $rows = collect($by)->map(fn ($r, $id) => ['insurer' => $names[$id] ?? '—', 'guides' => $r['guides'], 'billed' => $r['billed'], 'total' => $r['total'], 'paid' => $r['paid'],
            'glosa' => $r['glosa'], 'open' => max(0, $r['total'] - $r['paid'] - $r['glosa']), 'glosa_rate' => $r['total'] ? round(100 * $r['glosa'] / $r['total'], 1) : 0])
            ->sortBy('insurer')->values()->all();
        $sum = fn ($k) => array_sum(array_column($rows, $k));

        return new Report('insurance_billing', self::REPORTS['insurance_billing'][0], [
            'insurer' => ['Operadora', 'text'], 'guides' => ['Guias', 'int'], 'billed' => ['Faturadas', 'int'], 'total' => ['Apresentado', 'money'], 'paid' => ['Pago', 'money'],
            'glosa' => ['Glosado', 'money'], 'open' => ['A receber', 'money'], 'glosa_rate' => ['% glosa', 'pct'],
        ], $rows, ['Apresentado' => Format::money($sum('total')), 'Pago' => Format::money($sum('paid')), 'Glosado' => Format::money($sum('glosa')), 'Índice de glosa' => $this->pct($sum('glosa'), $sum('total'))],
            ['insurer' => 'Total', 'guides' => $sum('guides'), 'billed' => $sum('billed'), 'total' => $sum('total'), 'paid' => $sum('paid'), 'glosa' => $sum('glosa'), 'open' => $sum('open')]);
    }

    private function diagnoses(CarbonImmutable $from, CarbonImmutable $to, array $f): Report
    {
        $allowed = $this->context->allowedBranchIds();
        $rows = DB::table('encounter_diagnoses as d')->join('encounters as e', fn ($j) => $j->on('e.id', '=', 'd.encounter_id')->on('e.current_version', '=', 'd.version'))
            ->where('e.company_id', $this->context->companyId())->where('e.status', 'finalized')->whereBetween('e.finalized_at', [$from->utc(), $to->utc()])
            ->when($allowed !== null, fn ($q) => $q->whereIn('e.branch_id', $allowed))->when($f['branch_id'] ?? null, fn ($q, $b) => $q->where('e.branch_id', $b))
            ->when($f['doctor_id'] ?? null, fn ($q, $d) => $q->where('e.doctor_id', $d))
            ->groupBy('d.code', 'd.description')->selectRaw('d.code, d.description, COUNT(DISTINCT d.encounter_id) AS encounters, SUM(CASE WHEN d.is_primary THEN 1 ELSE 0 END) AS primary_count')
            ->orderByDesc('encounters')->orderBy('d.code')->limit(500)->get()
            ->map(fn ($r) => ['code' => $r->code, 'description' => $r->description, 'encounters' => (int) $r->encounters, 'primary' => (int) $r->primary_count])->all();

        return new Report('diagnoses', self::REPORTS['diagnoses'][0], ['code' => ['CID-10', 'text'], 'description' => ['Descrição', 'text'], 'encounters' => ['Atendimentos', 'int'], 'primary' => ['Como principal', 'int']],
            $rows, ['Diagnósticos distintos' => (string) count($rows), 'Registros' => (string) array_sum(array_column($rows, 'encounters'))], null,
            'Somente dados agregados (sem identificação de pacientes), da versão vigente de cada prontuário finalizado.');
    }

    /** Limita às unidades do usuário e ao filtro de unidade. */
    private function branchScope($query, array $f)
    {
        $allowed = $this->context->allowedBranchIds();

        return $query->when($allowed !== null, fn ($q) => $q->whereIn('branch_id', $allowed))->when($f['branch_id'] ?? null, fn ($q, $b) => $q->where('branch_id', $b));
    }

    private function pct(int $part, int $whole): string
    {
        return $whole ? number_format(100 * $part / $whole, 1, ',', '.').'%' : '—';
    }
}
