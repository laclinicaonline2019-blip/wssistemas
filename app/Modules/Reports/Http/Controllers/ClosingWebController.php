<?php

namespace App\Modules\Reports\Http\Controllers;

use App\Core\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Platform\Models\Company;
use App\Modules\Reports\Models\DoctorClosing;
use App\Modules\Reports\Services\ClosingService;
use App\Modules\Reports\Services\ReportService;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/** Fechamento mensal médico × clínica: financeiro (financeiro.fechamento) e o próprio médico. */
class ClosingWebController extends Controller
{
    public function index(Request $request): View
    {
        $period = $request->validate(['period' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']])['period'] ?? now(ReportService::TZ)->subMonthNoOverflow()->format('Y-m');
        $closings = DoctorClosing::query()->where('period', $period)->where('status', '!=', 'superseded')->get()->keyBy('doctor_id');

        return view('closings.index', [
            'period' => $period, 'closings' => $closings,
            'doctors' => Doctor::query()->where(fn ($q) => $q->where('status', 'active')->orWhereIn('id', $closings->keys()))->orderBy('name')->get(),
        ]);
    }

    public function preview(Request $request, Doctor $doctor, ClosingService $service): View
    {
        $period = $request->validate(['period' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']])['period'];

        return view('closings.preview', [
            'doctor' => $doctor, 'period' => $period, 'data' => $service->preview($doctor, $period),
            'history' => DoctorClosing::query()->where('doctor_id', $doctor->id)->where('period', $period)->orderByDesc('version')->get(),
        ]);
    }

    public function store(Request $request, Doctor $doctor, ClosingService $service): RedirectResponse
    {
        $period = $request->validate(['period' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']])['period'];
        $closing = $service->close($request->user(), $doctor, $period, $request->boolean('settle'));

        return redirect()->route('closings.show', $closing)->with('success', 'Mês fechado. Envie o demonstrativo ao médico para conferência'.($closing->payable_id ? ' — o repasse foi lançado em contas a pagar.' : '.'));
    }

    public function show(Request $request, DoctorClosing $closing): View
    {
        $this->authorizeView($request, $closing);

        return view('closings.show', ['closing' => $closing->load(['doctor', 'payable', 'closer:id,name', 'responder:id,name']), 'isDoctor' => $this->isDoctor($request, $closing)]);
    }

    public function pdf(Request $request, DoctorClosing $closing, AuditLogger $audit): Response
    {
        $this->authorizeView($request, $closing);
        $audit->record('closing.pdf', $closing);
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('chroot', public_path());
        $pdf = new Dompdf($options);
        $pdf->loadHtml(view('closings.pdf', ['closing' => $closing, 'clinic' => Company::query()->find($closing->company_id)?->trade_name])->render(), 'UTF-8');
        $pdf->setPaper('a4');
        $pdf->render();

        return response($pdf->output(), 200, ['Content-Type' => 'application/pdf', 'Cache-Control' => 'no-store, private',
            'Content-Disposition' => 'attachment; filename="fechamento-'.$closing->period.'-v'.$closing->version.'.pdf"']);
    }

    /** "Meus fechamentos" — usuário vinculado a um cadastro de médico. */
    public function mine(Request $request): View
    {
        $doctor = Doctor::query()->where('user_id', $request->user()->id)->first();
        abort_unless($doctor, 404);

        return view('closings.mine', ['doctor' => $doctor, 'closings' => DoctorClosing::query()->where('doctor_id', $doctor->id)->where('status', '!=', 'superseded')->orderByDesc('period')->get()]);
    }

    public function respond(Request $request, DoctorClosing $closing, ClosingService $service): RedirectResponse
    {
        $data = $request->validate(['action' => ['required', 'in:confirm,dispute'], 'notes' => ['nullable', 'string', 'max:1000']]);
        $service->respond($request->user(), $closing, $data['action'] === 'confirm', $data['notes'] ?? null);

        return back()->with('success', $data['action'] === 'confirm' ? 'Demonstrativo confirmado. Obrigado!' : 'Contestação enviada ao financeiro.');
    }

    private function isDoctor(Request $request, DoctorClosing $closing): bool
    {
        return Doctor::query()->withTrashed()->whereKey($closing->doctor_id)->value('user_id') === $request->user()->id;
    }

    private function authorizeView(Request $request, DoctorClosing $closing): void
    {
        abort_unless($request->user()->hasPermission('financeiro.fechamento') || $this->isDoctor($request, $closing), 403);
    }
}
