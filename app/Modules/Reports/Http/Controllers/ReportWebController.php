<?php

namespace App\Modules\Reports\Http\Controllers;

use App\Core\Audit\AuditLogger;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Organization\Models\Branch;
use App\Modules\Platform\Models\Company;
use App\Modules\Reports\Services\ReportExporter;
use App\Modules\Reports\Services\ReportService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/** Central de relatórios (permissões relatorio.operacional / financeiro / clinico). */
class ReportWebController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        return view('reports.index', [
            'groups' => collect(ReportService::REPORTS)->filter(fn ($r) => $user->hasPermission($r[2]))->groupBy(fn ($r) => $r[1], preserveKeys: true),
            'canClosing' => $user->hasPermission('financeiro.fechamento'),
        ]);
    }

    public function show(Request $request, string $key, ReportService $reports, ReportExporter $exporter, AuditLogger $audit): Response
    {
        abort_unless(isset(ReportService::REPORTS[$key]), 404);
        abort_unless($request->user()->hasPermission(ReportService::REPORTS[$key][2]), 403);
        $f = $request->validate([
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'], 'branch_id' => ['nullable', 'string', 'size:26'],
            'doctor_id' => ['nullable', 'string', 'size:26'], 'format' => ['nullable', Rule::in(['csv', 'xlsx', 'pdf'])],
        ]);
        $f['from'] ??= now(ReportService::TZ)->startOfMonth()->toDateString();
        $f['to'] ??= now(ReportService::TZ)->toDateString();
        if (CarbonImmutable::parse($f['from'])->diffInDays(CarbonImmutable::parse($f['to'])) > 400) {
            return back()->with('error', 'Período máximo de 400 dias por relatório.');
        }
        $report = $reports->run($key, $f);

        if ($format = $f['format'] ?? null) {
            $audit->record('report.exported', null, metadata: ['report' => $key, 'format' => $format, 'rows' => count($report->rows)] + array_intersect_key($f, array_flip(['from', 'to', 'branch_id', 'doctor_id'])));
            $name = $key.'-'.$f['from'].'-a-'.$f['to'].'.'.$format;
            $meta = ['clinic' => Company::query()->find($request->user()->company_id)?->trade_name, 'from' => $f['from'], 'to' => $f['to'], 'user' => $request->user()->name,
                'branch' => ! empty($f['branch_id']) ? Branch::query()->find($f['branch_id'])?->name : null, 'doctor' => ! empty($f['doctor_id']) ? Doctor::query()->find($f['doctor_id'])?->displayName() : null];

            return match ($format) {
                'csv' => response($exporter->csv($report), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="'.$name.'"', 'Cache-Control' => 'no-store, private']),
                'xlsx' => response($exporter->xlsx($report), 200, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Content-Disposition' => 'attachment; filename="'.$name.'"', 'Cache-Control' => 'no-store, private']),
                'pdf' => response($exporter->pdf($report, $meta), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$name.'"', 'Cache-Control' => 'no-store, private']),
            };
        }

        return response()->view('reports.show', [
            'key' => $key, 'info' => ReportService::REPORTS[$key], 'report' => $report, 'f' => $f, 'exporter' => $exporter,
            'branches' => Branch::query()->accessible(app(TenantContext::class)->allowedBranchIds())->orderBy('name')->get(['id', 'name']),
            'doctors' => Doctor::query()->orderBy('name')->get(['id', 'name', 'social_name']),
        ]);
    }
}
