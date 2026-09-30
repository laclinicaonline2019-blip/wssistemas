<div class="form-grid" data-row>
    <div class="field col-6">
        <label class="sr-only" for="r-{{ $index }}-role">Perfil</label>
        <select id="r-{{ $index }}-role" name="roles[{{ $index }}][role_id]" class="input">
            <option value="">Selecione o perfil…</option>
            @foreach ($roles as $r)
                <option value="{{ $r->id }}" @selected(($assignment['role_id'] ?? null) === $r->id)>{{ $r->name }}</option>
            @endforeach
        </select>
    </div>
    <div class="field col-4">
        <label class="sr-only" for="r-{{ $index }}-branch">Filial</label>
        <select id="r-{{ $index }}-branch" name="roles[{{ $index }}][branch_id]" class="input">
            @if ($companyWide)<option value="">Todas as filiais</option>@endif
            @foreach ($branches as $b)
                <option value="{{ $b->id }}" @selected(($assignment['branch_id'] ?? null) === $b->id)>{{ $b->name }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-2"><button type="button" class="btn btn-ghost btn-sm" data-row-remove aria-label="Remover perfil">Remover</button></div>
</div>
