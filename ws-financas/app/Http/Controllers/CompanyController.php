<?php

namespace App\Http\Controllers;

use App\Core\Audit\AuditLogger;
use App\Core\Support\Money;
use App\Core\Validation\Document;
use App\Models\BankAccount;
use App\Models\Company;
use App\Services\AccountService;
use App\Services\Billing\BillingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CompanyController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): View
    {
        return view('settings.companies', ['list' => Company::query()->with('bankAccounts')->orderByDesc('active')->orderBy('name')->get()]);
    }

    public function store(Request $request, AccountService $accounts): RedirectResponse
    {
        $d = $request->validate(['name' => ['required', 'string', 'max:160'], 'alias' => ['nullable', 'string', 'max:60'], 'person_type' => ['required', 'in:pf,pj'],
            'document' => ['nullable', 'string', 'max:20', new Document]]);
        $d['document'] = ! empty($d['document']) ? preg_replace('/\D/', '', $d['document']) : null;
        $accounts->createCompany($d);

        return back()->with('ok', 'Empresa cadastrada.');
    }

    public function update(Request $request, Company $company, BillingService $billing): RedirectResponse
    {
        $d = $request->validate(['name' => ['required', 'string', 'max:160'], 'alias' => ['nullable', 'string', 'max:60'], 'document' => ['nullable', 'string', 'max:20', new Document],
            'active' => ['boolean']]);
        $active = $request->boolean('active');
        if ($active && ! $company->active) {
            $billing->assertCanAdd($request->attributes->get('account'), 'company');
        }
        if (! $active && Company::query()->where('active', true)->count() <= 1 && $company->active) {
            return back()->with('error', 'É preciso manter pelo menos uma empresa ativa.');
        }
        $company->update(['name' => $d['name'], 'alias' => ($d['alias'] ?? null) ?: null, 'document' => ! empty($d['document']) ? preg_replace('/\D/', '', $d['document']) : null, 'active' => $active]);
        $this->audit->log('empresa.editar', $company, ['nome' => $company->name, 'ativa' => $active]);

        return back()->with('ok', 'Empresa atualizada.');
    }

    public function storeBank(Request $request, Company $company): RedirectResponse
    {
        $d = $request->validate(['name' => ['required', 'string', 'max:120'], 'bank' => ['nullable', 'string', 'max:120'], 'opening_balance' => ['nullable', 'string', 'max:20'],
            'opening_date' => ['nullable', 'date']]);
        $b = BankAccount::query()->create(['company_id' => $company->id, 'name' => $d['name'], 'bank' => $d['bank'] ?? null,
            'opening_balance_cents' => $this->signed($d['opening_balance'] ?? '0'), 'opening_date' => $d['opening_date'] ?? null, 'active' => true]);
        $this->audit->log('conta_bancaria.criar', $b, ['nome' => $b->name]);

        return back()->with('ok', 'Conta bancária cadastrada.');
    }

    public function updateBank(Request $request, BankAccount $bankAccount): RedirectResponse
    {
        $d = $request->validate(['name' => ['required', 'string', 'max:120'], 'bank' => ['nullable', 'string', 'max:120'], 'opening_balance' => ['nullable', 'string', 'max:20'], 'active' => ['boolean']]);
        $bankAccount->update(['name' => $d['name'], 'bank' => $d['bank'] ?? null, 'opening_balance_cents' => $this->signed($d['opening_balance'] ?? '0'), 'active' => $request->boolean('active')]);
        $this->audit->log('conta_bancaria.editar', $bankAccount, ['nome' => $bankAccount->name]);

        return back()->with('ok', 'Conta bancária atualizada.');
    }

    private function signed(string $v): int
    {
        $neg = str_starts_with(trim($v), '-');

        return (Money::parse(ltrim(trim($v), '-')) ?? 0) * ($neg ? -1 : 1);
    }
}
