<?php

namespace App\Modules\Platform\Services;

use Illuminate\Support\Facades\DB;

/** Indicadores agregados da plataforma (sem dados clínicos). */
class PlatformMetrics
{
    public function summary(): array
    {
        $byStatus = DB::table('companies')->whereNull('deleted_at')
            ->select('status', DB::raw('count(*) as total'))->groupBy('status')->pluck('total', 'status');

        $mrr = DB::table('companies as c')->join('saas_plans as p', 'p.id', '=', 'c.saas_plan_id')
            ->whereNull('c.deleted_at')->where('c.status', 'active')->sum('p.price_monthly_cents');

        return [
            'companies' => [
                'active' => (int) ($byStatus['active'] ?? 0),
                'trial' => (int) ($byStatus['trial'] ?? 0),
                'suspended' => (int) ($byStatus['suspended'] ?? 0),
                'cancelled' => (int) ($byStatus['cancelled'] ?? 0),
            ],
            'users' => DB::table('users')->whereNull('deleted_at')->whereNotNull('company_id')->count(),
            'branches' => DB::table('branches')->whereNull('deleted_at')->count(),
            'mrr_cents' => (int) $mrr,
            'failed_logins_24h' => DB::table('audit_logs')->whereIn('action', ['auth.login.failed', 'auth.account.locked'])
                ->where('created_at', '>=', now()->subDay())->count(),
            'failed_jobs' => DB::table('failed_jobs')->count(),
        ];
    }
}
