<?php

namespace App\Modules\Audit\Http\Controllers\Api;

use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Modules\Audit\Http\Resources\AuditLogResource;
use App\Modules\Audit\Models\AuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AuditLogController extends Controller
{
    public function index(Request $request, TenantContext $context): AnonymousResourceCollection
    {
        $filters = self::validateFilters($request);

        $logs = self::query($context)->filter($filters)->with('user:id,name')
            ->orderByDesc('id')
            ->paginate(min((int) ($filters['per_page'] ?? 50), 200));

        return AuditLogResource::collection($logs);
    }

    public static function validateFilters(Request $request): array
    {
        return $request->validate([
            'user_id' => ['nullable', 'string', 'size:26'],
            'branch_id' => ['nullable', 'string', 'size:26'],
            'action' => ['nullable', 'string', 'max:100'],
            'auditable_type' => ['nullable', 'string', 'max:100'],
            'auditable_id' => ['nullable', 'string', 'max:64'],
            'result' => ['nullable', 'in:success,failure,denied'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);
    }

    /** Usuário restrito a filiais só vê eventos das suas filiais. */
    public static function query(TenantContext $context): Builder
    {
        $allowed = $context->allowedBranchIds();

        return AuditLog::query()->when($allowed !== null, fn ($q) => $q->whereIn('branch_id', $allowed));
    }
}
