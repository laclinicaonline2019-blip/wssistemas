{{-- Linha de item da venda. $i (índice ou "__i__"), $professionals, $row (old input) --}}
@php $row ??= []; @endphp
<div class="ms-item" data-item>
    <div class="field"><label for="it-{{ $i }}-p">Profissional</label>
        <select id="it-{{ $i }}-p" name="items[{{ $i }}][professional_id]" class="input" data-pro>
            <option value="">— Só da clínica —</option>
            @foreach ($professionals as $p)
                <option value="{{ $p->id }}" data-type="{{ $p->split_type }}" data-value="{{ $p->split_value }}" @selected(($row['professional_id'] ?? null) === $p->id)>{{ $p->name }} ({{ $p->splitLabel() }})</option>
            @endforeach
        </select></div>
    <div class="field"><label for="it-{{ $i }}-d">Serviço / descrição</label>
        <input id="it-{{ $i }}-d" name="items[{{ $i }}][description]" class="input" maxlength="200" required value="{{ $row['description'] ?? '' }}" placeholder="Consulta, procedimento, exame…"></div>
    <div class="field"><label for="it-{{ $i }}-a">Valor (R$)</label>
        <input id="it-{{ $i }}-a" name="items[{{ $i }}][amount]" class="input money-input" data-mask="money" inputmode="numeric" required data-amount value="{{ $row['amount'] ?? '' }}"></div>
    <div class="field"><label for="it-{{ $i }}-t">Divisão</label>
        <select id="it-{{ $i }}-t" name="items[{{ $i }}][split_type]" class="input" data-split-type>
            <option value="percent" @selected(($row['split_type'] ?? 'percent') === 'percent')>%</option>
            <option value="fixed" @selected(($row['split_type'] ?? null) === 'fixed')>R$ fixo</option>
        </select></div>
    <div class="field"><label for="it-{{ $i }}-v">Parte do profissional</label>
        <input id="it-{{ $i }}-v" name="items[{{ $i }}][split_value]" class="input money-input" data-mask="money" inputmode="numeric" data-split-value value="{{ $row['split_value'] ?? '' }}"></div>
    <div class="stack"><span class="ms-item__share" data-share>—</span>
        <button type="button" class="btn btn-sm btn-ghost" data-remove aria-label="Remover item">Remover</button></div>
</div>
