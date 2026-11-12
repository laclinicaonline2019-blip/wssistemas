@extends('layouts.app')
@section('title', 'Usuários e permissões')
@section('content')
<div class="blockhead"><h2>Usuários e permissões</h2><span class="muted small">Plano {{ $account->plan()?->name }}: até {{ $account->plan()?->max_users }} usuário(s)</span></div>
<div class="card small">
  <b>Perfis:</b> <b>Administrador</b> — tudo, inclusive usuários e assinatura · <b>Gestor</b> — lançamentos, empresas, WhatsApp · <b>Financeiro</b> — lançamentos e relatórios ·
  <b>Consulta</b> — só leitura · <b>Contador</b> — leitura e exportação, somente nas empresas autorizadas.
</div>
<div class="table-wrap"><table class="t"><thead><tr><th>Usuário</th><th>Perfil e empresas</th></tr></thead><tbody>
@foreach ($list as $m)
  <tr><td><b>{{ $m->user->name }}</b><br><span class="small muted">{{ $m->user->email }}</span> @if($m->status!=='active')<span class="pill bad">inativo</span>@endif
      @if($m->user->hasTwoFactor())<span class="pill ok">2FA</span>@endif</td>
    <td>
      <details class="edit"><summary>{{ \App\Models\Membership::ROLES[$m->role] }} · {{ $m->all_companies && $m->role !== 'contador' ? 'todas as empresas' : ($m->companies->pluck('name')->implode(', ') ?: 'nenhuma empresa') }}</summary>
        <form method="post" action="{{ route('users.update', $m) }}" class="form" style="margin-top:8px">@csrf @method('PUT')
          <div class="field"><label>Perfil</label><select name="role">@foreach (\App\Models\Membership::ROLES as $k => $l)<option value="{{ $k }}" @selected($m->role===$k)>{{ $l }}</option>@endforeach</select></div>
          <div class="field"><label>Situação</label><select name="status"><option value="active">Ativo</option><option value="inactive" @selected($m->status!=='active')>Inativo</option></select></div>
          <label class="inline-check full"><input type="hidden" name="all_companies" value="0"><input type="checkbox" name="all_companies" value="1" @checked($m->all_companies)> Todas as empresas (não vale para contador)</label>
          <div class="checks full">@foreach ($allCompanies as $c)<label><input type="checkbox" name="companies[]" value="{{ $c->id }}" @checked($m->companies->contains('id', $c->id))> {{ $c->name }}</label>@endforeach</div>
          <div class="formacts full"><button class="btn primary" type="submit">Salvar</button></div>
        </form></details>
    </td></tr>
@endforeach
</tbody></table></div>
<form class="card" method="post" action="{{ route('users.store') }}">@csrf
  <h2>Adicionar usuário</h2>
  <p class="small muted mt0">Se a pessoa já usa o WS Finanças (por exemplo, um contador que atende outros clientes), ela é apenas vinculada à sua conta, com o mesmo login.</p>
  <div class="form">
    <div class="field"><label for="un">Nome</label><input id="un" name="name" required></div>
    <div class="field"><label for="ue">E-mail</label><input id="ue" name="email" type="email" required></div>
    <div class="field"><label for="ur">Perfil</label><select id="ur" name="role">@foreach (\App\Models\Membership::ROLES as $k => $l)<option value="{{ $k }}" @selected($k==='financeiro')>{{ $l }}</option>@endforeach</select></div>
    <div class="field"><label for="up">Senha inicial <span class="muted">(vazio = a pessoa cria pelo e-mail)</span></label><input id="up" name="password" type="password" autocomplete="new-password"></div>
    <label class="inline-check full"><input type="hidden" name="all_companies" value="0"><input type="checkbox" name="all_companies" value="1" checked> Acesso a todas as empresas</label>
    <div class="checks full"><span class="small muted">Ou só estas:</span>@foreach ($allCompanies as $c)<label><input type="checkbox" name="companies[]" value="{{ $c->id }}"> {{ $c->name }}</label>@endforeach</div>
  </div>
  <div class="formacts"><button class="btn primary" type="submit">Adicionar</button></div>
</form>
@endsection
