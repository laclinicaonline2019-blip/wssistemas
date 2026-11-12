{{-- <option>s de bairro agrupadas por cidade/UF. Param: $selected --}}
@foreach(\App\Models\Neighborhood::query()->orderBy('uf')->orderBy('city')->orderBy('name')->get()->groupBy(fn ($n) => trim($n->city.($n->uf ? '/'.$n->uf : '')) ?: 'Sem cidade') as $__group => $__list)
<optgroup label="{{ $__group }}">@foreach($__list as $n)<option value="{{ $n->id }}" @selected(($selected ?? null) == $n->id)>{{ $n->name }}</option>@endforeach</optgroup>
@endforeach
