<?php

namespace App\Modules\Insurance\Http\Controllers\Web;

use App\Core\Audit\AuditLogger;
use App\Core\Support\Format;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\FinancialTransaction;
use App\Modules\Insurance\Models\Batch;
use App\Modules\Insurance\Models\Guide;
use App\Modules\Insurance\Models\Insurer;
use App\Modules\Insurance\Services\BatchService;
use App\Modules\Organization\Models\Branch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Lotes de faturamento (TISS), retorno da operadora e glosas. */
class BatchWebController extends Controller
{
    public function __construct(private readonly BatchService $batches, private readonly TenantContext $context) {}

    public function index(Request $request): View
    {
        $f = $request->validate(['insurer_id' => ['nullable', 'string', 'size:26'], 'branch_id' => ['nullable', 'string', 'size:26'], 'guide_type' => ['nullable', Rule::in(array_keys(Guide::TYPES))]]);

        // Guias prontas agrupadas (convênio × unidade × tipo) para montar lotes.
        $ready = Guide::query()->with(['patient:id,name,social_name,record_number', 'doctor:id,name,social_name'])
            ->accessibleBranches($this->context->allowedBranchIds())
            ->where('status', 'ready')->whereNull('batch_id')
            ->when($f['insurer_id'] ?? null, fn ($q, $id) => $q->where('insurer_id', $id))
            ->when($f['branch_id'] ?? null, fn ($q, $id) => $q->where('branch_id', $id))
            ->when($f['guide_type'] ?? null, fn ($q, $t) => $q->where('guide_type', $t))
            ->orderBy('attendance_date')->limit(300)->get()
            ->groupBy(fn (Guide $g) => $g->insurer_id.'|'.$g->branch_id.'|'.$g->guide_type);

        return view('insurance.batches', [
            'ready' => $ready,
            'batches' => Batch::query()->with(['insurer:id,name', 'branch:id,name'])->accessibleBranches($this->context->allowedBranchIds())
                ->latest()->paginate(20)->withQueryString(),
            'insurers' => Insurer::query()->orderBy('name')->get(['id', 'name'])->keyBy('id'),
            'branches' => Branch::query()->accessible($this->context->allowedBranchIds())->get(['id', 'name'])->keyBy('id'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'insurer_id' => ['required', 'string', 'size:26'], 'branch_id' => ['required', 'string', 'size:26'],
            'guide_type' => ['required', Rule::in(array_keys(Guide::TYPES))],
            'guide_ids' => ['required', 'array', 'min:1', 'max:100'], 'guide_ids.*' => ['string', 'size:26'],
        ], ['guide_ids.required' => 'Selecione as guias do lote.']);
        abort_unless($this->allowed($data['branch_id']), 404);

        $batch = $this->batches->create($request->user(), $data['insurer_id'], $data['branch_id'], $data['guide_type'], array_values(array_unique($data['guide_ids'])));

        return redirect()->route('batches.show', $batch)->with('success', "Lote {$batch->number} montado. Confira e feche para gerar o XML TISS.");
    }

    public function show(Batch $batch): View
    {
        abort_unless($this->allowed($batch->branch_id), 404);

        return view('insurance.batch-show', [
            'batch' => $batch->load(['insurer', 'branch:id,name', 'receivable']),
            'guides' => Guide::query()->with(['patient:id,name,social_name,record_number', 'doctor:id,name,social_name'])->where('batch_id', $batch->id)->orderBy('number')->get(),
            'methods' => array_diff_key(FinancialTransaction::METHODS, ['cash' => 1]),
        ]);
    }

    public function removeGuide(Batch $batch, Guide $guide): RedirectResponse
    {
        abort_unless($this->allowed($batch->branch_id), 404);
        $this->batches->removeGuide($batch, $guide);

        return back()->with('success', "Guia {$guide->number} retirada do lote.");
    }

    public function close(Request $request, Batch $batch): RedirectResponse
    {
        abort_unless($this->allowed($batch->branch_id), 404);
        $this->batches->close($request->user(), $batch);

        return back()->with('success', 'Lote fechado: XML TISS gerado e validado no schema da ANS, e conta a receber do convênio criada.');
    }

    public function xml(Batch $batch): Response
    {
        abort_unless($this->allowed($batch->branch_id) && $batch->xml, 404);
        app(AuditLogger::class)->record('insurance.batch_xml_downloaded', $batch, metadata: ['number' => $batch->number]);

        // Nome do arquivo no padrão usual de envio: <sequencial>_<hash>.xml
        return response($batch->xmlFile(), 200, [
            'Content-Type' => 'application/xml; charset=ISO-8859-1',
            'Content-Disposition' => 'attachment; filename="'.str_pad($batch->number, 20, '0', STR_PAD_LEFT).'_'.$batch->xml_hash.'.xml"',
        ]);
    }

    public function sent(Request $request, Batch $batch): RedirectResponse
    {
        abort_unless($this->allowed($batch->branch_id), 404);
        $protocol = $request->validate(['protocol' => ['required', 'string', 'max:40']], [], ['protocol' => 'protocolo'])['protocol'];
        $this->batches->markSent($batch, $protocol);

        return back()->with('success', 'Envio registrado.');
    }

    public function cancel(Request $request, Batch $batch): RedirectResponse
    {
        abort_unless($this->allowed($batch->branch_id), 404);
        $reason = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:255']], [], ['reason' => 'motivo'])['reason'];
        $this->batches->cancel($request->user(), $batch, $reason);

        return back()->with('success', 'Lote cancelado — as guias voltaram para "prontas".');
    }

    public function registerReturn(Request $request, Batch $batch): RedirectResponse
    {
        abort_unless($this->allowed($batch->branch_id), 404);
        $data = $request->validate([
            'method' => ['required', Rule::in(array_keys(FinancialTransaction::METHODS)), 'not_in:cash'],
            'paid_on' => ['required', 'date'],
            'guides' => ['required', 'array'],
            'guides.*.paid' => ['nullable', 'string', 'max:20'],
            'guides.*.glosa_code' => ['nullable', 'regex:/^\d{4}$/'],
            'guides.*.glosa_reason' => ['nullable', 'string', 'max:255'],
        ], ['guides.*.glosa_code.regex' => 'Código de glosa TISS tem 4 dígitos.'], ['paid_on' => 'data do pagamento', 'method' => 'forma']);

        $results = [];
        foreach ($data['guides'] as $guideId => $row) {
            if (($row['paid'] ?? '') === '' || $row['paid'] === null) {
                continue; // guia sem retorno nesta remessa
            }
            $cents = Format::parseMoney($row['paid']);
            if ($cents === null || $cents < 0) {
                throw ValidationException::withMessages(["guides.{$guideId}.paid" => 'Valor pago inválido.']);
            }
            $results[$guideId] = ['paid_cents' => $cents, 'glosa_code' => $row['glosa_code'] ?? null, 'glosa_reason' => $row['glosa_reason'] ?? null];
        }

        $this->batches->registerReturn($request->user(), $batch, $results, $data['method'], substr($data['paid_on'], 0, 10));

        return back()->with('success', 'Retorno registrado: pagamento lançado no financeiro e repasses calculados. Trate as glosas abaixo.');
    }

    public function glosa(Request $request, Guide $guide): RedirectResponse
    {
        abort_unless($this->allowed($guide->branch_id), 404);
        $data = $request->validate([
            'action' => ['required', Rule::in(['appeal', 'accept', 'recover'])],
            'appeal_text' => ['nullable', 'string', 'max:1000'],
            'recovered' => ['nullable', 'string', 'max:20'],
            'method' => ['nullable', Rule::in(array_keys(FinancialTransaction::METHODS)), 'not_in:cash'],
            'paid_on' => ['nullable', 'date'],
        ]);
        if ($data['action'] === 'recover') {
            $data['recovered_cents'] = Format::parseMoney((string) ($data['recovered'] ?? '')) ?? 0;
        }

        $this->batches->resolveGlosa($request->user(), $guide, $data['action'], $data);

        return back()->with('success', ['appeal' => 'Recurso de glosa registrado.', 'accept' => 'Glosa aceita e baixada.', 'recover' => 'Recurso concluído: valor recuperado lançado.'][$data['action']]);
    }

    private function allowed(string $branchId): bool
    {
        $allowed = $this->context->allowedBranchIds();

        return $allowed === null || in_array($branchId, $allowed, true);
    }
}
