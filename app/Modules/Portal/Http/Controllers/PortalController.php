<?php

namespace App\Modules\Portal\Http\Controllers;

use App\Core\Audit\AuditLogger;
use App\Core\Security\PasswordRules;
use App\Http\Controllers\Controller;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Documents\Models\MedicalDocument;
use App\Modules\Documents\Models\PatientFile;
use App\Modules\Documents\Services\DocumentPdf;
use App\Modules\Finance\Models\FinancialTransaction;
use App\Modules\Organization\Models\Branch;
use App\Modules\Payments\Models\PaymentCharge;
use App\Modules\Platform\Models\Company;
use App\Modules\Portal\Models\PatientAccount;
use App\Modules\Portal\Services\PortalAccountService;
use App\Modules\Portal\Services\PortalService;
use App\Modules\Scheduling\Models\Appointment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Área logada do portal do paciente. Tudo filtrado pelo paciente da conta. */
class PortalController extends Controller
{
    public function __construct(private readonly PortalService $portal, private readonly AuditLogger $audit) {}

    public function home(Request $request): View
    {
        $a = $this->account();

        return $this->page($request, 'portal.home', [
            'upcoming' => $this->portal->upcoming($a)->take(5),
            'openBills' => $this->portal->receivables($a)->whereIn('status', ['open', 'partial'])->orderBy('due_date')->get(),
            'recentDocs' => $this->portal->documents($a)->latest('issued_at')->limit(5)->get(),
        ]);
    }

    public function appointments(Request $request): View
    {
        $a = $this->account();

        return $this->page($request, 'portal.appointments', [
            'upcoming' => $this->portal->upcoming($a),
            'past' => $this->portal->appointments($a)->where(fn ($q) => $q->where('starts_at', '<', now()->subHours(2))->orWhereIn('status', ['cancelled', 'no_show', 'completed']))
                ->orderByDesc('starts_at')->limit(50)->get(),
            'encounters' => $this->portal->encounters($a),
            'settings' => $this->portal->settings($this->company($request)),
        ]);
    }

    public function cancel(Request $request, Appointment $appointment): RedirectResponse
    {
        $this->portal->cancel($this->company($request), $this->account(), $appointment);

        return back()->with('success', 'Consulta cancelada. O horário foi liberado.');
    }

    public function confirm(Appointment $appointment): RedirectResponse
    {
        $this->portal->confirm($this->account(), $appointment);

        return back()->with('success', 'Presença confirmada. Obrigado!');
    }

    public function book(Request $request): View
    {
        $f = $request->validate(['doctor_id' => ['nullable', 'string', 'size:26'], 'branch_id' => ['nullable', 'string', 'size:26']]);
        $company = $this->company($request);
        $doctors = $this->portal->bookableDoctors();
        $doctor = ! empty($f['doctor_id']) ? $doctors->firstWhere('id', $f['doctor_id']) : null;
        $branches = $doctor ? $doctor->branches->filter(fn ($b) => $doctor->scheduleTemplates()->where('branch_id', $b->id)->exists())->values() : collect();
        $branch = $doctor ? ($branches->firstWhere('id', $f['branch_id'] ?? null) ?? $branches->first()) : null;
        $branch = $branch ? Branch::query()->active()->find($branch->id) : null;

        return $this->page($request, 'portal.book', [
            'settings' => $this->portal->settings($company),
            'doctors' => $doctors, 'doctor' => $doctor, 'branches' => $branches, 'branch' => $branch,
            'slots' => $doctor && $branch ? $this->portal->slots($company, $branch, $doctor) : [],
            'insurances' => $this->account()->patient->insurances()->whereNotNull('insurer_id')->get(),
        ]);
    }

