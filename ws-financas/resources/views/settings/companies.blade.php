@extends('layouts.app')
@section('title', 'Empresas e contas bancárias')
@section('content')
<div class="blockhead"><h2>Empresas e contas bancárias</h2><span class="muted small">Plano {{ $account->plan()?->name }}: @if($account->plan()?->isPersonal()) somente o CPF do titular @else até {{ $account->plan()?->max_companies }} empresa(s) (CNPJ) — o CPF do titular não conta @endif</span></div>
@foreach ($list as $c)
  <div class="card">
    <div class="blockhead"><h3>{{ $c->name }} @if($c->alias)<span class="muted small">apelido “{{ $c->alias }}”</span>@endif @unless($c->active)<span class="pill bad">inativa</span>@endunless</h3>
      <span class="small muted">{{ $c->person_type === 'pf' ? 'Pessoa física' : 'Pessoa jurídica' }}</span></div>
    <details class="edit"><summary>Editar empresa</summary>
      <form method="post" action="{{ route('companies.update', $c) }}" class="form" style="margin-top:10px">@csrf @method('PUT')
        <div class="field"><label>Nome</label><input name="name" required value="{{ $c->name }}"></div>
        <div class="field"><label>Apelido no WhatsApp</label><input name="alias" value="{{ $c->alias }}" placeholder="Ex.: A, loja, matriz"></div>
        <div class="field"><label>CPF/CNPJ</label><input name="document" value="{{ $c->document }}"></div>
        <label class="inline-check"><input type="hidden" name="active" value="0"><input type="checkbox" name="active" value="1" @checked($c->active)> Ativa</label>
        <div class="formacts full"><button class="btn primary" type="submit">Salvar</button></div>
      </form></details>
    <h3 class="small muted" style="margin:0">Contas bancárias</h3>
    @forelse ($c->bankAccounts as $b)
      <details class="edit"><summary>{{ $b->name }} {{ $b->bank ? '— '.$b->bank : '' }} · saldo inicial {{ brl($b->opening_balance_cents) }} @unless($b->active)(inativa)@endunless</summary>
        <form method="post" action="{{ route('banks.update', $b) }}" class="form" style="margin-top:10px">@csrf @method('PUT')
          <div class="field"><label>Nome</label><input name="name" required value="{{ $b->name }}"></div>
          <div class="field"><label>Banco</label><input name="bank" value="{{ $b->bank }}"></div>
          <div class="field"><label>Saldo inicial (R$)</label><input name="opening_balance" inputmode="decimal" value="{{ number_format($b->opening_balance_cents / 100, 2, ',', '.') }}"></div>
          <label class="inline-check"><input type="hidden" name="active" value="0"><input type="checkbox" name="active" value="1" @checked($b->active)> Ativa</label>
          <div class="formacts full"><button class="btn primary" type="submit">Salvar</button></div>
        </form></details>
    @empty
      <p class="small muted mt0">Nenhuma conta cadastrada.</p>
    @endforelse
    <details class="edit"><summary>+ Nova conta bancária</summary>
      <form method="post" action="{{ route('banks.store', $c) }}" class="form" style="margin-top:10px">@csrf
        <div class="field"><label>Nome</label><input name="name" required placeholder="Ex.: Conta corrente"></div>
        <div class="field"><label>Banco</label><input name="bank" placeholder="Ex.: Banco do Brasil"></div>
        <div class="field"><label>Saldo inicial (R$)</label><input name="opening_balance" inputmode="decimal" placeholder="0,00"></div>
        <div class="field"><label>Data do saldo</label><input name="opening_date" type="date" value="{{ today()->toDateString() }}"></div>
        <div class="formacts full"><button class="btn primary" type="submit">Adicionar</button></div>
      </form></details>

    <h3 class="small muted" style="margin:14px 0 0">Centros de custo</h3>
    <p class="small muted mt0">Ex.: Loja, Obra 12, Veículos, Casa. No WhatsApp, o assistente identifica pelo nome ("250 combustível veículos") ou pergunta.</p>
    @foreach ($c->costCenters as $cc)
      <details class="edit"><summary>🏷️ {{ $cc->name }} @unless($cc->active)<span class="pill bad">inativo</span>@endunless</summary>
        <form method="post" action="{{ route('cost_centers.update', $cc) }}" class="form" style="margin-top:10px">@csrf @method('PUT')
          <div class="field"><label>Nome</label><input name="name" required maxlength="80" value="{{ $cc->name }}"></div>
          <label class="inline-check"><input type="hidden" name="active" value="0"><input type="checkbox" name="active" value="1" @checked($cc->active)> Ativo</label>
          <div class="formacts full"><button class="btn primary" type="submit">Salvar</button></div>
        </form></details>
    @endforeach
    <form method="post" action="{{ route('cost_centers.store', $c) }}" class="inline-form">@csrf
      <input name="name" required maxlength="80" placeholder="Novo centro de custo" aria-label="Novo centro de custo"> <button class="btn sm" type="submit">Adicionar</button></form>

    <h3 class="small muted" style="margin:14px 0 0">Categorias ({{ $c->person_type === 'pf' ? 'padrão de pessoa física' : 'padrão de empresa' }} + próprias)</h3>
    <p class="small mt0">{{ implode(' · ', $c->categories('despesa')) }}</p>
    @foreach ($c->customCategories as $cat)
      <form method="post" action="{{ route('categories.update', $cat) }}" class="inline">@csrf @method('PUT')
        <input type="hidden" name="active" value="{{ $cat->active ? 0 : 1 }}">
        <span class="pill {{ $cat->active ? 'ok' : 'bad' }}">{{ $cat->name }} ({{ $cat->type }})</span> <button class="icon" type="submit">{{ $cat->active ? 'desativar' : 'reativar' }}</button></form>
    @endforeach
    <form method="post" action="{{ route('categories.store', $c) }}" class="inline-form">@csrf
      <input name="name" required maxlength="60" placeholder="Nova categoria" aria-label="Nova categoria">
      <select name="type" aria-label="Tipo"><option value="despesa">Despesa</option><option value="receita">Receita</option></select>
      <button class="btn sm" type="submit">Adicionar</button></form>

    <h3 class="small muted" style="margin:14px 0 0">Metas de gasto</h3>
    @php($bm = $c->budgets->firstWhere(fn ($b) => $b->period === 'mes' && $b->category === ''))
    @php($bs = $c->budgets->firstWhere(fn ($b) => $b->period === 'semana' && $b->category === ''))
    @foreach ($c->budgets->where('category', '!=', '') as $b)
      <span class="pill">{{ $b->category }}: {{ brl($b->amount_cents) }}/{{ $b->period === 'mes' ? 'mês' : 'semana' }}</span>
    @endforeach
    <form method="post" action="{{ route('budgets.save', $c) }}" class="form" style="margin-top:8px">@csrf
      <div class="field"><label>Meta mensal de despesas (R$)</label><input name="mes" inputmode="decimal" placeholder="Ex.: 5.000,00" value="{{ $bm ? number_format($bm->amount_cents / 100, 2, ',', '.') : '' }}"></div>
      <div class="field"><label>Meta semanal de despesas (R$)</label><input name="semana" inputmode="decimal" placeholder="Ex.: 1.200,00" value="{{ $bs ? number_format($bs->amount_cents / 100, 2, ',', '.') : '' }}"></div>
      <div class="field"><label>Meta por categoria <span class="muted">(opcional)</span></label>
        <select name="cat"><option value="">—</option>@foreach ($c->categories('despesa') as $cn)<option>{{ $cn }}</option>@endforeach</select></div>
      <div class="field"><label>Período da categoria</label><select name="cat_period"><option value="mes">Mensal</option><option value="semana">Semanal</option></select></div>
      <div class="field"><label>Valor da categoria (R$) <span class="muted">0 = remover</span></label><input name="cat_valor" inputmode="decimal"></div>
      <div class="formacts full"><button class="btn primary" type="submit">Salvar metas</button></div>
    </form>
  </div>
@endforeach
<form class="card" method="post" action="{{ route('companies.store') }}">@csrf
  <h2>Nova empresa</h2>
  <div class="form">
    <div class="field"><label for="n">Nome</label><input id="n" name="name" required></div>
    <div class="field"><label for="al">Apelido no WhatsApp</label><input id="al" name="alias" placeholder="Ex.: B"></div>
    <div class="field"><label for="pt">Tipo</label><select id="pt" name="person_type"><option value="pj">Pessoa jurídica</option><option value="pf">Pessoa física</option></select></div>
    <div class="field"><label for="dc">CPF/CNPJ</label><input id="dc" name="document"></div>
  </div>
  <div class="formacts"><button class="btn primary" type="submit">Cadastrar empresa</button></div>
</form>
@endsection
