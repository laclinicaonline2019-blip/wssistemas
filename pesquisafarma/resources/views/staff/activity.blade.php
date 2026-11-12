@extends('layouts.app')
@section('title', 'Atividades')
@section('content')
<div class="top"><div><h1>Registro de atividades</h1><div class="sub">Tudo que cada televendas, farmácia e administrador faz no sistema.</div></div></div>
<form class="toolbar">
  @if(auth()->user()->isAdmin())<div><label>Usuário</label><select name="usuario"><option value="">Todos</option>@foreach($users as $u)<option value="{{ $u->id }}" @selected(request('usuario')==$u->id)>{{ $u->name }}</option>@endforeach</select></div>@endif
  <div><label>Ação</label><select name="acao"><option value="">Todas</option>@foreach(['cotacao' => 'Cotações', 'pedido' => 'Pedidos', 'nota' => 'Notas', 'cliente' => 'Clientes', 'conversa' => 'Conversas', 'promocao' => 'Promoções', 'fidelidade' => 'Fidelidade', 'comissao' => 'Comissões', 'login' => 'Acessos'] as $k => $v)<option value="{{ $k }}" @selected(request('acao')===$k)>{{ $v }}</option>@endforeach</select></div>
  <div><label>De</label><input type="date" name="de" value="{{ request('de') }}"></div><div><label>Até</label><input type="date" name="ate" value="{{ request('ate') }}"></div><button class="btn">Filtrar</button>
</form>
<div class="card table-wrap"><table>
  <tr><th>Quando</th><th>Usuário</th><th>Ação</th><th>Descrição</th><th>IP</th></tr>
  @forelse($logs as $l)<tr><td class="small nowrap">{{ $l->created_at->format('d/m/Y H:i:s') }}</td><td>{{ $l->user?->name ?? 'Sistema / WhatsApp' }}</td><td class="small">{{ $l->action }}</td>
    <td>@if($l->subject_type === 'Quote')<a href="{{ route('quotes.show', $l->subject_id) }}">{{ $l->description }}</a>@elseif($l->subject_type === 'Order')<a href="{{ route('orders.show', $l->subject_id) }}">{{ $l->description }}</a>@else{{ $l->description }}@endif</td><td class="small muted">{{ $l->ip }}</td></tr>
  @empty <tr><td colspan="5" class="empty">Nada registrado.</td></tr>@endforelse
</table>{{ $logs->links() }}</div>
@endsection
