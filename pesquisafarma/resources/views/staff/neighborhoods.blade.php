@extends('layouts.app')
@section('title', 'Bairros')
@section('content')
<div class="top"><div><h1>Bairros</h1><div class="sub">O centro de cada bairro (latitude/longitude) é usado para achar as farmácias mais próximas do cliente.</div></div></div>
<div class="grid g2">
  <div class="card"><h3>+ Novo bairro</h3>
    <form method="post" action="{{ route('neighborhoods.store') }}">@csrf<div class="row"><input name="name" placeholder="Bairro" required><input name="city" placeholder="Cidade"><input name="uf" placeholder="UF" maxlength="2" style="max-width:70px"></div>
      <div class="row" style="margin-top:6px"><input name="lat" placeholder="Latitude"><input name="lng" placeholder="Longitude"><button class="btn p auto">Salvar</button></div></form></div>
  <div class="card"><h3>Importar CSV</h3><p class="small muted">Colunas: bairro;cidade;uf;latitude;longitude</p>
    <form method="post" action="{{ route('neighborhoods.import') }}" enctype="multipart/form-data" class="row">@csrf<input type="file" name="file" accept=".csv,.txt" required><button class="btn auto">Importar</button></form></div>
</div>
<form class="toolbar"><input name="q" value="{{ request('q') }}" placeholder="Buscar"><label class="check"><input type="checkbox" name="sem_coordenadas" value="1" @checked(request('sem_coordenadas'))> sem coordenadas</label><button class="btn">Filtrar</button></form>
<div class="card table-wrap"><table>
  <tr><th>Bairro</th><th>Cidade</th><th>UF</th><th>Latitude</th><th>Longitude</th><th></th></tr>
  @foreach($neighborhoods as $n)
  @php($f = 'nb'.$n->id)
  <tr><td><input form="{{ $f }}" name="name" value="{{ $n->name }}"></td><td><input form="{{ $f }}" name="city" value="{{ $n->city }}"></td><td style="width:70px"><input form="{{ $f }}" name="uf" value="{{ $n->uf }}" maxlength="2"></td>
    <td><input form="{{ $f }}" name="lat" value="{{ $n->lat }}"></td><td><input form="{{ $f }}" name="lng" value="{{ $n->lng }}"></td>
    <td><form id="{{ $f }}" method="post" action="{{ route('neighborhoods.update', $n) }}">@csrf @method('PUT')<button class="btn sm">Salvar</button></form></td></tr>
  @endforeach
</table>{{ $neighborhoods->links() }}</div>
@endsection
