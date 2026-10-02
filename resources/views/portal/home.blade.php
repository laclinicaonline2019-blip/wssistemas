@extends('portal.layout', ['title' => 'Início'])

@php use App\Core\Support\Format; @endphp

@section('content')
<div class="grid grid-2">
    <section class="card">
        <div class="card__head"><h2>Próximas consultas</h2>@if ($company->setting('portal.booking_enabled', true))<a class="btn btn-sm btn-primary" href="{{ route('portal.book') }}">Agendar</a>@endif</div>
        <ul class="portal-list">
            @forelse ($upcoming as $ap)@include('portal._appointment', ['ap' => $ap, 'actions' => true, 'cancelHours' => (int) $company->setting('portal.cancel_min_hours', 24)])
            @empty<li class="muted">Nenhuma consulta marcada.</li>@endforelse
        </ul>
    </section>
    <section class="card">
        <div class="card__head"><h2>Pagamentos em aberto</h2><a class="btn btn-sm" href="{{ route('portal.payments') }}">Ver todos</a></div>
        <ul class="portal-list">
            @forelse ($openBills as $r)
                <li><span>{{ $r->description }}<br><span class="small muted">vencimento {{ $r->due_date->format('d/m/Y') }}</span></span><strong>{{ Format::money($r->balanceCents()) }}</strong></li>
            @empty<li class="muted">Nada em aberto.</li>@endforelse
        </ul>
    </section>
    <section class="card">
        <div class="card__head"><h2>Documentos recentes</h2><a class="btn btn-sm" href="{{ route('portal.documents') }}">Ver todos</a></div>
        <ul class="portal-list">
            @forelse ($recentDocs as $d)
                <li><span>{{ $d->typeLabel() }}<br><span class="small muted">{{ $d->issued_at->timezone('America/Sao_Paulo')->format('d/m/Y') }} · {{ $d->doctor?->displayName() }}</span></span>
                    <a class="btn btn-sm" href="{{ route('portal.documents.pdf', $d) }}">Baixar PDF</a></li>
            @empty<li class="muted">Nenhum documento ainda.</li>@endforelse
        </ul>
    </section>
</div>
@endsection
