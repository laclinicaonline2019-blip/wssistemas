@extends('layouts.app', ['title' => 'Triagem'])

@php $tz = $branch->timezone; @endphp

@section('content')
<div class="page-head" data-autorefresh="30">
    <div><h1>Triagem — {{ $branch->name }}</h1><p>Pacientes que já chegaram hoje · atualiza automaticamente</p></div>
</div>

<section class="card">
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Chegada</th><th>Paciente</th><th class="hide-sm">Médico</th><th>Triagem</th><th></th></tr></thead>
        <tbody>
        @forelse ($appointments as $a)
            <tr>
                <td class="nowrap">{{ $a->arrived_at?->timezone($tz)->format('H:i') ?? '—' }}@if ($a->ticket)<div><span class="ticket-code">{{ $a->ticket->code }}</span></div>@endif</td>
                <td>{{ $a->patient->displayName() }} <span class="record-no small">#{{ $a->patient->record_number }}</span>
                    <div class="small muted">{{ $a->patient->age() !== null ? $a->patient->age().' anos · ' : '' }}agendado {{ $a->starts_at->timezone($tz)->format('H:i') }}</div></td>
                <td class="hide-sm small">{{ $a->doctor->displayName() }}</td>
                <td>@if (isset($triaged[$a->id]))<span class="badge risk-{{ $triaged[$a->id] ?? 'none' }}">{{ $triaged[$a->id] ? ucfirst($triaged[$a->id]) : 'feita' }}</span>@else<span class="badge badge-warning">pendente</span>@endif</td>
                <td class="actions"><a class="btn btn-sm {{ isset($triaged[$a->id]) ? '' : 'btn-primary' }}" href="{{ route('triage.create', ['patient_id' => $a->patient_id, 'appointment_id' => $a->id]) }}">{{ isset($triaged[$a->id]) ? 'Nova triagem' : 'Triar' }}</a></td>
            </tr>
        @empty
            <tr><td colspan="5" class="empty">Nenhum paciente aguardando. Pacientes aparecem aqui após o check-in na recepção.</td></tr>
        @endforelse
        </tbody>
    </table></div>
</section>
@endsection
