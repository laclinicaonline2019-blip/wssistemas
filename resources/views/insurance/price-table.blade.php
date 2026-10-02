@extends('layouts.app', ['title' => $table->name])

@php use App\Core\Support\Format; $can = auth()->user()->hasPermission('convenio.gerenciar'); @endphp

@section('content')
@include('insurance._tabs')
<div class="page-head">
    <div><h1>{{ $table->name }}</h1>
        <p>{{ $table->insurer->name }} · {{ $table->plan?->name ?? 'todos os planos' }} · vigência {{ $table->valid_from->format('d/m/Y') }} a {{ $table->valid_until?->format('d/m/Y') ?? 'indeterminado' }}
            @unless ($table->is_active)<span class="badge">inativa</span>@endunless</p></div>
    <a class="btn" href="{{ route('insurers.show', $table->insurer) }}">Voltar</a>
</div>

<section class="card mb-2">
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Código</th><th>Procedimento</th><th class="t-right">Valor</th><th>Autorização prévia</th><th>Coparticipação</th><th></th></tr></thead>
        <tbody>
        @forelse ($items as $it)
            <tr>
                <td class="mono">{{ $it->procedure->code }}</td>
                <td>{{ $it->procedure->name }} <span class="small muted">· {{ $it->procedure->kindLabel() }}</span></td>
                <td class="t-right">{{ Format::money($it->price_cents) }}</td>
                <td>{!! $it->requires_authorization ? '<span class="badge badge-warning">exige</span>' : '<span class="muted">não</span>' !!}</td>
                <td>{{ $it->copayLabel() }}</td>
                <td class="actions">@if ($can)<form method="post" action="{{ route('insurers.tables.items.destroy', [$table, $it]) }}" data-confirm="Retirar {{ $it->procedure->code }} da tabela?">@csrf @method('delete')<button class="btn btn-sm btn-ghost" type="submit">Retirar</button></form>@endif</td>
            </tr>
        @empty
            <tr><td colspan="6" class="empty">Nenhum procedimento nesta tabela.</td></tr>
        @endforelse
        </tbody>
    </table></div>
</section>

@if ($can)
<div class="grid grid-2">
    <section class="card">
        <div class="card__head"><h2>Incluir / alterar valor</h2></div>
        <form method="post" action="{{ route('insurers.tables.items.store', $table) }}" class="card__body form-grid">
            @csrf
            <div class="field col-12"><label for="pi-proc">Procedimento</label>
                <select id="pi-proc" name="procedure_id" class="input" required>@foreach ($procedures as $p)<option value="{{ $p->id }}">{{ $p->code }} — {{ $p->name }}</option>@endforeach</select>
                @if ($procedures->isEmpty())<div class="help">Cadastre os procedimentos em <a href="{{ route('procedures.index') }}">Procedimentos (TUSS)</a>.</div>@endif</div>
            <x-field name="price" label="Valor pago pelo convênio (R$)" col="col-6" mask="money" inputmode="numeric" required />
            <label class="check col-6"><input type="checkbox" name="requires_authorization" value="1"><span>Exige autorização prévia (senha)</span></label>
            <div class="field col-6"><label for="pi-copay">Coparticipação do paciente</label>
                <select id="pi-copay" name="copay_type" class="input"><option value="none">Não há</option><option value="percent">Percentual (%)</option><option value="fixed">Valor fixo (R$)</option></select></div>
            <x-field name="copay_value" label="Valor / percentual" col="col-6" mask="money" inputmode="numeric" help="Cobrado do paciente como particular (atendimento misto)." />
            <div class="col-12 form-actions"><button class="btn btn-primary" type="submit">Salvar valor</button></div>
        </form>
    </section>
    <section class="card">
        <div class="card__head"><h2>Vigência</h2></div>
        <form method="post" action="{{ route('insurers.tables.update', $table) }}" class="card__body form-grid">
            @csrf @method('put')
            <x-field name="valid_until" label="Fim da vigência" type="date" col="col-6" :value="$table->valid_until?->toDateString()" />
            <label class="check col-6"><input type="checkbox" name="is_active" value="1" @checked($table->is_active)><span>Tabela ativa</span></label>
            <p class="help col-12">Reajuste: encerre esta tabela (fim da vigência) e crie uma nova a partir do dia seguinte. Guias já criadas mantêm o valor da época.</p>
            <div class="col-12 form-actions"><button class="btn" type="submit">Salvar</button></div>
        </form>
    </section>
</div>
@endif
@endsection
