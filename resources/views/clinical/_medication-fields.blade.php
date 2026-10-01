@php use App\Modules\Clinical\Models\Medication; $p = $prefix ?? 'n'; @endphp
<div class="field col-6"><label for="{{ $p }}-ai">Princípio ativo *</label><input id="{{ $p }}-ai" name="active_ingredient" class="input" maxlength="200" required value="{{ $m?->active_ingredient }}"></div>
<div class="field col-6"><label for="{{ $p }}-cn">Nome comercial</label><input id="{{ $p }}-cn" name="commercial_name" class="input" maxlength="150" value="{{ $m?->commercial_name }}"></div>
<div class="field col-4"><label for="{{ $p }}-co">Concentração</label><input id="{{ $p }}-co" name="concentration" class="input" maxlength="80" placeholder="500 mg" value="{{ $m?->concentration }}"></div>
<div class="field col-4"><label for="{{ $p }}-pr">Apresentação</label><input id="{{ $p }}-pr" name="presentation" class="input" maxlength="120" placeholder="comprimido" value="{{ $m?->presentation }}"></div>
<div class="field col-4"><label for="{{ $p }}-ro">Via</label><input id="{{ $p }}-ro" name="route" class="input" maxlength="40" placeholder="oral" value="{{ $m?->route }}"></div>
<div class="field col-8"><label for="{{ $p }}-po">Posologia padrão</label><input id="{{ $p }}-po" name="default_posology" class="input" maxlength="255" value="{{ $m?->default_posology }}"></div>
<div class="field col-4"><label for="{{ $p }}-ct">Controle (Portaria 344/98)</label>
    <select id="{{ $p }}-ct" name="control_type" class="input">@foreach (Medication::CONTROL_TYPES as $k => $l)<option value="{{ $k }}" @selected(($m?->control_type ?? 'none') === $k)>{{ $l }}</option>@endforeach</select></div>
<div class="field col-6"><label for="{{ $p }}-fa">Fabricante</label><input id="{{ $p }}-fa" name="manufacturer" class="input" maxlength="120" value="{{ $m?->manufacturer }}"></div>
<div class="field col-6"><label for="{{ $p }}-no">Observações</label><input id="{{ $p }}-no" name="notes" class="input" maxlength="500" value="{{ $m?->notes }}"></div>
