<?php

namespace App\Modules\Sprint\Http\Controllers\Web;

use App\Core\Support\Format;
use App\Core\Tenancy\TenantContext;
use App\Core\Validation\ExistsInTenant;
use App\Http\Controllers\Controller;
use App\Modules\Documents\Services\DocumentService;
use App\Modules\Finance\Http\ParsesMoney;
use App\Modules\Finance\Services\FinanceService;
use App\Modules\Organization\Models\Branch;
use App\Modules\Patients\Models\Patient;
use App\Modules\Payments\Models\PaymentCharge;
use App\Modules\Payments\Models\PaymentGateway;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Sprint\Models\SprintInvoice;
use App\Modules\Sprint\Models\SprintPayment;
use App\Modules\Sprint\Models\SprintProfessional;
use App\Modules\Sprint\Models\SprintSale;
use App\Modules\Sprint\Models\SprintSetting;
use App\Modules\Sprint\Models\SprintSplit;
use App\Modules\Sprint\Services\NfseService;
use App\Modules\Sprint\Services\SprintLedger;
use App\Modules\Sprint\Services\SprintService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Melhor Sprint: painel, nova venda (PDV), tela de pagamento com confirmação e comprovante. */
class SprintWebController extends Controller
{
    use ParsesMoney;

    public function __construct(
        private readonly SprintService $sprint,
        private readonly NfseService $nfse,
        private readonly PaymentService $payments,
        private readonly FinanceService $finance,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request): View
    {
        $f = $request->validate([
            'status' => ['nullable', Rule::in(array_merge(array_keys(SprintSale::STATUSES), ['all']))],
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'q' => ['nullable', 'string', 'max:100'],
        ]);
        $tz = 'America/Sao_Paulo';
        $from = $f['from'] ?? CarbonImmutable::now($tz)->startOfMonth()->toDateString();
        $to = $f['to'] ?? CarbonImmutable::now($tz)->toDateString();
        $start = CarbonImmutable::parse($from, $tz)->startOfDay()->utc();
        $end = CarbonImmutable::parse($to, $tz)->endOfDay()->utc();
        $allowed = $this->context->allowedBranchIds();

        $sales = SprintSale::query()->with(['patient:id,name,social_name', 'branch:id,name'])->accessibleBranches($allowed)
            ->whereBetween('created_at', [$start, $end])
            ->when(($f['status'] ?? 'all') !== 'all', fn ($q) => $q->where('status', $f['status']))
            ->when($f['q'] ?? null, function ($q, $term) {
                $digits = preg_replace('/\D/', '', $term);
                $q->where(fn ($w) => $w->where('description', 'like', '%'.addcslashes($term, '%_\\').'%')
                    ->orWhere('customer_name', 'like', '%'.addcslashes($term, '%_\\').'%')
                    ->orWhereIn('patient_id', Patient::query()->search($term)->select('id'))
                    ->when($digits !== '' && strlen($digits) <= 9, fn ($w) => $w->orWhere('number', (int) $digits)));
            })
            ->latest()->paginate(25)->withQueryString();

        $saleIds = SprintSale::query()->accessibleBranches($allowed)->select('id');
        $kpi = SprintSplit::query()->whereIn('sale_id', $saleIds)->where('status', '!=', 'reversed')->whereBetween('created_at', [$start, $end])
            ->selectRaw('party, SUM(amount_cents) AS total')->groupBy('party')->pluck('total', 'party')->map(fn ($v) => (int) $v);
        $received = (int) SprintPayment::query()->whereIn('sale_id', $saleIds)->where('status', 'paid')->whereBetween('paid_at', [$start, $end])->sum('amount_cents');
        $refunded = (int) SprintPayment::query()->whereIn('sale_id', $saleIds)->where('status', 'refunded')->whereBetween('paid_at', [$start, $end])->sum('amount_cents');

        return view('sprint.index', [
            'sales' => $sales, 'from' => $from, 'to' => $to, 'status' => $f['status'] ?? 'all', 'q' => $f['q'] ?? '',
            'kpi' => $kpi, 'received' => $received, 'refunded' => $refunded,
        ]);
    }

