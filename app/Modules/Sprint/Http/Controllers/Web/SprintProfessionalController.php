<?php

namespace App\Modules\Sprint\Http\Controllers\Web;

use App\Core\Support\Format;
use App\Core\Validation\ExistsInTenant;
use App\Http\Controllers\Controller;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Sprint\Models\SprintProfessional;
use App\Modules\Sprint\Services\SprintService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Cadastro dos profissionais que recebem parte das vendas (médicos e parceiros). */
class SprintProfessionalController extends Controller
{
    public function __construct(private readonly SprintService $sprint) {}

    public function index(Request $request): View
    {
        $edit = $request->query('editar') ? SprintProfessional::query()->find($request->query('editar')) : null;

        return view('sprint.professionals', [
            'professionals' => SprintProfessional::query()->with('doctor:id,name,social_name')->orderByDesc('is_active')->orderBy('name')->get(),
            'doctors' => Doctor::query()->where('status', 'active')->orderBy('name')->get(['id', 'name', 'social_name']),
            'edit' => $edit,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->sprint->saveProfessional($this->validated($request));

        return redirect()->route('sprint.professionals.index')->with('success', 'Profissional cadastrado.');
    }

    public function update(Request $request, SprintProfessional $professional): RedirectResponse
    {
        $this->sprint->saveProfessional($this->validated($request), $professional);

        return redirect()->route('sprint.professionals.index')->with('success', 'Profissional atualizado. Vendas já feitas mantêm a divisão da época.');
    }

    public function toggle(SprintProfessional $professional): RedirectResponse
    {
        $professional->update(['is_active' => ! $professional->is_active]);

        return back()->with('success', $professional->is_active ? 'Profissional reativado.' : 'Profissional desativado (não aparece em novas vendas).');
    }

    public function importDoctors(): RedirectResponse
    {
        $count = $this->sprint->importDoctors();

        return back()->with('success', $count ? "{$count} médico(s) importado(s). Defina a regra de divisão de cada um." : 'Todos os médicos ativos já estão cadastrados.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(array_keys(SprintProfessional::KINDS))],
            'doctor_id' => ['nullable', 'string', 'size:26', new ExistsInTenant(Doctor::class)],
            'name' => ['required', 'string', 'min:3', 'max:150'],
            'document' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:150'],
            'phone' => ['nullable', 'string', 'max:20'],
            'pix_key' => ['nullable', 'string', 'max:140'],
            'bank_info' => ['nullable', 'string', 'max:255'],
            'asaas_wallet_id' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9\-]+$/'],
            'cielo_subordinate_id' => ['nullable', 'uuid'],
            'split_type' => ['required', Rule::in(['percent', 'fixed'])],
            'split_value' => ['required', 'string', 'max:20'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [], ['split_value' => 'valor da divisão', 'cielo_subordinate_id' => 'ID de subordinado Cielo', 'asaas_wallet_id' => 'walletId ASAAS']);

        $digits = Format::digits($data['document'] ?? null);
        if ($digits && ! in_array(strlen($digits), [11, 14], true)) {
            throw ValidationException::withMessages(['document' => 'CPF (11 dígitos) ou CNPJ (14 dígitos).']);
        }

        $value = Format::parseMoney($data['split_value']);
        if ($value === null || $value < 0 || ($data['split_type'] === 'percent' && $value > 10000)) {
            throw ValidationException::withMessages(['split_value' => $data['split_type'] === 'percent' ? 'Percentual entre 0 e 100.' : 'Valor inválido.']);
        }

        return ['split_value' => $value, 'document' => $digits] + array_map(fn ($v) => $v === '' ? null : $v, $data);
    }
}
