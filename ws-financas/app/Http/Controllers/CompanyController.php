<?php

namespace App\Http\Controllers;

use App\Core\Audit\AuditLogger;
use App\Core\Support\Money;
use App\Core\Validation\Document;
use App\Models\BankAccount;
use App\Models\Budget;
use App\Models\CompanyCategory;
use App\Models\CostCenter;
use Illuminate\Validation\Rule;
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
        return view('settings.companies', ['list' => Company::query()->with(['bankAccounts', 'costCenters' => fn ($q) => $q->orderBy('name'), 'customCategories' => fn ($q) => $q->orderBy('name'), 'budgets'])
            ->orderByDesc('active')->orderBy('name')->get()]);
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
            $billing->assertCanAdd($request->attributes->get('account'), 'company', $company->person_type);
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

    // ------------------------------------------------------------ 1.1.7: centros de custo, categorias e metas por entidade

    public function storeCostCenter(Request $request, Company $company): RedirectResponse
    {
        $d = $request->validate(['name' => ['required', 'string', 'max:80']]);
        $name = trim(strip_tags($d['name']));
        if (CostCenter::query()->where('company_id', $company->id)->where('name', $name)->exists()) {
            return back()->with('error', 'Já existe um centro de custo com esse nome nesta empresa.');
        }
        $cc = CostCenter::query()->create(['company_id' => $company->id, 'name' => $name, 'active' => true]);
        $this->audit->log('centro_custo.criar', $cc, ['nome' => $name, 'empresa' => $company->name]);

        return back()->with('ok', 'Centro de custo cadastrado.');
    }

    public function updateCostCenter(Request $request, CostCenter $costCenter): RedirectResponse
    {
        $d = $request->validate(['name' => ['required', 'string', 'max:80'], 'active' => ['boolean']]);
        $name = trim(strip_tags($d['name']));
        if (CostCenter::query()->where('company_id', $costCenter->company_id)->where('name', $name)->whereKeyNot($costCenter->id)->exists()) {
            return back()->with('error', 'Já existe um centro de custo com esse nome nesta empresa.');
        }
        $costCenter->update(['name' => $name, 'active' => $request->boolean('active')]);
        $this->audit->log('centro_custo.editar', $costCenter, ['nome' => $name, 'ativo' => $costCenter->active]);

        return back()->with('ok', 'Centro de custo atualizado.');
    }

    public function storeCategory(Request $request, Company $company): RedirectResponse
    {
        $d = $request->validate(['name' => ['required', 'string', 'max:60'], 'type' => ['required', 'in:despesa,receita']]);
        $name = trim(strip_tags($d['name']));
        if (in_array(mb_strtolower($name), array_map('mb_strtolower', $company->categories($d['type'])), true)) {
            return back()->with('error', 'Essa categoria já existe para esta empresa.');
        }
        $cat = CompanyCategory::query()->firstOrNew(['company_id' => $company->id, 'type' => $d['type'], 'name' => $name]);
        $cat->active = true;
        $cat->save();
        $this->audit->log('categoria.criar', $cat, ['nome' => $name, 'tipo' => $d['type']]);

        return back()->with('ok', 'Categoria cadastrada.');
    }

    public function updateCategory(Request $request, CompanyCategory $category): RedirectResponse
    {
        $category->update(['active' => $request->boolean('active')]);
        $this->audit->log('categoria.editar', $category, ['nome' => $category->name, 'ativa' => $category->active]);

        return back()->with('ok', $category->active ? 'Categoria reativada.' : 'Categoria desativada (lançamentos antigos continuam com ela).');
    }

    /** Metas de gasto: total mensal, total semanal e (opcional) por categoria. Valor vazio ou zero remove a meta. */
    public function saveBudgets(Request $request, Company $company): RedirectResponse
    {
        $request->validate(['mes' => ['nullable', 'string', 'max:20'], 'semana' => ['nullable', 'string', 'max:20'],
            'cat' => ['nullable', 'string', 'max:60'], 'cat_period' => ['nullable', Rule::in(array_keys(Budget::PERIODS))], 'cat_valor' => ['nullable', 'string', 'max:20']]);
        $set = function (string $period, string $category, ?string $value) use ($company) {
            if ($value === null) {
                return;
            }
            $cents = Money::parse(trim($value));
            $q = Budget::query()->where('company_id', $company->id)->where('period', $period)->where('category', $category);
            if (! $cents) {
                $q->delete();

                return;
            }
            $b = $q->first() ?? new Budget(['company_id' => $company->id, 'period' => $period, 'category' => $category]);
            $b->amount_cents = $cents;
            $b->save();
        };
        $set('mes', '', $request->input('mes'));
        $set('semana', '', $request->input('semana'));
        if ($request->filled('cat') && $request->filled('cat_period')) {
            $cat = (string) $request->input('cat');
            if (! in_array($cat, $company->categories('despesa'), true)) {
                return back()->with('error', 'Categoria inválida para esta empresa.');
            }
            $set($request->input('cat_period'), $cat, (string) $request->input('cat_valor', ''));
        }
        $this->audit->log('meta.salvar', $company, ['metas' => Budget::query()->where('company_id', $company->id)->get(['period', 'category', 'amount_cents'])->toArray()]);

        return back()->with('ok', 'Metas de gasto salvas.');
    }

    private function signed(string $v): int
    {
        $neg = str_starts_with(trim($v), '-');

        return (Money::parse(ltrim(trim($v), '-')) ?? 0) * ($neg ? -1 : 1);
    }
}
