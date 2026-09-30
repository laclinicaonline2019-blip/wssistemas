<?php

namespace App\Modules\Audit\Http\Controllers\Web;

use App\Core\Audit\AuditLogger;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Modules\Audit\Http\Controllers\Api\AuditLogController;
use App\Modules\Identity\Models\User;
use App\Modules\Organization\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditWebController extends Controller
{
    public function index(Request $request, TenantContext $context): View
    {
        $filters = AuditLogController::validateFilters($request);

        $logs = AuditLogController::query($context)->filter($filters)->with('user:id,name')
            ->orderByDesc('id')->paginate(50)->withQueryString();

        return view('audit.index', [
            'logs' => $logs,
            'filters' => $filters,
            'users' => User::query()->manageableBy($context->allowedBranchIds())->orderBy('name')->get(['id', 'name']),
            'branches' => Branch::query()->accessible($context->allowedBranchIds())->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /** Exportação CSV (auditada). */
    public function export(Request $request, TenantContext $context, AuditLogger $audit): StreamedResponse
    {
        $filters = AuditLogController::validateFilters($request);
        $audit->record('audit.exported', metadata: ['filters' => $filters]);

        $query = AuditLogController::query($context)->filter($filters)->with('user:id,name')->orderByDesc('id');
        $companyId = $context->companyId();

        // O download é transmitido após o fim do middleware: reabre o contexto do tenant.
        return response()->streamDownload(fn () => $context->runFor($companyId, function () use ($query) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['id', 'data_hora', 'usuario', 'acao', 'resultado', 'registro', 'id_registro', 'ip', 'valores_anteriores', 'valores_novos', 'metadata'], ';');

            $query->chunk(1000, function ($rows) use ($out) {
                foreach ($rows as $log) {
                    fputcsv($out, [
                        $log->id, $log->created_at?->format('Y-m-d H:i:s'), $log->user?->name ?? $log->actor_type,
                        $log->action, $log->result, $log->auditable_type, $log->auditable_id, $log->ip_address,
                        json_encode($log->old_values, JSON_UNESCAPED_UNICODE), json_encode($log->new_values, JSON_UNESCAPED_UNICODE),
                        json_encode($log->metadata, JSON_UNESCAPED_UNICODE),
                    ], ';');
                }
            });

            fclose($out);
        }), 'auditoria-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
