@extends('layouts.app', ['title' => 'Conciliação — '.$account->name])

@php
    use App\Core\Support\Format;
    use App\Modules\Banking\Models\BankStatement;
    use App\Modules\Banking\Models\BankStatementLine;
@endphp

@section('content')
<div class="page-head">
    <div><h1>{{ $account->name }}</h1><p>{{ $account->label() }} @if ($account->last_synced_at)· Open Finance lido em {{ $account->last_synced_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}@endif</p></div>
    <div class="row"><a class="btn" href="{{ route('bank.index') }}">Contas</a></div>
</div>
@if ($account->sync_error)<div class="alert alert-error">Última sincronização falhou: {{ $account->sync_error }}</div>@endif

<div class="grid grid-3 mb-2">
    <section class="card">
        <div class="card__head"><h2>Importar extrato</h2></div>
        <form method="post" action="{{ route('bank.accounts.import', $account) }}" enctype="multipart/form-data" class="card__body stack">@csrf
            <label for="sf">Arquivo OFX ou CSV</label><input id="sf" name="file" type="file" class="input" accept=".ofx,.csv,.txt" required>
            <p class="help">Pode reimportar ou enviar períodos sobrepostos: lançamentos já importados não se repetem.</p>
            <button class="btn btn-primary" type="submit">Importar</button></form>
        @if ($account->sync_provider === 'pluggy')
            <form method="post" action="{{ route('bank.accounts.sync', $account) }}" class="card__body">@csrf<button class="btn" type="submit">Ler agora pelo Open Finance</button></form>
        @endif
    </section>
    <section class="card">
        <div class="card__head"><h2>Situação</h2></div>
        <div class="card__body stack">
            @foreach (BankStatementLine::STATUSES as $k => $l)
                <a class="spread {{ $status === $k ? 'strong' : '' }}" href="{{ route('bank.accounts.show', [$account, 'status' => $k]) }}"><span>{{ $l }}</span><span class="badge {{ $k === 'pending' && ($counts[$k] ?? 0) ? 'badge-warning' : '' }}">{{ (int) ($counts[$k] ?? 0) }}</span></a>
            @endforeach
            <form method="post" action="{{ route('bank.accounts.auto', $account) }}">@csrf<button class="btn btn-sm" type="submit">Conciliar automaticamente os casos exatos</button></form>
            <p class="help">Automático só quando valor e data batem com UM único lançamento. O resto você confere.</p>
        </div>
    </section>
    <section class="card">
        <div class="card__head"><h2>Últimas importações</h2></div>
        <div class="card__body stack small">
            @forelse ($statements as $s)
                <div>{{ $s->created_at->timezone('America/Sao_Paulo')->format('d/m H:i') }} · {{ BankStatement::SOURCES[$s->source] }} · {{ $s->lines_new }}/{{ $s->lines_total }} novos
                    @if ($s->balance_cents !== null)· saldo {{ Format::money($s->balance_cents) }} em {{ $s->balance_date?->format('d/m') }}@endif
                    <span class="muted">{{ $s->importer?->name ?? 'automático' }}</span></div>
            @empty
                <p class="muted">Nenhum extrato ainda.</p>
            @endforelse
        </div>
    </section>
</div>

<section class="card">
    <div class="card__head"><h2>{{ BankStatementLine::STATUSES[$status] }}</h2>
        <form method="get" class="row"><input type="hidden" name="status" value="{{ $status }}">
            <label class="sr-only" for="ff">De</label><input id="ff" type="date" name="from" class="input w-auto" value="{{ $filters['from'] ?? '' }}">
            <label class="sr-only" for="ft">Até</label><input id="ft" type="date" name="to" class="input w-auto" value="{{ $filters['to'] ?? '' }}">
            <button class="btn btn-sm" type="submit">Filtrar</button></form></div>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Data</th><th>Histórico do banco</th><th class="num">Valor</th><th>{{ $status === 'pending' ? 'Sugestão' : 'Resolução' }}</th><th></th></tr></thead>
        <tbody>
        @forelse ($lines as $l)
            <tr>
                <td class="nowrap">{{ $l->posted_on->format('d/m/Y') }}</td>
                <td>{{ $l->description }}@if ($l->reference)<div class="small muted mono">{{ $l->reference }}</div>@endif</td>
                <td class="num nowrap {{ $l->amount_cents < 0 ? 'text-danger' : 'text-success' }}">{{ Format::money($l->amount_cents) }}</td>
                <td class="small">
                    @if ($status === 'pending')
                        @if ($best = ($suggestions[$l->id] ?? collect())->first())
                            {{ $best['transaction']->description }} · {{ $best['transaction']->methodLabel() }} · {{ $best['transaction']->occurred_at->timezone('America/Sao_Paulo')->format('d/m') }}
                            @if (($suggestions[$l->id])->count() > 1)<span class="badge">+{{ ($suggestions[$l->id])->count() - 1 }}</span>@endif
                            <form method="post" action="{{ route('bank.lines.match', $l) }}" class="inline">@csrf<input type="hidden" name="transactions[]" value="{{ $best['transaction']->id }}"><button class="btn btn-sm" type="submit">Conciliar</button></form>
                        @else
                            <span class="muted">sem lançamento correspondente</span>
                        @endif
                    @elseif ($l->status === 'reconciled')
                        @foreach ($l->matches as $m){{ $m->transaction->description }} ({{ Format::money($m->transaction->signedCents()) }})@if ($m->origin === 'auto') <span class="badge">auto</span>@elseif ($m->origin === 'created') <span class="badge badge-info">lançado</span>@endif<br>@endforeach
                    @else
                        {{ $l->notes }}
                    @endif
                    @if ($l->resolver)<div class="muted">{{ $l->resolver->name }} · {{ $l->resolved_at?->timezone('America/Sao_Paulo')->format('d/m H:i') }}</div>@endif
                </td>
                <td class="actions"><a class="btn btn-sm" href="{{ route('bank.lines.show', $l) }}">{{ $status === 'pending' ? 'Resolver' : 'Ver' }}</a></td>
            </tr>
        @empty
            <tr><td colspan="5" class="empty">Nada por aqui.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    @include('partials.pagination', ['paginator' => $lines])
</section>

<section class="card mt-2">
    <div class="card__head"><h2>Dados da conta</h2></div>
    <form method="post" action="{{ route('bank.accounts.update', $account) }}" class="card__body form-grid">@csrf @method('put')
        @include('banking._account-fields', ['a' => $account])
        <label class="check col-12"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked($account->is_active)><span>Conta ativa</span></label>
        <div class="col-12 form-actions"><button class="btn" type="submit">Salvar</button></div>
    </form>
</section>
@endsection
