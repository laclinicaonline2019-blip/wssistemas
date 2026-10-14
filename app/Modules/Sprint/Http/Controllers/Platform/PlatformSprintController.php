<?php

namespace App\Modules\Sprint\Http\Controllers\Platform;

use App\Core\Support\Format;
use App\Http\Controllers\Controller;
use App\Modules\Platform\Models\Company;
use App\Modules\Sprint\Services\CommissionService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Super Admin: comissão da plataforma sobre as vendas do Melhor Sprint de cada clínica. */
class PlatformSprintController extends Controller
{
    public function __construct(private readonly CommissionService $commission) {}

    public function index(Request $request): View
    {
        $f = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        $tz = 'America/Sao_Paulo';
        $from = $f['from'] ?? CarbonImmutable::now($tz)->startOfMonth()->toDateString();
        $to = $f['to'] ?? CarbonImmutable::now($tz)->toDateString();

        return view('platform.sprint', [
            'from' => $from, 'to' => $to,
            'rows' => $this->commission->summary($from, $to),
            'volume' => $this->commission->volume($from, $to),
            'companies' => Company::query()->orderBy('trade_name')->get(['id', 'trade_name', 'sprint_commission_bp']),
            'defaultBp' => (int) config('sprint.commission_bp'),
            'wallet' => $this->commission->platformWallet(),
            'subordinate' => $this->commission->platformSubordinate(),
        ]);
    }

    public function rate(Request $request, Company $company): RedirectResponse
    {
        $raw = $request->validate(['rate' => ['nullable', 'string', 'max:10']])['rate'] ?? null;
        $bp = $raw === null || $raw === '' ? null : Format::parseMoney($raw);
        if ($raw !== null && $raw !== '' && $bp === null) {
            throw ValidationException::withMessages(['rate' => 'Percentual inválido.']);
        }
        $this->commission->setRate($company, $bp);

        return back()->with('success', 'Comissão de '.$company->trade_name.': '.($bp === null ? 'taxa padrão' : number_format($bp / 100, 2, ',', '.').'%').' (vale para as próximas vendas).');
    }

    public function invoice(Request $request, Company $company): RedirectResponse
    {
        $f = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from']]);
        $total = $this->commission->markInvoiced($company->id, $f['from'], $f['to']);

        return back()->with('success', 'Comissão de '.Format::money($total).' marcada como cobrada de '.$company->trade_name.'.');
    }
}
