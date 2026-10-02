@extends('layouts.app', ['title' => 'Procedimentos'])

@php use App\Modules\Insurance\Models\Procedure; @endphp

@section('content')
@include('insurance._tabs')
<div class="page-head">
    <div><h1>Procedimentos (TUSS)</h1><p>Códigos faturados nas guias. Use a Terminologia Unificada (TUSS — tabela 22) publicada pela ANS ou a tabela própria da operadora.</p></div>
    <form method="get" class="row"><label class="sr-only" for="pq">Buscar</label><input id="pq" name="q" value="{{ $q }}" class="input w-auto" placeholder="Código ou descrição"><button class="btn" type="submit">Buscar</button></form>
</div>

<section class="card mb-2">
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Tabela</th><th>Código</th><th>Descrição</th><th>Tipo</th><th></th></tr></thead>
        <tbody>
        @forelse ($procedures as $p)
            <tr class="{{ $p->is_active ? '' : 'muted' }}">
                <td class="mono">{{ $p->table_code }}</td><td class="mono">{{ $p->code }}</td>
                <td>{{ $p->name }} @if ($p->is_sample)<span class="badge badge-warning">exemplo — confira na TUSS</span>@endif</td>
                <td>{{ $p->kindLabel() }}</td>
                <td class="actions"><form method="post" action="{{ route('procedures.toggle', $p) }}">@csrf @method('patch')<button class="btn btn-sm btn-ghost" type="submit">{{ $p->is_active ? 'Desativar' : 'Reativar' }}</button></form></td>
            </tr>
        @empty
            <tr><td colspan="5" class="empty">Nenhum procedimento. Cadastre abaixo ou importe a planilha TUSS.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    @include('partials.pagination', ['paginator' => $procedures])
</section>

<div class="grid grid-2">
    <section class="card">
        <div class="card__head"><h2>Novo procedimento</h2></div>
        <form method="post" action="{{ route('procedures.store') }}" class="card__body form-grid">
            @csrf
            <div class="field col-6"><label for="pr-t">Tabela</label><select id="pr-t" name="table_code" class="input">@foreach (Procedure::TABLES as $k => $l)<option value="{{ $k }}">{{ $k }} — {{ $l }}</option>@endforeach</select></div>
            <x-field name="code" label="Código" col="col-6" maxlength="10" required placeholder="10101012" />
            <x-field name="name" label="Descrição" col="col-12" maxlength="150" required />
            <div class="field col-6"><label for="pr-k">Tipo</label><select id="pr-k" name="kind" class="input">@foreach (Procedure::KINDS as $k => $v)<option value="{{ $k }}">{{ $v['label'] }}</option>@endforeach</select></div>
            <p class="help col-12">Consulta vai em guia de consulta; exames, terapias e pequenos procedimentos, em guia SP/SADT.</p>
            <div class="col-12 form-actions"><button class="btn btn-primary" type="submit">Cadastrar</button></div>
        </form>
    </section>
    <section class="card">
        <div class="card__head"><h2>Importar planilha (CSV)</h2></div>
        <form method="post" action="{{ route('procedures.import') }}" enctype="multipart/form-data" class="card__body form-grid">
            @csrf
            <div class="field col-12"><label for="pr-f">Arquivo CSV</label><input id="pr-f" type="file" name="file" accept=".csv,.txt" class="input" required>
                <div class="help">Uma linha por procedimento: <code>codigo;descricao</code> (opcional <code>;tipo</code> — consultation, exam, therapy, minor_surgery, small_care). Aceita UTF-8 ou ISO-8859-1. Códigos existentes são atualizados.</div></div>
            <div class="field col-6"><label for="pr-ft">Tabela</label><select id="pr-ft" name="table_code" class="input">@foreach (Procedure::TABLES as $k => $l)<option value="{{ $k }}">{{ $k }} — {{ $l }}</option>@endforeach</select></div>
            <div class="col-12 form-actions"><button class="btn" type="submit">Importar</button></div>
        </form>
    </section>
</div>
@endsection
