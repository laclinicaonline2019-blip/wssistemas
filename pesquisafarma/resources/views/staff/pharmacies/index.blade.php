@extends('layouts.app')
@section('title', 'Farmácias')
@section('content')
<div class="top"><h1>Farmácias parceiras</h1><a class="btn p" href="{{ route('pharmacies.create') }}">+ Cadastrar farmácia</a></div>
<form class="toolbar"><input name="q" value="{{ request('q') }}" placeholder="Nome ou CNPJ">@include('partials.city-filter')<button class="btn">Filtrar</button></form>
<div class="card"><b>Farmácias ativas por cidade:</b> @foreach($byCity as $city => $n)<span class="badge" style="margin:2px">{{ $city }}: {{ $n }}</span>@endforeach</div>
<div class="card table-wrap"><table>
  <tr><th>Farmácia</th><th>Bairro / cidade</th><th class="num">Comissão</th><th>Ciclo</th><th>Entrega</th><th class="num">Cotações</th><th class="num">Pedidos</th><th>Situação</th></tr>
  @forelse($pharmacies as $p)
  <tr><td><a href="{{ route('pharmacies.show', $p) }}"><b>{{ $p->trade_name }}</b></a><div class="small muted">{{ $p->cnpj }}</div></td><td>{{ $p->neighborhood?->name }}<div class="small muted">{{ $p->city }}{{ $p->neighborhood?->uf ? '/'.$p->neighborhood->uf : '' }}</div></td>
    <td class="num">{{ number_format($p->commission_percent, 2, ',', '') }}%</td><td>{{ \App\Models\Pharmacy::CYCLES[$p->commission_cycle] }}</td>
    <td class="small">{{ $p->delivers ? 'até '.number_format($p->delivery_radius_km, 1, ',', '').' km · '.money($p->delivery_base_fee) : 'não entrega' }}</td>
    <td class="num">{{ $p->dispatches_count }}</td><td class="num">{{ $p->orders_count }}</td><td><span class="badge {{ $p->active ? 'b-conferida' : 'b-cancelado' }}">{{ $p->active ? 'Ativa' : 'Inativa' }}</span>@if(! $p->lat)<span class="badge b-pendente">sem coordenadas</span>@endif</td></tr>
  @empty <tr><td colspan="8" class="empty">Nenhuma farmácia.</td></tr>@endforelse
</table>{{ $pharmacies->links() }}</div>
@endsection
