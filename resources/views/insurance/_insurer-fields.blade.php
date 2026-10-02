@php $i = $insurer ?? null; @endphp
<x-field name="name" label="Nome do convênio" col="col-6" required :value="$i?->name" maxlength="120" />
<x-field name="ans_registry" label="Registro ANS" col="col-3" :value="$i?->ans_registry" inputmode="numeric" maxlength="6" help="6 dígitos — obrigatório no XML TISS" />
<x-field name="cnpj" label="CNPJ da operadora" col="col-3" :value="$i?->cnpj" mask="cnpj" />
<x-field name="provider_code" label="Código do prestador na operadora" col="col-4" :value="$i?->provider_code" maxlength="14" help="Código da clínica no convênio. Sem ele, o XML usa o CNPJ da unidade." />
<div class="field col-2"><label for="f-tiss">Versão TISS</label>
    <select id="f-tiss" name="tiss_version" class="input">@foreach (\App\Modules\Insurance\Models\Insurer::TISS_VERSIONS as $v)<option value="{{ $v }}" @selected(old('tiss_version', $i?->tiss_version) === $v)>{{ $v }}</option>@endforeach</select></div>
<x-field name="payment_term_days" label="Prazo de pagamento (dias)" type="number" min="0" max="365" col="col-3" :value="$i?->payment_term_days ?? 30" required />
<x-field name="max_guides_per_batch" label="Máx. guias por lote" type="number" min="1" max="100" col="col-3" :value="$i?->max_guides_per_batch ?? 100" required />
<x-field name="phone" label="Telefone" col="col-4" :value="$i?->phone" mask="phone" />
<x-field name="email" label="E-mail" type="email" col="col-4" :value="$i?->email" />
<x-field name="portal_url" label="Portal do prestador (https)" col="col-4" :value="$i?->portal_url" placeholder="https://" />
<div class="field col-12"><label for="f-notes">Observações (regras de faturamento, contatos)</label><textarea id="f-notes" name="notes" class="input" rows="2" maxlength="1000">{{ old('notes', $i?->notes) }}</textarea></div>
