@extends('portal.layout', ['title' => 'Pagamentos'])

@php use App\Core\Support\Format; @endphp

@section('content')
<div class="page-head"><div><h1>Pagamentos</h1><p>Contas da clínica em seu nome. Atendimentos por convênio são cobrados da operadora — aqui aparecem só coparticipações e itens particulares.</p></div></div>
<section class="card">
    <ul class="portal-list">
        @forelse ($receivables as $r)
            <li>
                <span><strong>{{ $r->description }}</strong><br>
                    <span class="small muted">vencimento {{ $r->due_date->format('d/m/Y') }} · {{ Format::money($r->amount_cents) }}{{ $r->discount_cents ? ' · desconto '.Format::money($r->discount_cents) : '' }}</span>
                    @foreach ($r->transactions as $t)
                        <br><span class="small">Pago {{ Format::money($t->amount_cents) }} em {{ $t->occurred_at->timezone('America/Sao_Paulo')->format('d/m/Y') }} ({{ $t->methodLabel() }}) — <a href="{{ route('portal.receipt', $t) }}" target="_blank" rel="noopener">recibo</a></span>
                    @endforeach
                </span>
                <span class="row">
                    <span class="badge {{ ['paid' => 'badge-success', 'partial' => 'badge-info'][$r->status] ?? ($r->isOverdue() ? 'badge-danger' : 'badge-warning') }}">{{ $r->statusLabel() }}</span>
                    @if (in_array($r->status, ['open', 'partial'], true))
                        <strong>{{ Format::money($r->balanceCents()) }}</strong>
                        @if ($charge = $charges[$r->id] ?? null)<a class="btn btn-sm btn-primary" href="{{ route('payments.public', $charge->public_token) }}" target="_blank" rel="noopener">Pagar online</a>@endif
                    @endif
                </span>
            </li>
        @empty<li class="muted">Nenhum pagamento.</li>@endforelse
    </ul>
</section>
@endsection
