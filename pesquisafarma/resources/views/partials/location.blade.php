{{-- Seletor Estado → Cidade → Bairro (filtra as opções conforme a escolha). Params: $cityId, $neighborhoodId, $required --}}
@php($__cities = \App\Models\City::query()->orderBy('uf')->orderBy('name')->get())
@php($__hoods = \App\Models\Neighborhood::query()->orderBy('name')->get(['id', 'name', 'city_id']))
@php($__city = $__cities->firstWhere('id', $cityId ?? null))
<div class="row" data-location>
  <div class="f" style="max-width:110px"><label>UF</label>
    <select data-loc-uf><option value="">—</option>@foreach($__cities->pluck('uf')->unique()->filter() as $uf)<option @selected($__city?->uf === $uf)>{{ $uf }}</option>@endforeach</select></div>
  <div class="f"><label>Cidade{{ ($required ?? false) ? ' *' : '' }}</label>
    <select name="city_id" data-loc-city @if($required ?? false) required @endif><option value="">—</option>
      @foreach($__cities as $c)<option value="{{ $c->id }}" data-uf="{{ $c->uf }}" @selected(($cityId ?? null) == $c->id)>{{ $c->name }}</option>@endforeach</select></div>
  <div class="f"><label>Bairro{{ ($required ?? false) ? ' *' : '' }}</label>
    <select name="neighborhood_id" data-loc-hood @if($required ?? false) required @endif><option value="">—</option>
      @foreach($__hoods as $n)<option value="{{ $n->id }}" data-city="{{ $n->city_id }}" @selected(($neighborhoodId ?? null) == $n->id)>{{ $n->name }}</option>@endforeach</select></div>
</div>
