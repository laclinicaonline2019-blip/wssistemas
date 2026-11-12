@extends('layouts.app')
@section('title', 'Clientes')
@section('content')
<div class="top"><h1>Clientes</h1><a class="btn p" href="{{ route('customers.create') }}">+ Cadastrar cliente</a></div>
<form class="toolbar"><input name="q" value="{{ request('q') }}" placeholder="Nome, telefone ou CPF">@include('partials.city-filter')
  <select name="bairro"><option value="">Todos os bairros</option>@include('partials.neighborhood-options', ['selected' => request('bairro')])</select>
  <label class="check"><input type="checkbox" name="incompleto" value="1" @checked(request('incompleto'))> cadastro incompleto</label><button class="btn">Filtrar</button></form>
<div class="card table-wrap"><table>
  <tr><th>Cliente</th><th>Telefone</th><th>Bairro</th><th class="num">Cotações</th><th class="num">Pedidos</th><th class="num">Pontos</th><th>Desde</th></tr>
  @forelse($customers as $c)
  <tr><td><a href="{{ route('customers.show', $c) }}"><b>{{ $c->displayName() }}</b></a> @unless($c->isRegistered())<span class="badge b-pendente">incompleto</span>@endunless</td>
    <td class="nowrap">{{ \App\Support\Text::formatPhone($c->phone) }}</td><td>{{ $c->neighborhood?->name }}<div class="small muted">{{ $c->city }}</div></td>
    <td class="num">{{ $c->quotes_count }}</td><td class="num">{{ $c->orders_count }}</td><td class="num">{{ $c->points_balance }}</td><td class="small">{{ $c->created_at->format('d/m/Y') }}</td></tr>
  @empty <tr><td colspan="7" class="empty">Nenhum cliente.</td></tr>
  @endforelse
</table>{{ $customers->links() }}</div>
@endsection
