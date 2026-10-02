<?php

namespace App\Modules\Insurance\Http\Controllers\Web;

use App\Core\Tenancy\TenantContext;
use App\Core\Validation\ExistsInTenant;
use App\Http\Controllers\Controller;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Finance\Http\ParsesMoney;
use App\Modules\Finance\Models\Receivable;
use App\Modules\Insurance\Models\Authorization;
use App\Modules\Insurance\Models\Guide;
use App\Modules\Insurance\Models\GuideItem;
use App\Modules\Insurance\Models\Insurer;
use App\Modules\Insurance\Models\Procedure;
use App\Modules\Insurance\Services\GuideService;
use App\Modules\Organization\Models\Branch;
use App\Modules\Patients\Models\Patient;
use App\Modules\Platform\Models\Company;
use App\Modules\Scheduling\Models\Appointment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Guias de convênio e autorizações prévias. */
class GuideWebController extends Controller
{
    use ParsesMoney;

    public function __construct(private readonly GuideService $guides, private readonly TenantContext $context) {}

    public function index(Request $request): View
    {
        $f = $request->validate([
            'status' => ['nullable', Rule::in([...array_keys(Guide::STATUSES), 'all', 'glosa'])],
            'insurer_id' => ['nullable', 'string', 'size:26'], 'q' => ['nullable', 'string', 'max:100'],
        ]);
        $status = $f['status'] ?? 'open';

        $guides = Guide::query()->with(['patient:id,name,social_name,record_number', 'insurer:id,name', 'doctor:id,name,social_name', 'batch:id,number'])
            ->accessibleBranches($this->context->allowedBranchIds())
            ->when($status === 'open', fn ($q) => $q->whereIn('status', ['draft', 'ready']))
            ->when($status === 'glosa', fn ($q) => $q->whereIn('glosa_status', ['pending', 'appealed']))
            ->when(! in_array($status, ['open', 'all', 'glosa'], true), fn ($q) => $q->where('status', $status))
            ->when($f['insurer_id'] ?? null, fn ($q, $id) => $q->where('insurer_id', $id))
            ->when($f['q'] ?? null, fn ($q, $t) => $q->where(fn ($w) => $w->where('number', $t)->orWhereIn('patient_id', Patient::query()->search($t)->select('id'))))
            ->orderByDesc('attendance_date')->orderByDesc('number')->paginate(30)->withQueryString();

        return view('insurance.guides', [
            'guides' => $guides, 'status' => $status, 'insurerId' => $f['insurer_id'] ?? null,
            'insurers' => Insurer::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(Request $request): View
    {
        $patient = $request->query('patient_id') ? Patient::query()->with('insurances')->find($request->query('patient_id')) : null;

        return view('insurance.guide-create', [
            'patient' => $patient,
            'branches' => Branch::query()->active()->accessible($this->context->allowedBranchIds())->orderByDesc('is_headquarters')->get(),
            'doctors' => Doctor::query()->where('status', 'active')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'patient_id' => ['required', 'string', 'size:26', new ExistsInTenant(Patient::class)],
            'patient_insurance_id' => ['required', 'string', 'size:26'],
            'branch_id' => ['required', 'string', 'size:26', Rule::in($this->branchIds())],
            'doctor_id' => ['required', 'string', 'size:26', new ExistsInTenant(Doctor::class)],
            'guide_type' => ['required', Rule::in(array_keys(Guide::TYPES))],
            'attendance_date' => ['required', 'date'],
        ], [], ['patient_insurance_id' => 'carteirinha', 'attendance_date' => 'data do atendimento']);
        $data['attendance_date'] = substr($data['attendance_date'], 0, 10);

        $guide = $this->guides->createManual($request->user(), $data);

        return redirect()->route('guides.show', $guide)->with('success', 'Guia criada — inclua os procedimentos realizados.');
    }

    /** Gera a guia de um agendamento por convênio (quando não foi gerada na chegada). */
    public function fromAppointment(Request $request, Appointment $appointment): RedirectResponse
    {
        abort_unless($this->allowed($appointment->branch_id), 404);
        if (! $appointment->arrived_at) {
            return back()->with('error', 'Registre a chegada do paciente antes de gerar a guia.');
        }
        $guide = $this->guides->forAppointment($appointment, $request->user());

        return $guide ? redirect()->route('guides.show', $guide) : back()->with('error', 'Agendamento particular — não há guia de convênio.');
    }

    public function show(Guide $guide): View
    {
        abort_unless($this->allowed($guide->branch_id), 404);
        $guide->load(['items', 'patient', 'insurer', 'plan', 'doctor', 'branch:id,name', 'appointment:id,protocol,starts_at,status', 'authorization.procedure', 'batch:id,number,status', 'copayReceivable']);

        return view('insurance.guide-show', [
            'guide' => $guide,
            'issues' => $guide->isEditable() ? $this->guides->issues($guide) : [],
            'procedures' => Procedure::query()->where('is_active', true)->when($guide->guide_type === 'consulta', fn ($q) => $q->where('kind', 'consultation'))->orderBy('code')->get(),
            'authorizations' => Authorization::query()->with('procedure:id,code,name')->where('patient_insurance_id', $guide->patient_insurance_id)
                ->whereIn('status', ['authorized', 'used'])->latest()->limit(20)->get(),
            'patientCharges' => Receivable::query()->where('insurance_guide_id', $guide->id)->orderBy('created_at')->get(),
        ]);
    }

    public function update(Request $request, Guide $guide): RedirectResponse
    {
        abort_unless($this->allowed($guide->branch_id), 404);
        $data = $request->validate([
            'operator_guide_number' => ['nullable', 'string', 'max:20'],
            'authorization_id' => ['nullable', 'string', 'size:26', new ExistsInTenant(Authorization::class)],
            'consultation_type' => ['required', Rule::in(array_keys(Guide::CONSULTATION_TYPES))],
            'attendance_type' => ['required', Rule::in(array_column(Procedure::KINDS, 'attendance_type'))],
            'accident_indicator' => ['required', Rule::in(array_keys(Guide::ACCIDENT))],
            'character' => ['required', Rule::in(['1', '2'])],
            'cbo_code' => ['required', 'regex:/^\d{6}$/'],
            'clinical_indication' => ['nullable', 'string', 'max:500'],
            'observation' => ['nullable', 'string', 'max:500'],
        ], ['cbo_code.regex' => 'O CBO tem 6 dígitos.'], ['cbo_code' => 'CBO']);
        $data['authorization_id'] = ($data['authorization_id'] ?? null) ?: null;

        $this->guides->update($guide, $data);

        return back()->with('success', 'Guia atualizada.');
    }

    public function addItem(Request $request, Guide $guide): RedirectResponse
    {
        abort_unless($this->allowed($guide->branch_id), 404);
        $data = $request->validate([
            'procedure_id' => ['required', 'string', 'size:26'],
            'quantity' => ['required', 'integer', 'between:1,999'],
            'execution_date' => ['required', 'date'],
        ], [], ['procedure_id' => 'procedimento', 'execution_date' => 'data de execução']);
        $this->guides->addItem($request->user(), $guide, $data['procedure_id'], (int) $data['quantity'], substr($data['execution_date'], 0, 10));

        return back()->with('success', 'Procedimento incluído com o valor da tabela do convênio.');
    }

    public function removeItem(Guide $guide, GuideItem $item): RedirectResponse
    {
        abort_unless($this->allowed($guide->branch_id), 404);
        $this->guides->removeItem($guide, $item);

        return back()->with('success', 'Procedimento retirado.');
    }

    public function ready(Guide $guide): RedirectResponse
    {
        abort_unless($this->allowed($guide->branch_id), 404);
        $this->guides->markReady($guide);

        return back()->with('success', 'Guia conferida e pronta para faturar.');
    }

    public function draft(Guide $guide): RedirectResponse
    {
        abort_unless($this->allowed($guide->branch_id), 404);
        $this->guides->backToDraft($guide);

        return back()->with('success', 'Guia voltou para rascunho.');
    }

    public function cancel(Request $request, Guide $guide): RedirectResponse
    {
        abort_unless($this->allowed($guide->branch_id), 404);
        $reason = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:255']], [], ['reason' => 'motivo'])['reason'];
        $this->guides->cancel($request->user(), $guide, $reason);

        return back()->with('success', 'Guia cancelada.');
    }

    public function chargePatient(Request $request, Guide $guide): RedirectResponse
    {
        abort_unless($this->allowed($guide->branch_id), 404);
        $description = $request->validate(['description' => ['required', 'string', 'min:3', 'max:120']], [], ['description' => 'descrição'])['description'];
        $receivable = $this->guides->chargePatient($request->user(), $guide, $description, $this->cents($request, 'amount'));

        return redirect()->route('receivables.show', $receivable)->with('success', 'Cobrança particular criada para o paciente (atendimento misto).');
    }

    public function print(Guide $guide): View
    {
        abort_unless($this->allowed($guide->branch_id), 404);

        return view('insurance.guide-print', [
            'guide' => $guide->load(['items', 'patient', 'insurer', 'plan', 'doctor', 'branch', 'authorization']),
            'company' => Company::query()->findOrFail($this->context->companyId()),
        ]);
    }

    // ------------------------------------------------------------------ autorizações

    public function authorizations(Request $request): View
    {
        $status = $request->validate(['status' => ['nullable', Rule::in([...array_keys(Authorization::STATUSES), 'all'])]])['status'] ?? 'requested';
        $patient = $request->query('patient_id') ? Patient::query()->with('insurances')->find($request->query('patient_id')) : null;

        return view('insurance.authorizations', [
            'items' => Authorization::query()->with(['patient:id,name,social_name,record_number', 'insurer:id,name', 'procedure:id,code,name', 'doctor:id,name,social_name'])
                ->when($this->context->allowedBranchIds() !== null, fn ($q) => $q->whereIn('branch_id', $this->context->allowedBranchIds()))
                ->when($status !== 'all', fn ($q) => $q->where('status', $status))
                ->latest()->paginate(30)->withQueryString(),
            'status' => $status, 'patient' => $patient,
            'procedures' => Procedure::query()->where('is_active', true)->orderBy('code')->get(),
            'doctors' => Doctor::query()->where('status', 'active')->orderBy('name')->get(),
            'branches' => Branch::query()->active()->accessible($this->context->allowedBranchIds())->orderByDesc('is_headquarters')->get(),
        ]);
    }

    public function storeAuthorization(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'patient_id' => ['required', 'string', 'size:26', new ExistsInTenant(Patient::class)],
            'patient_insurance_id' => ['required', 'string', 'size:26'],
            'branch_id' => ['required', 'string', 'size:26', Rule::in($this->branchIds())],
            'procedure_id' => ['required', 'string', 'size:26', new ExistsInTenant(Procedure::class)],
            'doctor_id' => ['nullable', 'string', 'size:26', new ExistsInTenant(Doctor::class)],
            'quantity' => ['required', 'integer', 'between:1,999'],
            'operator_guide_number' => ['nullable', 'string', 'max:20'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [], ['patient_insurance_id' => 'carteirinha', 'procedure_id' => 'procedimento']);
        $this->guides->requestAuthorization($request->user(), $data);

        return redirect()->route('authorizations.index')->with('success', 'Solicitação registrada. Ao receber a resposta da operadora, informe a senha/validade.');
    }

    public function decideAuthorization(Request $request, Authorization $authorization): RedirectResponse
    {
        abort_unless($this->allowed($authorization->branch_id), 404);
        $data = $request->validate([
            'decision' => ['required', Rule::in(['authorized', 'denied'])],
            'password' => ['nullable', 'string', 'max:20'], 'operator_guide_number' => ['nullable', 'string', 'max:20'],
            'authorized_on' => ['nullable', 'date'], 'valid_until' => ['nullable', 'date'],
            'denial_reason' => ['nullable', 'string', 'max:255'],
        ], [], ['password' => 'senha', 'valid_until' => 'validade da senha']);
        $this->guides->decideAuthorization($request->user(), $authorization, $data);

        return back()->with('success', $data['decision'] === 'authorized' ? 'Autorização registrada.' : 'Negativa registrada.');
    }

    public function cancelAuthorization(Authorization $authorization): RedirectResponse
    {
        abort_unless($this->allowed($authorization->branch_id), 404);
        $this->guides->cancelAuthorization($authorization);

        return back()->with('success', 'Autorização cancelada.');
    }

    private function allowed(string $branchId): bool
    {
        $allowed = $this->context->allowedBranchIds();

        return $allowed === null || in_array($branchId, $allowed, true);
    }

    private function branchIds(): array
    {
        return Branch::query()->active()->accessible($this->context->allowedBranchIds())->pluck('id')->all();
    }
}
