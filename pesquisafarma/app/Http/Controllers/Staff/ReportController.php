<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Pharmacy;
use App\Models\Receipt;
use App\Services\ReportService;
use App\Support\Text;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public const REPORTS = [
        'farmacias' => 'Vendas por farmácia',
        'clientes' => 'Compras por cliente',
        'cotado-comprado' => 'Cotado × comprado',
        'televendas' => 'Televendas',
        'tempos' => 'Tempos e avaliações',
        'extrato' => 'Extrato detalhado',
    ];

    public function show(Request $request, string $report = 'farmacias')
    {
        abort_unless(array_key_exists($report, self::REPORTS), 404);
        $svc = ReportService::fromRequest();
        $data = ['report' => $report, 'svc' => $svc, 'pharmacies' => Pharmacy::orderBy('trade_name')->get()];

        switch ($report) {
            case 'farmacias':
                $data['rows'] = $svc->byPharmacy($request->integer('farmacia') ?: null);
                $csv = fn () => $data['rows']->map(fn ($r) => [$r['pharmacy']->trade_name, $r['pharmacy']->cnpj, $r['quotes_received'], $r['quotes_answered'], $r['orders'],
                    $r['orders_total'], $r['receipts'], $r['receipts_total'], $r['commission_base'], $r['percent'], $r['commission'], $r['cycle']]);
                $head = ['Farmácia', 'CNPJ', 'Cotações recebidas', 'Respondidas', 'Pedidos', 'Total pedidos', 'Notas', 'Total notas', 'Base comissão', '%', 'Comissão', 'Ciclo'];
                break;
            case 'clientes':
                $data['rows'] = $svc->byCustomer($request->q);
                $csv = fn () => $data['rows']->map(fn ($r) => [$r['customer']->name, Text::formatPhone($r['customer']->phone), $r['customer']->cpf, $r['customer']->neighborhood?->name,
                    $r['quotes'], $r['orders'], $r['orders_total'], $r['receipts'], $r['receipts_total'], $r['customer']->points_balance]);
                $head = ['Cliente', 'Telefone', 'CPF', 'Bairro', 'Cotações', 'Pedidos', 'Total pedidos', 'Notas', 'Total notas', 'Pontos'];
                break;
            case 'cotado-comprado':
                $data['rows'] = $svc->quotedVsBought();
                $csv = fn () => $data['rows']->map(fn ($r) => [$r['receipt']->code, $r['receipt']->customer->name, $r['receipt']->pharmacy?->trade_name, $r['receipt']->order?->code, $r['quoted'], $r['bought'], $r['difference'], $r['extra_items_total']]);
                $head = ['Nota', 'Cliente', 'Farmácia', 'Pedido', 'Cotado', 'Comprado', 'Diferença', 'Itens fora da cotação'];
                break;
            case 'televendas':
                $data['rows'] = $svc->operators();
                $csv = fn () => $data['rows']->map(fn ($r) => [$r['user']->name, $r['attendances'], $r['orders'], $r['sales_total'], $r['conversion'], $r['avg_to_dispatch'], $r['avg_to_present'], $r['avg_to_close'], $r['feedback_avg'], $r['nps'], $r['actions'], $r['messages']]);
                $head = ['Televendas', 'Atendimentos', 'Pedidos', 'Vendas', 'Conversão %', 'Min. até envio', 'Min. até apresentar', 'Min. até finalizar', 'Nota média', 'NPS', 'Ações', 'Mensagens'];
                break;
            case 'tempos':
                $data['times'] = $svc->times();
                $data['rows'] = collect();
                break;
            case 'extrato':
                $orders = Order::query()->with(['customer', 'pharmacy'])->whereBetween('created_at', [$svc->from, $svc->to])
                    ->when($request->farmacia, fn ($q, $p) => $q->where('pharmacy_id', $p))->when($request->cliente, fn ($q, $c) => $q->where('customer_id', $c))->get()
                    ->map(fn ($o) => ['date' => $o->created_at, 'type' => 'Pedido', 'code' => $o->code, 'customer' => $o->customer, 'pharmacy' => $o->pharmacy, 'status' => $o->status, 'total' => $o->total, 'receipt_total' => $o->receipt_total, 'commission' => $o->commission_amount]);
                $receipts = Receipt::query()->with(['customer', 'pharmacy'])->where('status', 'conferida')->whereNull('order_id')->whereBetween('reviewed_at', [$svc->from, $svc->to])
                    ->when($request->farmacia, fn ($q, $p) => $q->where('pharmacy_id', $p))->when($request->cliente, fn ($q, $c) => $q->where('customer_id', $c))->get()
                    ->map(fn ($r) => ['date' => $r->reviewed_at, 'type' => $r->source === 'farmacia' ? 'Cartão no balcão (farmácia)' : 'Compra com cartão (sem cotação)', 'code' => $r->code, 'customer' => $r->customer, 'pharmacy' => $r->pharmacy, 'status' => $r->status, 'total' => 0, 'receipt_total' => $r->total, 'commission' => $r->commission_amount]);
                $data['rows'] = $orders->concat($receipts)->sortByDesc('date')->values();
                $data['customers'] = Customer::query()->whereIn('id', $data['rows']->pluck('customer.id'))->orderBy('name')->get();
                $csv = fn () => $data['rows']->map(fn ($r) => [$r['date']->format('d/m/Y H:i'), $r['type'], $r['code'], $r['customer']->name, $r['pharmacy']?->trade_name, $r['status'], $r['total'], $r['receipt_total'], $r['commission']]);
                $head = ['Data', 'Tipo', 'Código', 'Cliente', 'Farmácia', 'Situação', 'Total pedido', 'Total nota', 'Comissão'];
                break;
        }

        if ($request->boolean('csv') && isset($csv)) {
            return $this->csv($report, $head, $csv());
        }

        return view('staff.reports.show', $data);
    }

    private function csv(string $name, array $head, $rows)
    {
        return response()->streamDownload(function () use ($head, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $head, ';');
            foreach ($rows as $row) {
                fputcsv($out, array_map(fn ($v) => is_float($v) ? number_format($v, 2, ',', '') : $v, $row), ';');
            }
            fclose($out);
        }, 'relatorio-'.$name.'-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
