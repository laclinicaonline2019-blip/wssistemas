<div class="form-grid" data-row>
    <div class="field col-3"><label class="label" for="c-{{ $i }}-type">Tipo</label>
        <select id="c-{{ $i }}-type" name="contacts[{{ $i }}][type]" class="input">
            @foreach (\App\Modules\Patients\Models\PatientContact::TYPES as $k => $l)
                <option value="{{ $k }}" @selected(($c['type'] ?? 'guardian') === $k)>{{ $l }}</option>
            @endforeach
        </select></div>
    <div class="field col-4"><label class="label" for="c-{{ $i }}-name">Nome</label><input id="c-{{ $i }}-name" name="contacts[{{ $i }}][name]" class="input" value="{{ $c['name'] ?? '' }}"></div>
    <div class="field col-2"><label class="label" for="c-{{ $i }}-rel">Parentesco</label><input id="c-{{ $i }}-rel" name="contacts[{{ $i }}][relationship]" class="input" value="{{ $c['relationship'] ?? '' }}" placeholder="mãe, pai…"></div>
    <div class="field col-2"><label class="label" for="c-{{ $i }}-phone">Telefone</label><input id="c-{{ $i }}-phone" name="contacts[{{ $i }}][phone]" class="input" data-mask="phone" value="{{ \App\Core\Support\Format::phone($c['phone'] ?? null) }}"></div>
    <div class="col-1 row"><button type="button" class="btn btn-ghost btn-sm" data-row-remove aria-label="Remover contato">✕</button></div>
    <div class="field col-3"><label class="label" for="c-{{ $i }}-cpf">CPF</label><input id="c-{{ $i }}-cpf" name="contacts[{{ $i }}][cpf]" class="input" data-mask="cpf" value="{{ \App\Core\Support\Format::cpf($c['cpf'] ?? null) }}"></div>
    <div class="field col-4"><label class="label" for="c-{{ $i }}-email">E-mail</label><input id="c-{{ $i }}-email" name="contacts[{{ $i }}][email]" type="email" class="input" value="{{ $c['email'] ?? '' }}"></div>
</div>
