<div class="form-grid" data-row>
    <input type="hidden" name="insurances[{{ $i }}][id]" value="{{ $n['id'] ?? '' }}">
    <div class="field col-3"><label class="label" for="i-{{ $i }}-insid">Convênio</label>
        <select id="i-{{ $i }}-insid" name="insurances[{{ $i }}][insurer_id]" class="input">
            <option value="">Outro (digite o nome)</option>
            @foreach ($insurerOptions as $opt)<option value="{{ $opt->id }}" @selected(($n['insurer_id'] ?? null) === $opt->id)>{{ $opt->name }}</option>@endforeach
        </select></div>
    <div class="field col-3"><label class="label" for="i-{{ $i }}-ins">Nome (se não cadastrado)</label><input id="i-{{ $i }}-ins" name="insurances[{{ $i }}][insurer_name]" class="input" value="{{ empty($n['insurer_id']) ? ($n['insurer_name'] ?? '') : '' }}"></div>
    <div class="field col-3"><label class="label" for="i-{{ $i }}-plan">Plano</label>
        <select id="i-{{ $i }}-plan" name="insurances[{{ $i }}][plan_id]" class="input">
            <option value="">—</option>
            @foreach ($insurerOptions as $opt)
                @if ($opt->plans->isNotEmpty())<optgroup label="{{ $opt->name }}">@foreach ($opt->plans as $pl)<option value="{{ $pl->id }}" @selected(($n['plan_id'] ?? null) === $pl->id)>{{ $pl->name }}</option>@endforeach</optgroup>@endif
            @endforeach
        </select>
        @error("insurances.$i.plan_id")<div class="field-error">{{ $message }}</div>@enderror</div>
    <div class="field col-3"><label class="label" for="i-{{ $i }}-card">Nº da carteirinha</label><input id="i-{{ $i }}-card" name="insurances[{{ $i }}][card_number]" class="input" value="{{ $n['card_number'] ?? '' }}"></div>
    <div class="field col-3"><label class="label" for="i-{{ $i }}-val">Validade</label><input id="i-{{ $i }}-val" name="insurances[{{ $i }}][valid_until]" type="date" class="input" value="{{ ! empty($n['valid_until']) ? \Illuminate\Support\Carbon::parse($n['valid_until'])->format('Y-m-d') : '' }}"></div>
    <label class="check col-6 small"><input type="checkbox" name="insurances[{{ $i }}][is_primary]" value="1" @checked(! empty($n['is_primary']))><span>Convênio principal</span></label>
    <div class="col-3 row"><button type="button" class="btn btn-ghost btn-sm" data-row-remove aria-label="Remover convênio">✕ Remover</button></div>
</div>