    public function create(Request $request): View
    {
        return view('sprint.create', [
            'professionals' => SprintProfessional::query()->where('is_active', true)->orderBy('name')->get(),
            'branches' => Branch::query()->active()->accessible($this->context->allowedBranchIds())->orderByDesc('is_headquarters')->get(),
            'settings' => SprintSetting::current(),
            'patient' => $request->query('patient_id') ? Patient::query()->find($request->query('patient_id')) : null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'branch_id' => ['nullable', 'string', 'size:26'],
            'patient_id' => ['nullable', 'string', 'size:26', new ExistsInTenant(Patient::class)],
            'customer_name' => ['nullable', 'string', 'max:150'],
            'customer_document' => ['nullable', 'string', 'max:20'],
            'description' => ['nullable', 'string', 'max:200'],
            'issue_nfse' => ['nullable', 'boolean'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.professional_id' => ['nullable', 'string', 'size:26'],
            'items.*.description' => ['required', 'string', 'max:200'],
            'items.*.amount' => ['required', 'string', 'max:20'],
            'items.*.split_type' => ['nullable', Rule::in(['percent', 'fixed'])],
            'items.*.split_value' => ['nullable', 'string', 'max:20'],
        ], [], ['items.*.description' => 'descrição do item', 'items.*.amount' => 'valor do item']);

        $items = [];
        foreach ($data['items'] as $i => $row) {
            $amount = Format::parseMoney($row['amount']);
            if ($amount === null || $amount <= 0) {
                throw ValidationException::withMessages(["items.{$i}.amount" => 'Valor do item '.($i + 1).' inválido.']);
            }
            $split = isset($row['split_value']) && $row['split_value'] !== '' ? Format::parseMoney($row['split_value']) : null;
            $items[] = ['professional_id' => $row['professional_id'] ?? null, 'description' => $row['description'], 'amount_cents' => $amount,
                'split_type' => $row['split_type'] ?? null, 'split_value' => $split];
        }

        $sale = $this->sprint->createSale($request->user(), ['items' => $items] + $data);

        return redirect()->route('sprint.sales.show', $sale)->with('success', 'Venda '.$sale->code().' criada. Escolha a forma de pagamento.');
    }

    public function show(Request $request, SprintSale $sale): View
    {
        $this->authorizeBranch($sale);
        $sale = $this->sprint->refresh($sale);
        $sale->load(['items.professional', 'patient', 'branch:id,name', 'receivable:id,paid_cents,amount_cents,discount_cents,status',
            'payments' => fn ($q) => $q->with('charge')->orderBy('created_at'), 'invoices' => fn ($q) => $q->latest(),
            'splits' => fn ($q) => $q->with('professional:id,name')->orderBy('created_at')]);

        $qr = [];
        foreach ($sale->payments as $p) {
            if ($p->status === 'pending' && $p->charge) {
                $qr[$p->id] = $p->charge->pix_qr_image ? 'data:image/png;base64,'.$p->charge->pix_qr_image
                    : ($p->charge->pix_payload ? app(DocumentService::class)->qrDataUri($p->charge->pix_payload)
                        : app(DocumentService::class)->qrDataUri($p->charge->payment_url ?? $p->charge->publicUrl()));
            }
        }

        return view('sprint.show', [
            'sale' => $sale, 'qr' => $qr, 'settings' => SprintSetting::current(),
            'available' => $sale->total_cents - (int) app(SprintLedger::class)->activePayments($sale)->sum('amount_cents'),
            'gateways' => PaymentGateway::query()->where('is_active', true)->get(['id', 'provider', 'mode', 'name'])->groupBy('provider'),
            'openSession' => $this->finance->openSessionOf($request->user()),
        ]);
    }

    /** Situação para a tela de confirmação (consulta periódica do navegador). */
    public function status(SprintSale $sale): JsonResponse
    {
        $this->authorizeBranch($sale);
        $sale = $this->sprint->refresh($sale);
        $payments = SprintPayment::query()->where('sale_id', $sale->id)->orderBy('created_at')->get(['id', 'status', 'amount_cents', 'method', 'channel']);
        $paid = (int) $payments->where('status', 'paid')->sum('amount_cents');

        return response()->json([
            'sale' => ['id' => $sale->id, 'status' => $sale->status, 'status_label' => $sale->statusLabel(), 'total_cents' => $sale->total_cents,
                'paid_cents' => $paid, 'balance_cents' => $sale->total_cents - $paid],
            'payments' => $payments->map(fn ($p) => ['id' => $p->id, 'status' => $p->status, 'status_label' => SprintPayment::STATUSES[$p->status],
                'amount' => Format::money($p->amount_cents), 'label' => $p->label()])->values(),
        ]);
    }

    public function pay(Request $request, SprintSale $sale): RedirectResponse
    {
        $this->authorizeBranch($sale);
        $data = $request->validate([
            'method' => ['required', Rule::in(array_keys(SprintPayment::METHODS))],
            'channel' => ['required', Rule::in(array_keys(SprintPayment::CHANNELS))],
            'card_brand' => ['nullable', 'string', 'max:30'],
            'installments' => ['nullable', 'integer', 'min:1', 'max:24'],
            'nsu' => ['nullable', 'string', 'max:60'],
            'terminal_split' => ['nullable', 'boolean'],
            'idempotency_key' => ['required', 'string', 'min:16', 'max:64'],
        ], [], ['nsu' => 'NSU / autorização']);
        $data['amount_cents'] = $this->cents($request, 'amount');
        $data['cash_given_cents'] = $data['method'] === 'cash' ? $this->cents($request, 'cash_given', false, 0, 'valor entregue') : null;
        if ($data['cash_given_cents'] === null) {
            unset($data['cash_given_cents']);
        }

        $payment = $this->sprint->addPayment($request->user(), $sale, $data);
        $msg = match (true) {
            $payment->status === 'paid' && $payment->change_cents => 'Pagamento confirmado. Troco: '.Format::money($payment->change_cents).'.',
            $payment->status === 'paid' => 'Pagamento confirmado.',
            $payment->method === 'pix' => 'PIX gerado. Mostre o QR Code ao cliente — a confirmação aparece nesta tela.',
            default => 'Cobrança gerada. A confirmação aparece nesta tela assim que o gateway aprovar.',
        };

        return redirect()->route('sprint.sales.show', $sale)->with('success', $msg)->with('confirmed', $payment->status === 'paid' ? $payment->id : null);
    }

    public function syncPayment(SprintPayment $payment): RedirectResponse
    {
        $sale = $payment->sale()->firstOrFail();
        $this->authorizeBranch($sale);
        $this->sprint->refresh($sale, force: true);

        return back()->with('success', 'Situação consultada no gateway: '.SprintPayment::STATUSES[$payment->fresh()->status].'.');
    }

    public function cancelPayment(Request $request, SprintPayment $payment): RedirectResponse
    {
        $this->authorizeBranch($payment->sale()->firstOrFail());
        $this->sprint->cancelPayment($request->user(), $payment->load('charge'));

        return back()->with('success', 'Cobrança cancelada — o valor voltou a ficar disponível.');
    }

    public function simulate(Request $request, SprintPayment $payment): RedirectResponse
    {
        abort_if(app()->environment('production') && ! config('aivexa.payments.allow_mock_in_production'), 403);
        $this->authorizeBranch($payment->sale()->firstOrFail());
        abort_unless($payment->charge_id && $payment->status === 'pending', 404);

        $this->payments->simulateMock($request->user(), PaymentCharge::query()->findOrFail($payment->charge_id), 'paid');

        return back()->with('success', 'MOCK: pagamento simulado pelo mesmo fluxo do webhook.')->with('confirmed', $payment->id);
    }

    public function cancel(Request $request, SprintSale $sale): RedirectResponse
    {
        $this->authorizeBranch($sale);
        $reason = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:255']], [], ['reason' => 'motivo'])['reason'];
        $this->sprint->cancelSale($request->user(), $sale, $reason);

        return redirect()->route('sprint.sales.show', $sale)->with('success', 'Venda cancelada.');
    }

    public function refund(Request $request, SprintSale $sale): RedirectResponse
    {
        $this->authorizeBranch($sale);
        $reason = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:230']], [], ['reason' => 'motivo'])['reason'];
        $r = $this->sprint->refundSale($request->user(), $sale, $reason);

        return back()->with('success', trim(($r['reversed'] ? "{$r['reversed']} pagamento(s) estornado(s) e divisão desfeita. " : '')
            .($r['requested'] ? "{$r['requested']} estorno(s) solicitado(s) ao gateway — a baixa acontece quando o gateway confirmar." : '')));
    }

    public function issueNfse(Request $request, SprintSale $sale): RedirectResponse
    {
        $this->authorizeBranch($sale);
        $invoice = $this->nfse->issue($request->user(), $sale);

        return back()->with('success', 'NFS-e enviada sobre a parte da clínica ('.Format::money($invoice->value_cents).'): '.mb_strtolower(SprintInvoice::STATUSES[$invoice->status]).'.');
    }

    public function syncNfse(SprintInvoice $invoice): RedirectResponse
    {
        $this->authorizeBranch($invoice->sale()->firstOrFail());
        $invoice = $this->nfse->sync($invoice);

        return back()->with('success', 'NFS-e: '.mb_strtolower(SprintInvoice::STATUSES[$invoice->status]).'.');
    }

    public function cancelNfse(Request $request, SprintInvoice $invoice): RedirectResponse
    {
        $this->authorizeBranch($invoice->sale()->firstOrFail());
        $invoice = $this->nfse->cancel($request->user(), $invoice);

        return back()->with('success', 'Cancelamento da NFS-e: '.mb_strtolower(SprintInvoice::STATUSES[$invoice->status]).'.');
    }

    /** Comprovante: via do cliente (sem a divisão) ou via da clínica (?via=clinica, com a parte de cada profissional). */
    public function receipt(Request $request, SprintSale $sale): View
    {
        $this->authorizeBranch($sale);
        $sale->load(['items.professional:id,name', 'patient', 'branch', 'payments' => fn ($q) => $q->where('status', 'paid')->orderBy('paid_at')]);

        return view('sprint.receipt', [
            'sale' => $sale,
            'internal' => $request->query('via') === 'clinica',
            'company' => DB::table('companies')->where('id', $sale->company_id)->first(['trade_name', 'legal_name', 'document']),
        ]);
    }

    private function authorizeBranch(SprintSale $sale): void
    {
        $allowed = $this->context->allowedBranchIds();
        abort_if($allowed !== null && ! in_array($sale->branch_id, $allowed, true), 404);
    }
}
