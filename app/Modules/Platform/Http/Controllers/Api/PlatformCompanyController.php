<?php

namespace App\Modules\Platform\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Http\Requests\CompanyStoreRequest;
use App\Modules\Platform\Http\Resources\CompanyResource;
use App\Modules\Platform\Models\Company;
use App\Modules\Platform\Services\CompanyProvisioningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PlatformCompanyController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $companies = Company::query()->with('plan')
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($w) => $w->whereLike('trade_name', "%{$s}%")->orWhereLike('legal_name', "%{$s}%")->orWhere('document', preg_replace('/\D/', '', $s) ?: '-')))
            ->orderBy('trade_name')
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return CompanyResource::collection($companies);
    }

    public function show(Company $company): JsonResponse
    {
        return response()->json([
            'data' => new CompanyResource($company->load('plan')),
            'stats' => [
                'users' => DB::table('users')->where('company_id', $company->id)->whereNull('deleted_at')->count(),
                'branches' => DB::table('branches')->where('company_id', $company->id)->whereNull('deleted_at')->count(),
            ],
        ]);
    }

    public function store(CompanyStoreRequest $request, CompanyProvisioningService $service): JsonResponse
    {
        $result = self::provision($request, $service);

        return response()->json(['data' => new CompanyResource($result['company']->load('plan'))], 201);
    }

    public static function provision(CompanyStoreRequest $request, CompanyProvisioningService $service): array
    {
        $v = $request->validated();

        return $service->provision(
            company: [
                'legal_name' => $v['legal_name'], 'trade_name' => $v['trade_name'], 'document' => $v['document'],
                'email' => $v['email'] ?? null, 'phone' => $v['phone'] ?? null,
                'saas_plan_id' => $v['saas_plan_id'] ?? null, 'status' => $v['status'] ?? 'trial',
            ],
            headquarters: ['name' => $v['headquarters_name'], 'city' => $v['city'] ?? null, 'state' => isset($v['state']) ? strtoupper($v['state']) : null],
            admin: ['name' => $v['admin_name'], 'email' => $v['admin_email'], 'password' => $v['admin_password'], 'must_change_password' => true],
        );
    }

    /** Plano e status comercial (trial, ativa, suspensa, cancelada). */
    public function update(Request $request, Company $company): CompanyResource
    {
        return new CompanyResource(self::applyUpdate($request, $company)->load('plan'));
    }

    public static function applyUpdate(Request $request, Company $company): Company
    {
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(['trial', 'active', 'suspended', 'cancelled'])],
            'saas_plan_id' => ['sometimes', 'nullable', 'exists:saas_plans,id'],
            'trial_ends_at' => ['sometimes', 'nullable', 'date'],
            'legal_name' => ['sometimes', 'string', 'max:200'],
            'trade_name' => ['sometimes', 'string', 'max:200'],
        ]);

        $company->update($data);

        if (in_array($data['status'] ?? null, ['suspended', 'cancelled'], true)) {
            // Encerra sessões e tokens ativos da clínica.
            $userIds = DB::table('users')->where('company_id', $company->id)->pluck('id');
            DB::table('personal_access_tokens')->whereIn('tokenable_id', $userIds)->delete();
            DB::table('sessions')->whereIn('user_id', $userIds)->delete();
        }

        return $company;
    }
}
