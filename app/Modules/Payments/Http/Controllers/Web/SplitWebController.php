<?php

namespace App\Modules\Payments\Http\Controllers\Web;

use App\Core\Support\Format;
use App\Core\Validation\ExistsInTenant;
use App\Http\Controllers\Controller;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Payments\Models\PaymentSplit;
use App\Modules\Payments\Models\SplitRule;
use App\Modules\Payments\Services\SplitService;
use App\Modules\Scheduling\Models\DoctorService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Regras de split/repasse e fechamento do repasse médico (financeiro.repasse). */
class SplitWebController extends Controller
{
    public function __construct(private readonly SplitService $splits) {}

    public function index(Request $request): View
    {
        $f = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        $tz = 'America/Sao_Paulo';
        $from = $f['from'] ?? CarbonImmutable::now($tz)->startOfMonth()->toDateString();
        $to = $f['to'] ?? CarbonImmutable::now($tz)->toDateString();
        $start = CarbonImmutable::parse($from, $tz)->startOfDay()->utc();
        $end = CarbonImmutable::parse($to, $tz)->endOfDay()->utc();

        $summary = PaymentSplit::query()->whereBetween('created_at', [$start, $end])
            ->selectRaw('doctor_id, mode, status, SUM(amount_cents) AS total, COUNT(*) AS qty')->groupBy('doctor_id', 'mode', 'status')->get()
            ->groupBy('doctor_id');

        return view('payments.splits', [
            'from' => $from, 'to' => $to, 'summary' => $summary,
            'doctors' => Doctor::query()->where('status', 'active')->orderBy('name')->get(),
            'rules' => SplitRule::query()->with(['doctor:id,name,social_name', 'service:id,name'])->orderBy('doctor_id')->get(),
            'services' => DoctorService::query()->orderBy('name')->get(['id', 'doctor_id', 'name']),
            'recent' => PaymentSplit::query()->with(['doctor:id,name,social_name', 'receivable:id,description'])->latest()->limit(30)->get(),
        ]);
    }

    public function storeRule(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'doctor_id' => ['required', 'string', 'size:26', new ExistsInTenant(Doctor::class)],
            'doctor_service_id' => ['nullable', 'string', 'size:26', new ExistsInTenant(DoctorService::class)],
            'payer_type' => ['nullable', Rule::in(['private', 'insurance'])],
            'type' => ['required', Rule::in(['percent', 'fixed'])],
            'value' => ['required', 'string', 'max:20'],
        ], [], ['value' => 'valor']);

        $cents = Format::parseMoney($data['value']);
        if ($cents === null || $cents <= 0 || ($data['type'] === 'percent' && $cents > 10000)) {
            throw ValidationException::withMessages(['value' => $data['type'] === 'percent' ? 'Percentual entre 0,01 e 100.' : 'Valor inválido.']);
        }
        if (! empty($data['doctor_service_id']) && DB::table('doctor_services')->where('id', $data['doctor_service_id'])->value('doctor_id') !== $data['doctor_id']) {
            throw ValidationException::withMessages(['doctor_service_id' => 'O tipo de atendimento não pertence a este médico.']);
        }

        SplitRule::create(['doctor_id' => $data['doctor_id'], 'doctor_service_id' => $data['doctor_service_id'] ?? null,
            'payer_type' => $data['payer_type'] ?? null, 'type' => $data['type'], 'value' => $cents]);

        return back()->with('success', 'Regra de repasse criada.');
    }

    public function toggleRule(SplitRule $rule): RedirectResponse
    {
        $rule->update(['is_active' => ! $rule->is_active]);

        return back()->with('success', $rule->is_active ? 'Regra reativada.' : 'Regra desativada (recebimentos já lançados não mudam).');
    }

    public function wallet(Request $request, Doctor $doctor): RedirectResponse
    {
        $wallet = $request->validate(['asaas_wallet_id' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9\-]+$/']])['asaas_wallet_id'] ?? null;
        $doctor->forceFill(['asaas_wallet_id' => $wallet ?: null])->save();

        return back()->with('success', 'Carteira ASAAS do médico atualizada.');
    }

    public function settle(Request $request, Doctor $doctor): RedirectResponse
    {
        $f = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from']]);
        $payable = $this->splits->settle($request->user(), $doctor->id, $f['from'], $f['to']);

        return redirect()->route('payables.show', $payable)->with('success', 'Repasse fechado: conta a pagar gerada para '.$doctor->displayName().'.');
    }
}
