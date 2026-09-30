@extends('layouts.app', ['title' => 'Busca'])

@php use App\Core\Support\Format; $total = collect($results)->sum(fn ($c) => $c->count()); @endphp

@section('content')
<div class="page-head"><div><h1>Busca</h1><p>@if ($q) {{ $total }} resultado(s) para “{{ $q }}” @else Digite ao menos 2 caracteres. @endif</p></div></div>

<form method="get" action="{{ route('search') }}" class="card"><div class="card__body toolbar">
    <div class="field grow"><label for="q">Buscar</label><input id="q" name="q" class="input" value="{{ $q }}" autofocus placeholder="Nome, CPF, telefone, nº do prontuário, CRM, e-mail"></div>
    <button class="btn btn-primary" type="submit">Buscar</button>
</div></form>

@if ($results['patients']->isNotEmpty())
    <section class="card"><div class="card__head"><h2>Pacientes</h2></div>
        <div class="table-wrap"><table class="table"><tbody>
            @foreach ($results['patients'] as $p)
                <tr><td class="record-no">#{{ $p->record_number }}</td>
                    <td><a href="{{ route('patients.show', $p) }}"><strong>{{ $p->displayName() }}</strong></a></td>
                    <td class="small mono hide-sm">{{ Format::cpfMasked($p->cpf) }}</td>
                    <td class="small">{{ $p->birth_date?->format('d/m/Y') }}</td></tr>
            @endforeach
        </tbody></table></div></section>
@endif

@if ($results['doctors']->isNotEmpty())
    <section class="card"><div class="card__head"><h2>Médicos</h2></div>
        <div class="table-wrap"><table class="table"><tbody>
            @foreach ($results['doctors'] as $d)
                <tr><td><a href="{{ route('doctors.edit', $d) }}"><strong>{{ $d->displayName() }}</strong></a></td>
                    <td class="small">{{ $d->registration() }}</td><td class="small">{{ $d->specialties->pluck('name')->implode(', ') }}</td></tr>
            @endforeach
        </tbody></table></div></section>
@endif

@if ($results['users']->isNotEmpty())
    <section class="card"><div class="card__head"><h2>Usuários</h2></div>
        <div class="table-wrap"><table class="table"><tbody>
            @foreach ($results['users'] as $u)
                <tr><td><a href="{{ route('users.edit', $u) }}"><strong>{{ $u->name }}</strong></a></td><td class="small">{{ $u->email }}</td></tr>
            @endforeach
        </tbody></table></div></section>
@endif

@if ($q && $total === 0)
    <section class="card"><div class="empty">Nada encontrado. A busca respeita suas permissões de acesso.</div></section>
@endif
@endsection
