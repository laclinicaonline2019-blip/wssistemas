@extends('layouts.app', ['title' => 'Demonstrativo '.$closing->periodLabel()])

@section('content')
<div class="page-head">
    <div><h1>{{ $closing->data['doctor']['name'] }} — {{ $closing->periodLabel() }} <span class="small muted">v{{ $closing->version }}</span></h1>
        <p><span class="badge {{ ['confirmed' => 'badge-success', 'disputed' => 'badge-danger', 'superseded' => ''][$closing->status] ?? 'badge-warning' }}">{{ $closing->statusLabel() }}</span>
            Fechado por {{ $closing->closer?->name }} em {{ $closing->closed_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}
            · @if ($closing->intact())<span class="text-success">integridade conferida</span>@else<span class="text-danger"><strong>ALTERADO após o fechamento</strong></span>@endif
            · <span class="mono small" title="SHA-256">{{ substr($closing->hash, 0, 16) }}…</span></p></div>
    <div class="row">
        <a class="btn" href="{{ route('closings.pdf', $closing) }}">PDF</a>
        @if (auth()->user()->hasPermission('financeiro.fechamento'))<a class="btn" href="{{ route('closings.index', ['period' => $closing->period]) }}">Voltar</a>@else<a class="btn" href="{{ route('closings.mine') }}">Voltar</a>@endif
    </div>
</div>
@if ($closing->payable)<div class="alert alert-info">Repasse lançado em contas a pagar: {{ $closing->payable->description }} — {{ \App\Core\Support\Format::money($closing->payable->amount_cents) }} ({{ $closing->payable->statusLabel() }}).</div>@endif
@if ($closing->responded_at)<div class="alert {{ $closing->status === 'disputed' ? 'alert-error' : 'alert-success' }}">{{ $closing->statusLabel() }} por {{ $closing->responder?->name }} em {{ $closing->responded_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}@if ($closing->response_notes): “{{ $closing->response_notes }}” @endif</div>@endif

@include('closings._statement', ['data' => $closing->data])

@if ($isDoctor && $closing->status === 'closed')
    <section class="card mt-2">
        <div class="card__head"><h2>Sua conferência</h2></div>
        <div class="card__body grid grid-2">
            <form method="post" action="{{ route('closings.respond', $closing) }}" class="stack">@csrf<input type="hidden" name="action" value="confirm">
                <p class="small">Os valores conferem com os seus registros.</p><button class="btn btn-primary" type="submit">Confirmar demonstrativo</button></form>
            <form method="post" action="{{ route('closings.respond', $closing) }}" class="stack">@csrf<input type="hidden" name="action" value="dispute">
                <label for="dn">O que está divergente?</label><textarea id="dn" name="notes" class="input" rows="2" maxlength="1000" required></textarea>
                <button class="btn" type="submit">Contestar</button></form>
        </div>
    </section>
@endif
@endsection
