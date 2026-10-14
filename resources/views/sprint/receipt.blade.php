@php
    use App\Core\Support\Format;
    use App\Modules\Sprint\Models\SprintPayment;
    $tz = 'America/Sao_Paulo';
    $paid = (int) $sale->payments->sum('amount_cents');
@endphp
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">
    <title>Comprovante {{ $sale->code() }} · Melhor Sprint</title>
    <link rel="stylesheet" href="{{ asset('assets/css/app.css') }}?v={{ filemtime(public_path('assets/css/app.css')) }}">
    <link rel="stylesheet" href="{{ asset('assets/css/sprint.css') }}?v={{ filemtime(public_path('assets/css/sprint.css')) }}">
    <script src="{{ asset('assets/js/sprint.js') }}?v={{ filemtime(public_path('assets/js/sprint.js')) }}" defer></script>
</head>
<body class="ms-receipt-page">
<main class="ms-receipt">
    <img src="{{ asset('assets/img/melhor-sprint.webp') }}" alt="Melhor Sprint — sistema de pagamento" width="180" height="120">
    <p class="t-center"><strong>{{ $company->trade_name }}</strong><br>{{ $company->legal_name }} · CNPJ {{ Format::cnpj($company->document) }}<br>{{ $sale->branch->name }}</p>
    <hr>
    <table>
        <tr><td>Venda</td><td class="t-right"><strong>{{ $sale->code() }}</strong></td></tr>
        <tr><td>Data</td><td class="t-right">{{ $sale->created_at->timezone($tz)->format('d/m/Y H:i') }}</td></tr>
        <tr><td>Cliente</td><td class="t-right">{{ $sale->customerLabel() }}</td></tr>
    </table>
    <hr>
    <table>
        @foreach ($sale->items as $i)
            <tr><td>{{ $i->description }}@if ($i->professional)<br><small>{{ $i->professional->name }}</small>@endif</td><td class="t-right">{{ Format::money($i->amount_cents) }}</td></tr>
        @endforeach
        <tr><td><strong>TOTAL</strong></td><td class="t-right"><strong>{{ Format::money($sale->total_cents) }}</strong></td></tr>
    </table>
    <hr>
    <table>
        @forelse ($sale->payments as $p)
            <tr><td>{{ SprintPayment::METHODS[$p->method] }}@if ($p->installments > 1) {{ $p->installments }}x @endif @if ($p->nsu)<br><small>NSU {{ $p->nsu }}</small>@endif</td><td class="t-right">{{ Format::money($p->amount_cents) }}</td></tr>
            @if ($p->change_cents)<tr><td><small>Troco</small></td><td class="t-right"><small>{{ Format::money($p->change_cents) }}</small></td></tr>@endif
        @empty
            <tr><td colspan="2">Nenhum pagamento confirmado.</td></tr>
        @endforelse
        <tr><td>Pago</td><td class="t-right">{{ Format::money($paid) }}</td></tr>
        @if ($paid < $sale->total_cents)<tr><td>Saldo</td><td class="t-right">{{ Format::money($sale->total_cents - $paid) }}</td></tr>@endif
    </table>
    @if ($internal)
    <hr>
    <p><strong>Divisão dos valores (via da clínica)</strong></p>
    <table>
        @foreach ($sale->items->whereNotNull('professional_id')->groupBy('professional_id') as $proId => $items)
            <tr><td>{{ $items->first()->professional->name }}</td><td class="t-right">{{ Format::money((int) $items->sum('professional_cents')) }}</td></tr>
        @endforeach
        <tr><td>Clínica</td><td class="t-right">{{ Format::money($sale->house_cents) }}</td></tr>
    </table>
    @endif
    <hr>
    <p class="t-center"><small>Documento sem valor fiscal. Melhor Sprint — sistema de pagamento.</small></p>
    <div class="ms-receipt__actions row">
        <button type="button" class="btn btn-sprint" data-print-receipt>Imprimir</button>
        <a class="btn" href="{{ route('sprint.sales.receipt', [$sale, 'via' => $internal ? 'cliente' : 'clinica']) }}">{{ $internal ? 'Via do cliente' : 'Via da clínica (com divisão)' }}</a>
    </div>
</main>
</body>
</html>
