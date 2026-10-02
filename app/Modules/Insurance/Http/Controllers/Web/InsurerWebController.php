<?php

namespace App\Modules\Insurance\Http\Controllers\Web;

use App\Core\Audit\AuditLogger;
use App\Core\Support\Format;
use App\Core\Validation\ExistsInTenant;
use App\Http\Controllers\Controller;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Finance\Http\ParsesMoney;
use App\Modules\Insurance\Models\Batch;
use App\Modules\Insurance\Models\Guide;
use App\Modules\Insurance\Models\InsurancePlan;
use App\Modules\Insurance\Models\Insurer;
use App\Modules\Insurance\Models\PriceItem;
use App\Modules\Insurance\Models\PriceTable;
use App\Modules\Insurance\Models\Procedure;
use App\Modules\Insurance\Services\InsuranceCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Cadastro de convênios, planos, credenciamento, procedimentos (TUSS) e tabelas de valores. */
class InsurerWebController extends Controller
{
    use ParsesMoney;

    public function __construct(private readonly InsuranceCatalog $catalog) {}

    public function index(): View
    {
        $open = Guide::query()->selectRaw('insurer_id, status, COUNT(*) AS qty, SUM(total_cents) AS total')
            ->whereIn('status', ['draft', 'ready', 'billed'])->groupBy('insurer_id', 'status')->get()->groupBy('insurer_id');
        $receivable = Batch::query()->selectRaw('insurer_id, SUM(total_cents - paid_cents - glosa_cents) AS due, SUM(glosa_cents) AS glosa')
            ->whereIn('status', ['closed', 'partial'])->groupBy('insurer_id')->get()->keyBy('insurer_id');

        return view('insurance.insurers', [
            'insurers' => Insurer::query()->withCount('plans')->orderByDesc('is_active')->orderBy('name')->get(),
            'open' => $open, 'receivable' => $receivable,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $insurer = Insurer::create($this->validated($request));

        return redirect()->route('insurers.show', $insurer)->with('success', 'Convênio cadastrado. Agora inclua os planos e a tabela de valores.');
    }

    public function show(Insurer $insurer): View
    {
        return view('insurance.insurer-show', [
            'insurer' => $insurer->load(['plans' => fn ($q) => $q->orderByDesc('is_active')->orderBy('name')]),
            'tables' => PriceTable::query()->with('plan:id,name')->withCount('items')->where('insurer_id', $insurer->id)->orderByDesc('is_active')->orderByDesc('valid_from')->get(),
            'doctors' => Doctor::query()->where('status', 'active')->orderBy('name')->get(),
            'credentialed' => $insurer->doctors()->pluck('doctors.id')->all(),
        ]);
    }

    public function update(Request $request, Insurer $insurer): RedirectResponse
    {
        $insurer->update($this->validated($request, $insurer) + ['is_active' => $request->boolean('is_active')]);

        return back()->with('success', 'Convênio atualizado.');
    }

    public function storePlan(Request $request, Insurer $insurer): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('insurance_plans')->where('insurer_id', $insurer->id)],
            'ans_code' => ['nullable', 'string', 'max:20'],
        ], [], ['name' => 'nome do plano']);
        $insurer->plans()->create($data);

        return back()->with('success', 'Plano incluído.');
    }

    public function togglePlan(InsurancePlan $plan): RedirectResponse
    {
        $plan->update(['is_active' => ! $plan->is_active]);

        return back()->with('success', $plan->is_active ? 'Plano reativado.' : 'Plano desativado.');
    }

    public function doctors(Request $request, Insurer $insurer): RedirectResponse
    {
        $ids = $request->validate(['doctor_ids' => ['nullable', 'array'], 'doctor_ids.*' => ['string', 'size:26', new ExistsInTenant(Doctor::class)]])['doctor_ids'] ?? [];
        $before = $insurer->doctors()->pluck('doctors.id')->sort()->values()->all();
        $insurer->doctors()->sync($ids);
        app(AuditLogger::class)->record('insurer.doctors_changed', $insurer, old: ['doctor_ids' => $before], new: ['doctor_ids' => collect($ids)->sort()->values()->all()]);

        return back()->with('success', $ids === [] ? 'Todos os médicos atendem este convênio.' : 'Credenciamento atualizado ('.count($ids).' médicos).');
    }

    // ------------------------------------------------------------------ tabelas de valores

    public function storeTable(Request $request, Insurer $insurer): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'plan_id' => ['nullable', 'string', 'size:26', Rule::exists('insurance_plans', 'id')->where('insurer_id', $insurer->id)],
            'valid_from' => ['required', 'date'], 'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
        ], [], ['valid_from' => 'início da vigência', 'valid_until' => 'fim da vigência']);
        $this->catalog->assertNoOverlap($insurer->id, $data['plan_id'] ?? null, $data['valid_from'], $data['valid_until'] ?? null);

        $table = PriceTable::create($data + ['insurer_id' => $insurer->id]);

        return redirect()->route('insurers.tables.show', $table)->with('success', 'Tabela criada. Inclua os procedimentos e valores.');
    }

    public function showTable(PriceTable $table): View
    {
        return view('insurance.price-table', [
            'table' => $table->load(['insurer', 'plan']),
            'items' => PriceItem::query()->with('procedure')->where('price_table_id', $table->id)->get()->sortBy('procedure.code'),
            'procedures' => Procedure::query()->where('is_active', true)->orderBy('code')->get(),
        ]);
    }

    public function updateTable(Request $request, PriceTable $table): RedirectResponse
    {
        $data = $request->validate(['valid_until' => ['nullable', 'date', 'after_or_equal:'.$table->valid_from->toDateString()]], [], ['valid_until' => 'fim da vigência']);
        $active = $request->boolean('is_active');
        if ($active) {
            $this->catalog->assertNoOverlap($table->insurer_id, $table->plan_id, $table->valid_from->toDateString(), $data['valid_until'] ?? null, $table->id);
        }
        $table->update(['valid_until' => $data['valid_until'] ?? null, 'is_active' => $active]);

        return back()->with('success', 'Tabela atualizada.');
    }

    public function storeItem(Request $request, PriceTable $table): RedirectResponse
    {
        $data = $request->validate([
            'procedure_id' => ['required', 'string', 'size:26', new ExistsInTenant(Procedure::class)],
            'copay_type' => ['required', Rule::in(['none', 'percent', 'fixed'])],
            'requires_authorization' => ['nullable', 'boolean'],
        ], [], ['procedure_id' => 'procedimento']);
        $price = $this->cents($request, 'price');
        $copay = $data['copay_type'] === 'none' ? 0 : $this->cents($request, 'copay_value', true, 1, 'valor da coparticipação');
        if ($data['copay_type'] === 'percent' && $copay > 10000) {
            throw ValidationException::withMessages(['copay_value' => 'Percentual entre 0,01 e 100.']);
        }

        PriceItem::query()->updateOrCreate(
            ['price_table_id' => $table->id, 'procedure_id' => $data['procedure_id']],
            ['price_cents' => $price, 'requires_authorization' => (bool) ($data['requires_authorization'] ?? false), 'copay_type' => $data['copay_type'], 'copay_value' => $copay],
        );

        return back()->with('success', 'Valor salvo ('.Format::money($price).'). Guias já criadas mantêm o valor da época.');
    }

    public function destroyItem(PriceTable $table, PriceItem $item): RedirectResponse
    {
        abort_unless($item->price_table_id === $table->id, 404);
        $item->delete();

        return back()->with('success', 'Procedimento retirado da tabela.');
    }

    // ------------------------------------------------------------------ procedimentos

    public function procedures(Request $request): View
    {
        $q = $request->validate(['q' => ['nullable', 'string', 'max:60']])['q'] ?? null;

        return view('insurance.procedures', [
            'procedures' => Procedure::query()
                ->when($q, fn ($w) => $w->where(fn ($x) => $x->where('code', 'like', addcslashes($q, '%_\\').'%')->orWhere('name', 'like', '%'.addcslashes($q, '%_\\').'%')))
                ->orderByDesc('is_active')->orderBy('code')->paginate(50)->withQueryString(),
            'q' => $q,
        ]);
    }

    public function storeProcedure(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'table_code' => ['required', Rule::in(array_keys(Procedure::TABLES))],
            'code' => ['required', 'string', 'max:10', 'regex:/^[0-9A-Za-z]+$/'],
            'name' => ['required', 'string', 'max:150'],
            'kind' => ['required', Rule::in(array_keys(Procedure::KINDS))],
        ], [], ['code' => 'código', 'name' => 'descrição']);

        if (Procedure::query()->where('table_code', $data['table_code'])->where('code', $data['code'])->exists()) {
            throw ValidationException::withMessages(['code' => 'Procedimento já cadastrado nesta tabela.']);
        }
        Procedure::create($data);

        return back()->with('success', 'Procedimento cadastrado.');
    }

    public function toggleProcedure(Procedure $procedure): RedirectResponse
    {
        $procedure->update(['is_active' => ! $procedure->is_active]);

        return back()->with('success', $procedure->is_active ? 'Procedimento reativado.' : 'Procedimento desativado.');
    }

    public function importProcedures(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:5120', 'mimes:csv,txt'],
            'table_code' => ['required', Rule::in(array_keys(Procedure::TABLES))],
        ], [], ['file' => 'arquivo']);
        $stats = DB::transaction(fn () => $this->catalog->importProcedures($data['file']->getRealPath(), $data['table_code']));

        return back()->with('success', "Importação concluída: {$stats['created']} novos, {$stats['updated']} atualizados, {$stats['skipped']} linhas ignoradas.");
    }

    private function validated(Request $request, ?Insurer $insurer = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('insurers')->where('company_id', $request->user()->company_id)->ignore($insurer?->id)],
            'ans_registry' => ['nullable', 'regex:/^\d{6}$/'],
            'cnpj' => ['nullable', 'string', 'max:18'],
            'provider_code' => ['nullable', 'string', 'max:14'],
            'tiss_version' => ['required', Rule::in(Insurer::TISS_VERSIONS)],
            'payment_term_days' => ['required', 'integer', 'between:0,365'],
            'max_guides_per_batch' => ['required', 'integer', 'between:1,100'],
            'phone' => ['nullable', 'string', 'max:20'], 'email' => ['nullable', 'email:rfc', 'max:190'],
            'portal_url' => ['nullable', 'url:https', 'max:255'], 'notes' => ['nullable', 'string', 'max:1000'],
        ], ['ans_registry.regex' => 'O registro ANS tem 6 dígitos.'], ['ans_registry' => 'registro ANS', 'provider_code' => 'código do prestador', 'payment_term_days' => 'prazo de pagamento']);

        $data['cnpj'] = Format::digits($data['cnpj'] ?? null) ?: null;
        if ($data['cnpj'] && strlen($data['cnpj']) !== 14) {
            throw ValidationException::withMessages(['cnpj' => 'CNPJ inválido.']);
        }

        return $data;
    }
}
