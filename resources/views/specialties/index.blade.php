@extends('layouts.app', ['title' => 'Especialidades'])

@php $editable = auth()->user()->hasPermission('especialidade.gerenciar'); @endphp

@section('content')
<div class="page-head"><div><h1>Especialidades</h1><p>Usadas no cadastro dos médicos, na agenda e no atendimento por IA.</p></div></div>

<div class="grid grid-3">
    <section class="card span-2">
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Especialidade</th><th class="hide-sm">CBO</th><th>Médicos</th><th>Situação</th>@if ($editable)<th></th>@endif</tr></thead>
            <tbody>
            @forelse ($specialties as $s)
                <tr>
                    @if ($editable)
                        <td colspan="5">
                            <form method="post" action="{{ route('specialties.update', $s) }}" class="row">
                                @csrf @method('put')
                                <label class="sr-only" for="n-{{ $s->id }}">Nome</label>
                                <input id="n-{{ $s->id }}" name="name" class="input w-auto grow" value="{{ $s->name }}" required maxlength="120">
                                <label class="sr-only" for="cbo-{{ $s->id }}">CBO</label>
                                <input id="cbo-{{ $s->id }}" name="cbo_code" class="input input-sm" value="{{ $s->cbo_code }}" placeholder="CBO">
                                <span class="small muted nowrap">{{ $s->doctors_count }} médico(s)</span>
                                <label class="check small"><input type="checkbox" name="is_active" value="1" @checked($s->is_active)><span>ativa</span></label>
                                <button class="btn btn-sm" type="submit">Salvar</button>
                            </form>
                        </td>
                    @else
                        <td>{{ $s->name }}</td><td class="hide-sm">{{ $s->cbo_code ?? '—' }}</td><td>{{ $s->doctors_count }}</td>
                        <td>@if ($s->is_active)<span class="badge badge-success">Ativa</span>@else<span class="badge">Inativa</span>@endif</td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="5" class="empty">Nenhuma especialidade.</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </section>

    @if ($editable)
        <form method="post" action="{{ route('specialties.store') }}" class="card">
            @csrf
            <div class="card__head"><h2>Nova especialidade</h2></div>
            <div class="card__body form-grid">
                <x-field name="name" label="Nome" col="col-12" required maxlength="120" />
                <x-field name="cbo_code" label="Código CBO (opcional)" col="col-12" />
                <x-field name="description" label="Descrição" col="col-12" maxlength="255" />
                <div class="col-12 form-actions"><button class="btn btn-primary" type="submit">Cadastrar</button></div>
            </div>
        </form>
    @endif
</div>
@endsection
