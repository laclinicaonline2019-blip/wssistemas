{{-- Linha de medicamento da receita. $i = índice (ou __I__ no template), $item = dados (old) --}}
@php
    $ctl = $item['control_type'] ?? 'none';
    $needsNotification = in_array($ctl, \App\Modules\Documents\Services\DocumentService::NOTIFICATION_REQUIRED, true);
@endphp
<div class="rx-row card" data-rx-row data-control="{{ $ctl }}">
    <div class="card__body form-grid">
        <input type="hidden" name="items[{{ $i }}][medication_id]" value="{{ $item['medication_id'] ?? '' }}" data-rx-med-id>
        <div class="field col-6">
            <label>Medicamento</label>
            <input name="items[{{ $i }}][name]" class="input" value="{{ $item['name'] ?? '' }}" maxlength="250" data-rx-name @if (! empty($item['medication_id'])) readonly @endif>
        </div>
        <div class="field col-3">
            <label>Controle</label>
            <select name="items[{{ $i }}][control_type]" class="input" data-rx-control @if (! empty($item['medication_id'])) disabled @endif>
                @foreach ($controlTypes as $k => $l)<option value="{{ $k }}" @selected($ctl === $k)>{{ $k === 'none' ? 'Livre' : $l }}</option>@endforeach
            </select>
        </div>
        <div class="field col-3">
            <label>Quantidade</label>
            <input name="items[{{ $i }}][quantity]" class="input" value="{{ $item['quantity'] ?? '' }}" maxlength="60" placeholder="1 caixa / 30 comp." data-rx-quantity>
        </div>
        <div class="field col-8">
            <label>Posologia</label>
            <input name="items[{{ $i }}][posology]" class="input" value="{{ $item['posology'] ?? '' }}" maxlength="500" data-rx-posology>
        </div>
        <div class="field col-2">
            <label>Via</label>
            <input name="items[{{ $i }}][route]" class="input" value="{{ $item['route'] ?? '' }}" maxlength="40" placeholder="oral" data-rx-route>
        </div>
        <div class="field col-2 actions"><label class="sr-only">Remover</label><button type="button" class="btn btn-ghost" data-rx-remove aria-label="Remover medicamento">Remover</button></div>
        <div class="field col-6 {{ $needsNotification ? '' : 'hidden' }}" data-rx-notification>
            <label>Nº da Notificação de Receita (talão oficial)</label>
            <input name="items[{{ $i }}][notification_number]" class="input" value="{{ $item['notification_number'] ?? '' }}" maxlength="20">
        </div>
        <div class="col-12 small" data-rx-hint></div>
    </div>
</div>
