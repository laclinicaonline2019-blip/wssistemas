@extends('layouts.app')
@section('title', 'Cidades e bairros')
@section('content')
<div class="top"><div><h1>Cidades e bairros</h1><div class="sub">O sistema atende vários municípios e estados. Cada bairro pertence a uma cidade; o centro do bairro (latitude/longitude) é usado para achar as farmácias mais próximas do cliente.</div></div></div>

<div class="card">
  <div class="top" style="margin-bottom:8px"><h2 style="margin:0">🏙️ Cidades atendidas ({{ $allCities->count() }})</h2>
    <form class="toolbar" style="margin:0"><select name="uf" data-autosubmit><option value="">Todos os estados</option>@foreach($allCities->pluck('uf')->unique()->filter() as $uf)<option @selected(request('uf') === $uf)>{{ $uf }}</option>@endforeach</select></form></div>
  <div class="table-wrap"><table>
    <tr><th>Cidade</th><th>UF</th><th>Latitude</th><th>Longitude</th><th class="num">Bairros</th><th class="num">Farmácias</th><th class="num">Clientes</th><th>Ativa</th><th></th></tr>
    @foreach($cities as $c)
    @php($f = 'c'.$c->id)
    <tr><td><input form="{{ $f }}" name="name" value="{{ $c->name }}"></td>
      <td style="width:80px"><select form="{{ $f }}" name="uf">@foreach(\App\Models\City::UFS as $uf)<option @selected($c->uf === $uf)>{{ $uf }}</option>@endforeach</select></td>
      <td><input form="{{ $f }}" name="lat" value="{{ $c->lat }}"></td><td><input form="{{ $f }}" name="lng" value="{{ $c->lng }}"></td>
      <td class="num"><a href="{{ route('neighborhoods.index', ['cidade' => $c->id]) }}">{{ $c->neighborhoods_count }}</a></td>
      <td class="num"><a href="{{ route('pharmacies.index', ['cidade' => $c->id]) }}">{{ $c->pharmacies_count }}</a></td><td class="num">{{ $c->customers_count }}</td>
      <td><input form="{{ $f }}" type="checkbox" name="active" value="1" @checked($c->active)></td>
      <td><form id="{{ $f }}" method="post" action="{{ route('cities.update', $c) }}">@csrf @method('PUT')<button class="btn sm">Salvar</button></form></td></tr>
    @endforeach
  </table></div>
  <form method="post" action="{{ route('cities.store') }}" class="row" style="margin-top:10px">@csrf
    <input name="name" placeholder="Nova cidade" required><select name="uf" class="auto" required><option value="">UF</option>@foreach(\App\Models\City::UFS as $uf)<option>{{ $uf }}</option>@endforeach</select>
    <input name="lat" placeholder="Latitude (centro)"><input name="lng" placeholder="Longitude (centro)"><button class="btn p auto">+ Cidade</button></form>
</div>

<div class="grid g2">
  <div class="card"><h3>+ Novo bairro</h3>
    <form method="post" action="{{ route('neighborhoods.store') }}">@csrf
      <div class="row"><select name="city_id" required><option value="">Cidade…</option>@foreach($allCities as $c)<option value="{{ $c->id }}" @selected(request('cidade') == $c->id)>{{ $c->label() }}</option>@endforeach</select><input name="name" placeholder="Bairro" required></div>
      <div class="row" style="margin-top:6px"><input name="lat" placeholder="Latitude"><input name="lng" placeholder="Longitude"><button class="btn p auto">Salvar</button></div></form></div>
  <div class="card"><h3>Importar bairros (CSV)</h3><p class="small muted">Colunas: <code>bairro;cidade;uf;latitude;longitude</code> — cidades novas são criadas automaticamente. Ex.: <code>Cambuí;Campinas;SP;-22.8935;-47.0505</code></p>
    <form method="post" action="{{ route('neighborhoods.import') }}" enctype="multipart/form-data" class="row">@csrf<input type="file" name="file" accept=".csv,.txt" required><button class="btn auto">Importar</button></form></div>
</div>

<form class="toolbar">
  <select name="cidade"><option value="">Todas as cidades</option>@foreach($allCities as $c)<option value="{{ $c->id }}" @selected(request('cidade') == $c->id)>{{ $c->label() }}</option>@endforeach</select>
  <input name="q" value="{{ request('q') }}" placeholder="Buscar bairro">
  <label class="check"><input type="checkbox" name="sem_coordenadas" value="1" @checked(request('sem_coordenadas'))> sem coordenadas</label>
  <label class="check"><input type="checkbox" name="sem_cidade" value="1" @checked(request('sem_cidade'))> sem cidade</label><button class="btn">Filtrar</button>
</form>
<div class="card table-wrap"><table>
  <tr><th>Bairro</th><th>Cidade</th><th>Latitude</th><th>Longitude</th><th></th></tr>
  @forelse($neighborhoods as $n)
  @php($f = 'nb'.$n->id)
  <tr><td><input form="{{ $f }}" name="name" value="{{ $n->name }}"></td>
    <td><select form="{{ $f }}" name="city_id" required><option value="">— definir —</option>@foreach($allCities as $c)<option value="{{ $c->id }}" @selected($n->city_id === $c->id)>{{ $c->label() }}</option>@endforeach</select></td>
    <td><input form="{{ $f }}" name="lat" value="{{ $n->lat }}"></td><td><input form="{{ $f }}" name="lng" value="{{ $n->lng }}"></td>
    <td><form id="{{ $f }}" method="post" action="{{ route('neighborhoods.update', $n) }}">@csrf @method('PUT')<button class="btn sm">Salvar</button></form></td></tr>
  @empty <tr><td colspan="5" class="empty">Nenhum bairro.</td></tr>@endforelse
</table>{{ $neighborhoods->links() }}</div>
@endsection
