@extends('layouts.app', ['title' => 'Linha do extrato'])

@php use App\Core\Support\Format; @endphp

@section('content')
<div class="page-head">
    <div><h1 class="{{ $line->amount_cents < 0 ? 'text-danger' : 'text-success' }}">{{ Format::money($line->amount_cents) }}</h1>
        <p>{{ $line->posted_on->format('d/m/Y') }} · {{ $line->description }} @if ($line->reference)<span class="mono small">({{ $line->reference }})</span>@endif · {{ $line->account->name }}
            <span class="badge {{ $line->status === 'pending' ? 'badge-warning' : ($line->status === 'reconciled' ? 'badge-success' : '') }}">{{ $line->statusLabel() }}</span></p></div>
    <div class="row"><a class="btn" href="{{ route('bank.accounts.show', $line->account) }}">Voltar</a></div>
</div>

@if ($line->status !== 'pending')
    <section class="card">
        <div class="card__body stack">
            @if ($line->status === 'reconciled')
                <h2 class="mb-0">Conciliado com</h2>
                @foreach ($line->matches as $m)<div>{{ $m->transaction->occurred_at->timezone('America/Sao_Paulo')->format('d/m/Y') }} · {{ $m->transaction->description }} · {{ $m->transaction->methodLabel() }} · <strong>{{ Format::money($m->transaction->signedCents()) }}</strong></div>@endforeach
            @else
                <p><strong>Ignorado:</strong> {{ $line->notes }}</p>
            @endif
            <p class="small muted">{{ $line->resolver?->name }} · {{ $line->resolved_at?->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}</p>
            <form method="post" action="{{ route('bank.lines.undo', $line) }}" data-confirm="Desfazer? A linha volta para a conciliar.">@csrf<button class="btn btn-sm" type="submit">Desfazer</button></form>
        </div>
    </section>
@else
    <form method="post" action="{{ route('bank.lines.match', $line) }}" class="card mb-2">@csrf
        <div class="card__head"><h2>Conciliar com lançamentos do financeiro</h2><span class="help">Marque um ou mais (ex.: repasse do gateway = recebimentos − tarifa). A soma precisa ser {{ Format::money($line->amount_cents) }}.</span></div>
        <div class="table-wrap"><table class="table">
            <thead><tr><th></th><th>Data</th><th>Lançamento</th><th>Forma</th><th class="num">Valor</th><th></th></tr></thead>
            <tbody>
            @php $suggested = $suggestions->pluck('transaction.id')->all(); $rows = $suggestions->pluck('transaction')->concat($search->whereNotIn('id', $suggested)); @endphp
            @forelse ($rows as $t)
                <tr><td><input type="checkbox" name="transactions[]" value="{{ $t->id }}" aria-label="Selecionar" @checked(count($suggested) === 1 && $t->id === $suggested[0])></td>
                    <td class="nowrap">{{ $t->occurred_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}</td>
                    <td>{{ $t->description }}@if ($t->authorization_code)<div class="small muted mono">{{ $t->authorization_code }}</div>@endif</td>
                    <td class="small">{{ $t->methodLabel() }}{{ $t->gateway ? ' · '.$t->gateway : '' }}</td>
                    <td class="num nowrap {{ $t->direction === 'out' ? 'text-danger' : '' }}">{{ Format::money($t->signedCents()) }}</td>
                    <td>@if (in_array($t->id, $suggested, true))<span class="badge badge-info">sugerido</span>@endif</td></tr>
            @empty
                <tr><td colspan="6" class="empty">Nenhum lançamento não conciliado no período. Ajuste a busca ou lance abaixo.</td></tr>
            @endforelse
            </tbody>
        </table></div>
        <div class="card__body"><button class="btn btn-primary" type="submit">Conciliar selecionados</button></div>
    </form>

    <div class="grid grid-3">
        <section class="card">
            <div class="card__head"><h2>Buscar lançamentos</h2></div>
            <form method="get" class="card__body form-grid">
                <x-field name="q_from" label="De" type="date" col="col-6" :value="$q['from']" />
                <x-field name="q_to" label="Até" type="date" col="col-6" :value="$q['to']" />
                <x-field name="q_amount" label="Valor (opcional)" col="col-6" :value="$q['amount']" placeholder="0,00" />
                <div class="field col-6"><label for="qd">Tipo</label><select id="qd" name="q_direction" class="input"><option value="in" @selected($q['direction'] === 'in')>Entradas</option><option value="out" @selected($q['direction'] === 'out')>Saídas</option></select></div>
                <div class="col-12"><button class="btn" type="submit">Buscar</button></div>
            </form>
        </section>
        @if (auth()->user()->hasPermission('financeiro.editar'))
            <section class="card">
                <div class="card__head"><h2>Lançar no financeiro</h2></div>
                <form method="post" action="{{ route('bank.lines.create_entry', $line) }}" class="card__body form-grid">@csrf
                    <p class="help col-12">Para o que só aparece no banco ({{ $line->amount_cents < 0 ? 'tarifa, juros, débito automático' : 'rendimento, transferência recebida' }}): cria a conta a {{ $line->amount_cents < 0 ? 'pagar' : 'receber' }} já baixada na data do extrato, fora do caixa.</p>
                    <div class="field col-12"><label for="ce-c">Categoria</label><select id="ce-c" name="category_id" class="input" required>@foreach ($categories as $c)<option value="{{ $c->id }}" @selected(in_array($c->name, ['Tarifas bancárias e de cartão', 'Outras receitas'], true))>{{ $c->name }}</option>@endforeach</select></div>
                    <x-field name="description" label="Descrição" col="col-12" :value="\Illuminate\Support\Str::limit($line->description, 190, '')" required />
                    <x-field name="counterparty" :label="$line->amount_cents < 0 ? 'Favorecido' : 'Pagador'" col="col-6" :value="$line->account->name" required />
                    <div class="field col-6"><label for="ce-m">Forma</label><select id="ce-m" name="method" class="input">@foreach ($methods as $k => $l)<option value="{{ $k }}" @selected($k === 'bank_transfer')>{{ $l }}</option>@endforeach</select></div>
                    <div class="field col-12"><label for="ce-b">Unidade</label><select id="ce-b" name="branch_id" class="input"><option value="">{{ $line->account->branch_id ? 'Da conta' : 'Matriz' }}</option>@foreach ($branches as $b)<option value="{{ $b->id }}">{{ $b->name }}</option>@endforeach</select></div>
                    <div class="col-12"><button class="btn" type="submit">Lançar e conciliar</button></div>
                </form>
            </section>
        @endif
        <section class="card">
            <div class="card__head"><h2>Ignorar</h2></div>
            <form method="post" action="{{ route('bank.lines.ignore', $line) }}" class="card__body stack">@csrf
                <p class="help">Para o que não entra no financeiro da clínica (ex.: transferência entre contas próprias, aplicação/resgate).</p>
                <label for="ig">Motivo</label><input id="ig" name="reason" class="input" required minlength="3" maxlength="500">
                <button class="btn" type="submit">Ignorar linha</button></form>
        </section>
    </div>
@endif
@endsection
