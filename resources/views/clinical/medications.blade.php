@extends('layouts.app', ['title' => 'Medicamentos'])

@php use App\Modules\Clinical\Models\Medication; @endphp

@section('content')
<div class="page-head">
    <div><h1>Medicamentos</h1><p>Base global da plataforma (somente leitura) + cadastros próprios da clínica. Usada nas receitas (Fase 6).</p></div>
    <form method="get" class="row"><label class="sr-only" for="mq">Buscar</label>
        <input id="mq" name="q" class="input w-auto" value="{{ request('q') }}" placeholder="Princípio ativo ou nome comercial"><button class="btn" type="submit">Buscar</button></form>
</div>

<details class="card mb-2" @if ($errors->any()) open @endif>
    <summary class="card__head"><h2>Cadastrar medicamento da clínica</h2></summary>
    <form method="post" action="{{ route('medications.store') }}">
        @csrf
        <div class="card__body form-grid">@include('clinical._medication-fields', ['m' => null, 'prefix' => 'n'])</div>
        <div class="card__body form-actions"><button class="btn btn-primary" type="submit">Cadastrar</button></div>
    </form>
</details>

<section class="card">
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Medicamento</th><th class="hide-sm">Posologia padrão</th><th>Controle</th><th>Origem</th><th></th></tr></thead>
        <tbody>
        @forelse ($medications as $m)
            <tr class="{{ $m->is_active ? '' : 'muted' }}">
                <td>{{ $m->label() }}@if ($m->is_sample) <span class="badge badge-warning" title="Dado de exemplo — substitua pela base oficial">exemplo</span>@endif</td>
                <td class="hide-sm small">{{ $m->default_posology ?? '—' }}</td>
                <td>@if ($m->isControlled())<span class="badge badge-danger" title="{{ Medication::CONTROL_TYPES[$m->control_type] }}">{{ $m->control_type === 'antimicrobial' ? 'antimicrobiano' : $m->control_type }}</span>@else<span class="small muted">livre</span>@endif</td>
                <td class="small">{{ $m->isGlobal() ? 'Base global' : 'Clínica' }}{{ $m->is_active ? '' : ' · inativo' }}</td>
                <td class="actions">
                    @unless ($m->isGlobal())
                        <details><summary class="btn btn-sm">Editar</summary>
                            <form method="post" action="{{ route('medications.update', $m) }}" class="card mt-1">
                                @csrf @method('put')
                                <div class="card__body form-grid">@include('clinical._medication-fields', ['m' => $m, 'prefix' => 'e'.$m->id])
                                    <label class="check col-12"><input type="checkbox" name="is_active" value="1" @checked($m->is_active)><span>Ativo</span></label></div>
                                <div class="card__body form-actions"><button class="btn btn-primary btn-sm" type="submit">Salvar</button></div>
                            </form>
                        </details>
                    @endunless
                </td>
            </tr>
        @empty
            <tr><td colspan="5" class="empty">Nenhum medicamento encontrado.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    {{ $medications->links() }}
</section>
@endsection