    public function storeBooking(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'doctor_id' => ['required', 'string', 'size:26'], 'branch_id' => ['required', 'string', 'size:26'],
            'service_id' => ['required', 'string', 'size:26'], 'starts_at' => ['required', 'date'],
            'payer_type' => ['required', Rule::in(['private', 'insurance'])], 'patient_insurance_id' => ['nullable', 'string', 'size:26'],
            'idempotency_key' => ['nullable', 'string', 'max:40'],
        ], [], ['service_id' => 'tipo de atendimento', 'starts_at' => 'horário']);
        abort_unless(Doctor::query()->active()->whereKey($data['doctor_id'])->exists(), 404);

        $appointment = $this->portal->book($this->company($request), $this->account(), $data);

        return redirect()->route('portal.appointments')->with('success', 'Consulta agendada para '.$appointment->starts_at->timezone('America/Sao_Paulo')->format('d/m/Y \à\s H:i').'. Protocolo '.$appointment->protocol.'.');
    }

    public function documents(Request $request): View
    {
        $a = $this->account();

        return $this->page($request, 'portal.documents', [
            'documents' => $this->portal->documents($a)->latest('issued_at')->paginate(30),
            'files' => $this->portal->files($a)->latest()->get(),
        ]);
    }

    public function documentPdf(MedicalDocument $document, DocumentPdf $pdf): Response
    {
        $a = $this->account();
        $this->portal->assertOwn($a, $document);
        abort_unless($document->status === 'issued' && in_array($document->type, PortalService::DOCUMENT_TYPES, true), 404);
        $this->audit->record('portal.document_downloaded', $document, metadata: ['type' => $document->type]);

        return response($pdf->render(collect([$document])), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$pdf->filename($document).'"',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function file(PatientFile $file): StreamedResponse
    {
        $a = $this->account();
        $this->portal->assertOwn($a, $file);
        abort_unless($file->visible_to_patient && $file->status === 'active', 404);
        $disk = Storage::disk($file->disk);
        abort_unless($disk->exists($file->path), 404);
        $this->audit->record('portal.file_downloaded', $file);

        return $disk->response($file->path, preg_replace('/[^\w.\- ]+/u', '_', $file->original_name), [
            'Content-Type' => $file->mime, 'Cache-Control' => 'no-store, private',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
        ], 'attachment');
    }

    public function payments(Request $request): View
    {
        $a = $this->account();
        $receivables = $this->portal->receivables($a)->orderByDesc('due_date')->limit(100)->get();

        return $this->page($request, 'portal.payments', [
            'receivables' => $receivables,
            // Link de pagamento online pendente (PIX/cartão) de cada conta em aberto.
            'charges' => PaymentCharge::query()->whereIn('receivable_id', $receivables->pluck('id'))->where('status', 'pending')
                ->latest()->get()->keyBy('receivable_id'),
        ]);
    }

    public function receipt(Request $request, FinancialTransaction $transaction): View
    {
        abort_unless($transaction->kind === 'receipt' && $transaction->receivable_id, 404);
        $transaction->load(['receivable.patient', 'creator:id,name', 'reversal:id,reversal_of', 'session.branch']);
        $this->portal->assertOwn($this->account(), $transaction->receivable);
        $company = $this->company($request);

        return view('finance.receipt', [
            't' => $transaction, 'branch' => $transaction->session?->branch ?? Branch::query()->find($transaction->branch_id), 'company' => $company,
            'format' => 'a4', 'thermalWidth' => 80, 'preview' => false,
        ]);
    }

    public function doctors(Request $request): View
    {
        return $this->page($request, 'portal.doctors', ['doctors' => Doctor::query()->active()->with(['specialties:id,name', 'branches:id,name'])->orderBy('name')->get()]);
    }

    public function profile(Request $request): View
    {
        return $this->page($request, 'portal.profile', ['patient' => $this->account()->patient->load('insurances')]);
    }

    public function password(Request $request, PortalAccountService $accounts): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', 'different:current_password', PasswordRules::default()],
        ], [], ['password' => 'nova senha', 'current_password' => 'senha atual']);
        $accounts->changePassword($this->account(), $data['current_password'], $data['password']);
        $request->session()->regenerate();

        return back()->with('success', 'Senha alterada.');
    }

    private function account(): PatientAccount
    {
        /** @var PatientAccount $a */
        $a = Auth::guard('patient')->user();

        return $a;
    }

    private function company(Request $request): Company
    {
        return $request->attributes->get('portal_company');
    }

    private function page(Request $request, string $view, array $data): View
    {
        return view($view, $data + ['company' => $this->company($request), 'account' => $this->account(), 'patient' => $data['patient'] ?? $this->account()->patient]);
    }
}
