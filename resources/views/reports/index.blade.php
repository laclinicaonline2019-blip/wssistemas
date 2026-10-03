@extends('layouts.app', ['title' => 'Relatórios'])

@section('content')
<div class="page-head">
    <div><h1>Relatórios</h1><p>Escolha o relatório, filtre o período, a unidade e o médico, e exporte em PDF, Excel ou CSV. Exportações ficam registradas na auditoria.</p></div>
</div>
<div class="grid grid-2">
    @forelse ($groups as $group => $items)
        <section class="card">
            <div class="card__head"><h2>{{ $group }}</h2></div>
            <div class="card__body stack">
                @foreach ($items as $key => $r)
                    <a class="report-link" href="{{ route('reports.show', $key) }}"><strong>{{ $r[0] }}</strong><span class="small muted">{{ $r[3] }}</span></a>
                @endforeach
            </div>
        </section>
    @empty
        <p class="muted">Seu perfil não tem acesso a relatórios.</p>
    @endforelse
    @if ($canClosing)
        <section class="card">
            <div class="card__head"><h2>Médico × clínica</h2></div>
            <div class="card__body stack">
                <a class="report-link" href="{{ route('closings.index') }}"><strong>Fechamento mensal</strong><span class="small muted">Demonstrativo do mês por médico (atendimentos, recebimentos, convênios, repasse), fechado e confirmado pelo médico.</span></a>
            </div>
        </section>
    @endif
</div>
@endsection
