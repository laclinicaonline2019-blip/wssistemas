{{-- Seletor de CID (busca JSON). Campo enviado: cid_code_id --}}
<div class="field {{ $col ?? 'col-12' }}" data-cid-picker="{{ route('clinical.cid') }}">
    <label for="cid-picker-q">{{ $label ?? 'CID-10 (opcional)' }}</label>
    <input type="hidden" name="cid_code_id" value="{{ old('cid_code_id') }}" data-cid-value>
    <div class="selected-patient {{ old('cid_code_id') ? '' : 'hidden' }}" data-cid-selected>
        <span data-cid-label>{{ old('cid_code_id') ? 'CID selecionado' : '' }}</span>
        <button type="button" class="btn btn-sm btn-ghost" data-cid-clear>Remover</button>
    </div>
    <div class="lookup {{ old('cid_code_id') ? 'hidden' : '' }}" data-cid-search-wrap>
        <input id="cid-picker-q" class="input" placeholder="Buscar por código ou descrição" autocomplete="off" data-cid-q>
        <div class="lookup__list hidden" data-cid-list></div>
    </div>
    @error('cid_code_id')<div class="field-error">{{ $message }}</div>@enderror
</div>
