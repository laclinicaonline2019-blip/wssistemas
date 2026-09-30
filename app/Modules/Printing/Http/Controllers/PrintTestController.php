<?php

namespace App\Modules\Printing\Http\Controllers;

use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Modules\Organization\Models\Branch;
use App\Modules\Platform\Models\Company;
use Illuminate\View\View;

/**
 * Página de teste de impressão (A4 e térmica 58/80 mm).
 * Valida margens, cabeçalho/rodapé e a impressora antes do uso real com
 * receitas, atestados, senhas e comprovantes (Fases 4, 6 e 7).
 */
class PrintTestController extends Controller
{
    public function __invoke(string $format, TenantContext $context): View
    {
        abort_unless(in_array($format, ['a4', 'thermal'], true), 404);

        $company = Company::findOrFail($context->companyId());
        $branch = $context->branchId() ? Branch::find($context->branchId()) : Branch::query()->where('is_headquarters', true)->first();

        return view($format === 'a4' ? 'print.test-a4' : 'print.test-thermal', [
            'company' => $company,
            'branch' => $branch,
            'width' => (int) $company->setting('print.thermal_width_mm', 80),
        ]);
    }
}
