@extends('layouts.app', ['title' => $insurer->name])

@php use App\Core\Support\Format; $can = auth()->user()->hasPermission('convenio.gerenciar'); @endphp

@section('content')
@include('insurance._tabs')
<div class="page-head">
    <div><h1>{{ $insurer->name }} @unless ($insurer->is_active)<span class="badge">inativo</span>@endunless</h1>
        <p>Registro ANS {{ $insurer->ans_registry ?? '—' }} · TISS {{ $insurer->tiss_version }} · pagamento em {{ $insurer->payment_term_days }} dias</p></div>
    <a class="btn" href="{{ route('insurers.index') }}">Voltar</a>
</div>

@if ($insurer->tissIssues())
    <div class="alert alert-warning">Para gerar o XML TISS, complete: <strong>{{ implode(', ', $insurer->tissIssues()) }}</strong>.</div>
@endif

<div class="grid grid-2">
    <section class="card">
        <div class="card__head"><h2>Tabelas de valores</h2></div>
        <div class="card__body stack">
            @forelse ($tables as $t)
                <div class="spread {{ $t->is_active ? '' : 'muted' }}">
                    <div><a href="{{ route('insurers.tables.show', $t) }}"><strong>{{ $t->name }}</strong></a>
                        <div class="small muted">{{ $t->plan?->name ?? 'Todos os planos' }} · vigência {{ $t->valid_from->format('d/m/Y') }} a {{ $t->valid_until?->format('d/m/Y') ?? 'indeterminado' }} · {{ $t->items_count }} procedimentos {{ $t->is_active ? '' : '· inativa' }}</div></div>
                    <a class="btn btn-sm" href="{{ route('insurers.tables.show', $t) }}">Valores</a>
                </div>
            @empty
                <p class="small muted">Nenhuma tabela. Sem tabela vigente não é possível incluir procedimentos nas guias.</p>
            @endforelse
            @if ($can)
                <form method="post" action="{{ route('insurers.tables.store', $insurer) }}" class="form-grid">
                    @csrf
                    <x-field name="name" label="Nome da tabela" col="col-6" placeholder="CBHPM 5ª ed. / Tabela 2026" required />
                    <div class="field col-6"><label for="t-plan">Plano</label>
                        <select id="t-plan" name="plan_id" class="input"><option value="">Todos os planos</option>@foreach ($insurer->plans->where('is_active', true) as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach</select></div>
                    <x-field name="valid_from" label="Início da vigência" type="date" col="col-6" :value="now('America/Sao_Paulo')->toDateString()" required />
                    <x-field name="valid_until" label="Fim da vigência" type="date" col="col-6" help="Vazio = sem data final" />
                    <p class="help col-12">Tabela de um plano tem prioridade sobre a tabela geral do convênio. Vigências não podem se sobrepor.</p>
                    <div class="col-12 form-actions"><button class="btn btn-primary" type="submit">Criar tabela</button></div>
                </form>
            @endif
        </div>
    </section>

    <section class="card">
        <div class="card__head"><h2>Planos</h2></div>
        <div class="card__body stack">
            @forelse ($insurer->plans as $p)
                <div class="spread {{ $p->is_active ? '' : 'muted' }}"><span><strong>{{ $p->name }}</strong> {{ $p->ans_code ? '· ANS '.$p->ans_code : '' }} {{ $p->is_active ? '' : '(inativo)' }}</span>
                    @if ($can)<form method="post" action="{{ route('insurers.plans.toggle', $p) }}">@csrf @method('patch')<button class="btn btn-sm btn-ghost" type="submit">{{ $p->is_active ? 'Desativar' : 'Reativar' }}</button></form>@endif</div>
            @empty
                <p class="small muted">Nenhum plano cadastrado (opcional).</p>
            @endforelse
            @if ($can)
                <form method="post" action="{{ route('insurers.plans.store', $insurer) }}" class="form-grid">
                    @csrf
                    <x-field name="name" label="Plano" col="col-7" required maxlength="120" />
                    <x-field name="ans_code" label="Registro do produto (ANS)" col="col-5" maxlength="20" />
                    <div class="col-12 form-actions"><button class="btn" type="submit">Incluir plano</button></div>
                </form>
            @endif
        </div>
    </section>

    <section class="card">
        <div class="card__head"><h2>Médicos credenciados</h2></div>
        <form method="post" action="{{ route('insurers.doctors', $insurer) }}" class="card__body stack">
            @csrf @method('put')
            <p class="help">Sem nenhum marcado, todos os médicos atendem este convênio. Marcados: só eles podem ser agendados por este convênio.</p>
            @foreach ($doctors as $d)
                <label class="check"><input type="checkbox" name="doctor_ids[]" value="{{ $d->id }}" @checked(in_array($d->id, $credentialed, true)) @disabled(! $can)><span>{{ $d->displayName() }} <span class="muted small">CRM {{ $d->crm }}/{{ $d->crm_state }}</span></span></label>
            @endforeach
            @if ($can)<div class="form-actions"><button class="btn" type="submit">Salvar credenciamento</button></div>@endif
        </form>
    </section>

</div>

    <section class="card mt-2">
        <div class="card__head"><h2>Dados do convênio</h2></div>
        @if ($can)
            <form method="post" action="{{ route('insurers.update', $insurer) }}" class="card__body form-grid">
                @csrf @method('put')
                @include('insurance._insurer-fields', ['insurer' => $insurer])
                <label class="check col-12"><input type="checkbox" name="is_active" value="1" @checked($insurer->is_active)><span>Convênio ativo (inativo não pode ser usado em agendamentos e guias novas)</span></label>
                <div class="col-12 form-actions"><button class="btn btn-primary" type="submit">Salvar</button></div>
            </form>
        @else
            <div class="card__body small">{{ $insurer->notes ?: 'Sem observações.' }}</div>
        @endif
    </section>
@endsection
