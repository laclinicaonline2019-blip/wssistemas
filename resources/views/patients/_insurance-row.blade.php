<div class="form-grid" data-row>
    <div class="field col-3"><label class="label" for="i-{{ $i }}-ins">Convênio</label><input id="i-{{ $i }}-ins" name="insurances[{{ $i }}][insurer_name]" class="input" value="{{ $n['insurer_name'] ?? '' }}"></div>
    <div class="field col-3"><label class="label" for="i-{{ $i }}-plan">Plano</label><input id="i-{{ $i }}-plan" name="insurances[{{ $i }}][plan_name]" class="input" value="{{ $n['plan_name'] ?? '' }}"></div>
    <div class="field col-3"><label class="label" for="i-{{ $i }}-card">Nº da carteirinha</label><input id="i-{{ $i }}-card" name="insurances[{{ $i }}][card_number]" class="input" value="{{ $n['card_number'] ?? '' }}"></div>
    <div class="field col-2"><label class="label" for="i-{{ $i }}-val">Validade</label><input id="i-{{ $i }}-val" name="insurances[{{ $i }}][valid_until]" type="date" class="input" value="{{ isset($n['valid_until']) ? \Illuminate\Support\Carbon::parse($n['valid_until'])->format('Y-m-d') : '' }}"></div>
    <div class="col-1 row"><button type="button" class="btn btn-ghost btn-sm" data-row-remove aria-label="Remover convênio">✕</button></div>
    <label class="check col-12 small"><input type="checkbox" name="insurances[{{ $i }}][is_primary]" value="1" @checked(! empty($n['is_primary']))><span>Convênio principal</span></label>
</div>
