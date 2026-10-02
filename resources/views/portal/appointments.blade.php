@extends('portal.layout', ['title' => 'Consultas'])

@section('content')
<div class="page-head"><div><h1>Minhas consultas</h1>
    <p>Cancelamento pelo portal até {{ $settings['cancel_hours'] }} horas antes. Depois disso, fale com a clínica.</p></div>
    @if ($settings['booking'])<a class="btn btn-primary" href="{{ route('portal.book') }}">Agendar consulta</a>@endif
</div>

<section class="card mb-2">
    <div class="card__head"><h2>Próximas</h2></div>
    <ul class="portal-list">
        @forelse ($upcoming as $ap)@include('portal._appointment', ['ap' => $ap, 'actions' => true, 'cancelHours' => $settings['cancel_hours']])
        @empty<li class="muted">Nenhuma consulta marcada.</li>@endforelse
    </ul>
</section>

<div class="grid grid-2">
    <section class="card">
        <div class="card__head"><h2>Histórico de atendimentos</h2></div>
        <ul class="portal-list">
            @forelse ($encounters as $e)
                <li><span><strong>{{ $e->started_at->timezone('America/Sao_Paulo')->format('d/m/Y') }}</strong> · {{ $e->doctor->displayName() }}<br><span class="small muted">{{ $e->branch?->name }}</span></span></li>
            @empty<li class="muted">Nenhum atendimento registrado.</li>@endforelse
        </ul>
        <p class="card__body small muted">O conteúdo do prontuário é sigiloso e fica com a equipe médica. Para receber uma cópia, solicite à clínica.</p>
    </section>
    <section class="card">
        <div class="card__head"><h2>Agendamentos anteriores</h2></div>
        <ul class="portal-list">
            @forelse ($past as $ap)@include('portal._appointment', ['ap' => $ap])
            @empty<li class="muted">Nenhum.</li>@endforelse
        </ul>
    </section>
</div>
@endsection
