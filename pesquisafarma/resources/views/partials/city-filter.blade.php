{{-- Filtro por UF e cidade (query: uf, cidade) --}}
@php($__cities = \App\Models\City::query()->orderBy('uf')->orderBy('name')->get())
<select name="uf" class="auto"><option value="">Todos os estados</option>@foreach($__cities->pluck('uf')->unique()->filter() as $uf)<option @selected(request('uf') === $uf)>{{ $uf }}</option>@endforeach</select>
<select name="cidade" class="auto"><option value="">Todas as cidades</option>@foreach($__cities as $c)<option value="{{ $c->id }}" @selected(request('cidade') == $c->id)>{{ $c->label() }}</option>@endforeach</select>
