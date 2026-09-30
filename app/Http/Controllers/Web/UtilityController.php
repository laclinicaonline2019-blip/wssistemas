<?php

namespace App\Http\Controllers\Web;

use App\Core\Support\CepLookup;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class UtilityController extends Controller
{
    public function cep(string $cep, CepLookup $lookup): JsonResponse
    {
        $address = $lookup->find($cep);

        return $address ? response()->json($address) : response()->json(['message' => 'CEP não encontrado.'], 404);
    }

    /** Busca global: cada bloco só aparece para quem tem a permissão correspondente. */
    public function search(Request $request, TenantContext $context): View
    {
        $q = trim((string) $request->validate(['q' => ['nullable', 'string', 'max:100']])['q'] ?? '');
        $user = $request->user();
        $results = ['patients' => collect(), 'doctors' => collect(), 'users' => collect()];

        if (mb_strlen($q) >= 2) {
            if ($user->hasPermission('paciente.visualizar')) {
                $results['patients'] = Patient::query()->search($q)->orderBy('search_name')->limit(15)->get();
            }

            if ($user->hasPermission('medico.visualizar')) {
                $results['doctors'] = Doctor::query()->with('specialties')
                    ->where(fn ($w) => $w->whereLike('name', "%{$q}%")->orWhereLike('social_name', "%{$q}%")->orWhere('crm', preg_replace('/\D/', '', $q) ?: '-'))
                    ->orderBy('name')->limit(10)->get();
            }

            if ($user->hasPermission('usuario.visualizar')) {
                $results['users'] = User::query()->manageableBy($context->allowedBranchIds())
                    ->where(fn ($w) => $w->whereLike('name', "%{$q}%")->orWhereLike('email', "%{$q}%"))
                    ->orderBy('name')->limit(10)->get();
            }
        }

        return view('search', ['q' => $q, 'results' => $results]);
    }
}
