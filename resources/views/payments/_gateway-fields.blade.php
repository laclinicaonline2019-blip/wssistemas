@php $p = $provider; @endphp
<x-field name="name" label="Nome de exibição" col="col-4" maxlength="80" :value="$g?->name" placeholder="ex.: ASAAS — Matriz" />
@if ($p !== 'mock')
    <div class="field col-4"><label>Modo</label>
        <select name="mode" class="input"><option value="sandbox" @selected(($g?->mode ?? 'sandbox') === 'sandbox')>SANDBOX (homologação)</option><option value="production" @selected($g?->mode === 'production')>Produção</option></select></div>
@endif
@if ($p === null || $p === 'asaas')
    <div class="field col-6"><label>ASAAS — chave de API (access_token)</label>
        <input name="credentials[api_key]" type="password" class="input" autocomplete="off" placeholder="{{ $g?->credential('api_key') ? '•••••••• configurada (deixe em branco para manter)' : '$aact_...' }}"></div>
@endif
@if ($p === null || $p === 'cielo')
    <div class="field col-3"><label>Cielo — ClientId</label><input name="credentials[client_id]" class="input" autocomplete="off" placeholder="{{ $g?->credential('client_id') ? 'configurado' : '' }}"></div>
    <div class="field col-3"><label>Cielo — ClientSecret</label><input name="credentials[client_secret]" type="password" class="input" autocomplete="off" placeholder="{{ $g?->credential('client_secret') ? '•••••••• configurado' : '' }}"></div>
@endif
<x-field name="settings[max_installments]" label="Parcelas máx. no cartão" type="number" min="1" max="12" col="col-3" :value="$g?->setting('max_installments', 1) ?? 1" />
<label class="check col-3"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked($g?->is_active ?? true)><span>Ativo</span></label>
<label class="check col-3"><input type="hidden" name="is_default" value="0"><input type="checkbox" name="is_default" value="1" @checked($g?->is_default ?? false)><span>Padrão</span></label>
@if ($p === null)<p class="help col-12">Preencha apenas os campos do gateway escolhido. O gateway MOCK não usa credenciais (simulação).</p>@endif
