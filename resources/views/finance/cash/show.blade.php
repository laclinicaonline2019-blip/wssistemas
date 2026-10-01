@extends('layouts.app', ['title' => 'Caixa'])

@php use App\Core\Support\Format; $tz = 'America/Sao_Paulo'; @endphp

@section('content')
@include('finance._tabs')
<div class="page-head">
    <div><h1>Caixa de {{ $session->operator->name }} — {{ $session->branch->name }}</h1>
        <p>Aberto em {{ $session->opened_at->timezone($tz)->format('d/m/Y H:i') }}{{ $session->closed_at ? ' · fechado em '.$session->closed_at->timezone($tz)->format('d/m/Y H:i') : '' }}
            · fundo de troco {{ Format::money($session->opening_cents) }}
            <span class="badge {{ ['open' => 'badge-info', 'closed' => 'badge-warning', 'reviewed' => 'badge-success'][$session->status] }}">{{ \App\Modules\Finance\Models\CashSession::STATUSES[$session->status] }}</span></p></div>
    <div class="row">
        <a class="btn" href="{{ route('cash.print', $session) }}" target="_blank" rel="noopener"><svg><use href="#i-printer"/></svg>Térmica</a>
        <a class="btn" href="{{ route('cash.print', [$session, 'format' => 'a4']) }}" target="_blank" rel="noopener"><svg><use href="#i-printer"/></svg>A4</a>
    </div>
</div>

@if ($session->status !== 'open')
<section class="card">
    <div class="card__head"><h2>Fechamento</h2>
        @if ($session->difference_cents === 0)<span class="badge badge-success">sem diferença</span>@else<span class="badge badge-danger">diferença {{ Format::money($session->difference_cents) }}</span>@endif</div>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Forma</th><th class="t-right">Esperado</th><th class="t-right">Declarado</th><th class="t-right">Diferença</th></tr></thead>
        <tbody>
        @foreach ($session->expected as $m => $exp)
            @php $dec = $session->declared[$m] ?? 0; @endphp
            <tr><td>{{ $methods[$m] ?? $m }}</td><td class="t-right">{{ Format::money($exp) }}</td><td class="t-right">{{ Format::money($dec) }}</td>
                <td class="t-right {{ $dec - $exp !== 0 ? 'text-danger' : '' }}">{{ Format::money($dec - $exp) }}</td></tr>
        @endforeach
        </tbody>
    </table></div>
    <div class="card__body">
        @if ($session->closing_notes)<p class="small"><strong>Observação do operador:</strong> {{ $session->closing_notes }}</p>@endif
        @if ($session->status === 'reviewed')
            <p class="small"><strong>Conferido</strong> por {{ $session->reviewer?->name }} em {{ $session->reviewed_at->timezone($tz)->format('d/m/Y H:i') }}{{ $session->review_notes ? ' — '.$session->review_notes : '' }}</p>
        @elseif ($canReview)
            <form method="post" action="{{ route('cash.review', $session) }}" class="stack">
                @csrf
                <label class="label" for="rv-n">Conferência {{ $session->difference_cents ? '(justificativa obrigatória)' : '' }}</label>
                <textarea id="rv-n" name="notes" class="input" rows="2" maxlength="500" @if ($session->difference_cents) required minlength="10" @endif placeholder="Ex.: diferença de troco devolvida ao paciente; valor recolhido ao cofre"></textarea>
                <div><button class="btn btn-primary" type="submit">Confirmar conferência</button></div>
            </form>
        @else
            <p class="small muted">Aguardando conferência por um supervisor (não pode ser o próprio operador).</p>
        @endif
    </div>
</section>
@endif

<section class="card mt-2">
    <div class="card__head"><h2>Movimentações</h2><span class="small muted">Entradas {{ Format::money($summary['total_in']) }} · saídas {{ Format::money($summary['total_out']) }}</span></div>
    @include('finance._transactions', ['transactions' => $transactions, 'showOrigin' => true])
</section>
@endsection
