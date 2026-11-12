@extends('layouts.guest')
@section('title', 'Instalação — WS Finanças em Dia')
@section('content')
<form class="card" method="post" action="/instalar">@csrf
  <h2>Instalação</h2>
  <div class="table-wrap"><table class="t"><tbody>@foreach ($checks as $c)<tr><td><span class="pill {{ $c['ok'] ? 'ok' : 'bad' }}">{{ $c['ok'] ? 'OK' : 'Falta' }}</span> {{ $c['item'] }}</td><td class="small muted">{{ $c['info'] }}</td></tr>@endforeach</tbody></table></div>
  <div class="form">
    <div class="field full"><label>Endereço do sistema (com https)</label><input name="app_url" required value="{{ old('app_url', request()->getSchemeAndHttpHost()) }}"></div>
    <div class="field"><label>Ambiente</label><select name="stage"><option value="production">Produção</option><option value="homologation">Homologação (testes)</option></select></div>
    <div class="field"><label>Servidor MySQL</label><input name="db_host" required value="{{ old('db_host', 'localhost') }}"></div>
    <div class="field"><label>Porta</label><input name="db_port" required value="{{ old('db_port', 3306) }}"></div>
    <div class="field"><label>Nome do banco (cPanel: conta_nome)</label><input name="db_database" required value="{{ old('db_database') }}"></div>
    <div class="field"><label>Usuário do banco</label><input name="db_username" required value="{{ old('db_username') }}"></div>
    <div class="field"><label>Senha do banco</label><input name="db_password" type="password"></div>
    <div class="field full"><h3 style="margin-top:8px">Proprietário da plataforma</h3></div>
    <div class="field"><label>Nome</label><input name="admin_name" required value="{{ old('admin_name') }}"></div>
    <div class="field"><label>E-mail</label><input name="admin_email" type="email" required value="{{ old('admin_email') }}"></div>
    <div class="field"><label>Senha</label><input name="admin_password" type="password" required autocomplete="new-password"></div>
    <div class="field"><label>Repita a senha</label><input name="admin_password_confirmation" type="password" required autocomplete="new-password"></div>
  </div>
  <div class="formacts"><button class="btn primary" type="submit">Instalar</button></div>
</form>
@endsection
