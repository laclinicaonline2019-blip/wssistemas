@extends('layouts.app', ['title' => 'Pacientes'])

@section('content')
<div class="page-head">
    <div><h1>Pacientes</h1><p>Cadastro compartilhado entre as unidades da empresa.</p></div>
    @if (auth()->user()->hasPermission('paciente.criar'))
        <a class="btn btn-primary" href="{{ route('patients.create') }}"><svg><use href="#i-plus"/></svg>Novo paciente</a>
    @endif
</div>

<section class="card">
    <div class="card__body">
        <form class="toolbar" method="get">
            <div class="field grow"><label for="search">Buscar</label>
                <input id="search" name="search" class="input" value="{{ request('search') }}" placeholder="Nome, CPF, telefone ou nº do prontuário" autofocus></div>
            <div class="field"><label for="status">Situação</label>
                <select id="status" name="status" class="input">
                    <option value="active" @selected(request('status', 'active') === 'active')>Ativos</option>
                    <option value="inactive" @selected(request('status') === 'inactive')>Inativos</option>
                </select></div>
            <button class="btn" type="submit"><svg><use href="#i-search"/></svg>Buscar</button>
        </form>
    </div>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Prontuário</th><th>Paciente</th><th class="hide-sm">CPF</th><th class="hide-sm">Nascimento</th><th class="hide-sm">Contato</th><th></th></tr></thead>
            <tbody>
            @forelse ($patients as $p)
                <tr>
                    <td class="record-no">#{{ $p->record_number }}</td>
                    <td><a href="{{ route('patients.show', $p) }}"><strong>{{ $p->displayName() }}</strong></a>
                        @if ($p->social_name)<div class="small muted">Registro civil: {{ $p->name }}</div>@endif
                        @if ($p->isAnonymized())<span class="badge">anonimizado</span>@endif</td>
                    <td class="hide-sm mono small">{{ \App\Core\Support\Format::cpfMasked($p->cpf) ?? '—' }}</td>
                    <td class="hide-sm small">{{ $p->birth_date?->format('d/m/Y') ?? '—' }} @if ($p->age() !== null)<span class="muted">({{ $p->age() }} anos)</span>@endif</td>
                    <td class="hide-sm small">{{ \App\Core\Support\Format::phone($p->whatsapp ?? $p->phone) ?? '—' }}</td>
                    <td class="actions"><a class="btn btn-sm" href="{{ route('patients.show', $p) }}">Abrir</a></td>
                </tr>
            @empty
                <tr><td colspan="6" class="empty">{{ request('search') ? 'Nenhum paciente encontrado para esta busca.' : 'Nenhum paciente cadastrado.' }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $patients->links() }}
</section>
@endsection
