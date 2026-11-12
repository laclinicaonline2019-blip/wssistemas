@extends('layouts.app')
@section('title', 'Usuários')
@section('content')
<div class="top"><div><h1>Usuários</h1><div class="sub">Administradores, televendas e acessos das farmácias.</div></div></div>
<div class="card"><h3>+ Novo usuário</h3>
  <form method="post" action="{{ route('users.store') }}">@csrf<div class="row">
    <input name="name" placeholder="Nome" required><input name="email" type="email" placeholder="E-mail" required>
    <select name="role">@foreach(\App\Models\User::ROLES as $k => $v)<option value="{{ $k }}" @selected($k==='televendas')>{{ $v }}</option>@endforeach</select>
    <select name="pharmacy_id"><option value="">(farmácia, se perfil Farmácia)</option>@foreach($pharmacies as $p)<option value="{{ $p->id }}">{{ $p->trade_name }}</option>@endforeach</select>
    <input name="password" type="password" placeholder="Senha (mín. 8)" required><button class="btn p auto">Criar</button></div></form>
</div>
<div class="card table-wrap"><table>
  <tr><th>Nome</th><th>E-mail</th><th>Perfil</th><th>Farmácia</th><th>Nova senha</th><th>Ativo</th><th>Último acesso</th><th></th></tr>
  @foreach($users as $u)
  @php($f = 'u'.$u->id)
  <tr><td><input form="{{ $f }}" name="name" value="{{ $u->name }}"></td><td><input form="{{ $f }}" name="email" value="{{ $u->email }}"></td>
    <td><select form="{{ $f }}" name="role">@foreach(\App\Models\User::ROLES as $k => $v)<option value="{{ $k }}" @selected($u->role===$k)>{{ $v }}</option>@endforeach</select></td>
    <td><select form="{{ $f }}" name="pharmacy_id"><option value="">—</option>@foreach($pharmacies as $p)<option value="{{ $p->id }}" @selected($u->pharmacy_id===$p->id)>{{ $p->trade_name }}</option>@endforeach</select></td>
    <td><input form="{{ $f }}" name="password" type="password" placeholder="(manter)"></td><td><input form="{{ $f }}" type="checkbox" name="active" value="1" @checked($u->active)></td>
    <td class="small">{{ $u->last_login_at?->format('d/m H:i') ?? '—' }}</td><td><form id="{{ $f }}" method="post" action="{{ route('users.update', $u) }}">@csrf @method('PUT')<button class="btn sm">Salvar</button></form></td></tr>
  @endforeach
</table></div>
@endsection
