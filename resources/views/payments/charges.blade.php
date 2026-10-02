@extends('layouts.app', ['title' => 'Cobranças online'])

@php use App\Core\Support\Format; use App\Modules\Payments\Models\PaymentCharge; $tz = 'America/Sao_Paulo'; @endphp

@section('content')
@include('finance._tabs')
<div class="page-head">
    <div><h1>Cobranças online</h1><p>Links de pagamento (PIX, boleto, cartão). Situação atualizada pelos avisos do gateway e pela consulta automática.</p></div>
    <form method="get"><label class="sr-only" for="cs">Situação</label>
        <select id="cs" name="status" class="input w-auto" data-autosubmit><option value="all">Todas</option>
            @foreach (PaymentCharge::STATUSES as $k => $l)<option value="{{ $k }}" @selected($status === $k)>{{ $l }}</option>@endforeach</select></form>
</div>

<section class="card">
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Criada</th><th>Paciente / descrição</th><th>Gateway</th><th class="t-right">Valor</th><th>Situação</th><th></th></tr></thead>
        <tbody>
        @forelse ($charges as $c)
            <tr>
                <td class="small nowrap">{{ $c->created_at->timezone($tz)->format('d/m/Y H:i') }}</td>
                <td>{{ $c->receivable->patient?->displayName() ?? '—' }}<div class="small muted">{{ $c->receivable->description }}</div></td>
                <td class="small">{{ $c->gateway->name }} @if ($c->modeBadge())<span class="badge badge-warning">{{ $c->modeBadge() }}</span>@endif<div class="muted">{{ PaymentCharge::BILLING_TYPES[$c->billing_type] }}</div></td>
                <td class="t-right nowrap">{{ Format::money($c->amount_cents) }}</td>
                <td><span class="badge {{ ['paid' => 'badge-success', 'pending' => 'badge-warning', 'review' => 'badge-danger', 'overdue' => 'badge-danger', 'failed' => 'badge-danger'][$c->status] ?? '' }}">{{ PaymentCharge::STATUSES[$c->status] }}</span></td>
                <td class="actions"><a class="btn btn-sm" href="{{ route('receivables.show', $c->receivable_id) }}">Abrir</a></td>
            </tr>
        @empty
            <tr><td colspan="6" class="empty">Nenhuma cobrança online.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    {{ $charges->links() }}
</section>
@endsection
