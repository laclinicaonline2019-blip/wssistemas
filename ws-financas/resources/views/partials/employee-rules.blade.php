{{-- Regras de funcionário: aprovação de despesas, limite e centros de custo (e empresas, para número avulso). --}}
@php($r = $rules ?? null)
<fieldset class="full rules">
  <legend class="small"><b>Regras de lançamento</b></legend>
  @if (! empty($withCompanies))
    <label class="inline-check"><input type="hidden" name="all_rule_companies" value="0"><input type="checkbox" name="all_rule_companies" value="1" @checked(! is_array($r?->company_ids))> Pode lançar em todas as empresas</label>
    <div class="checks"><span class="small muted">Ou só nestas:</span>@foreach ($companies as $co)<label><input type="checkbox" name="rule_companies[]" value="{{ $co->id }}" @checked(is_array($r?->company_ids) && in_array($co->id, $r->company_ids, true))> {{ $co->name }}</label>@endforeach</div>
  @endif
  <label class="inline-check"><input type="hidden" name="requires_approval" value="0"><input type="checkbox" name="requires_approval" value="1" @checked($r?->requires_approval)> Despesas precisam de aprovação (administrador ou gestor)</label>
  <div class="field"><label>Aprovar só acima de (R$) <span class="muted">vazio = todas</span></label><input name="approval_limit" inputmode="decimal" placeholder="Ex.: 200,00" value="{{ $r?->approval_limit_cents !== null && $r ? number_format($r->approval_limit_cents / 100, 2, ',', '.') : '' }}"></div>
  @if ($centers->isNotEmpty())
    <label class="inline-check"><input type="hidden" name="all_cost_centers" value="0"><input type="checkbox" name="all_cost_centers" value="1" @checked(! is_array($r?->cost_center_ids))> Todos os centros de custo</label>
    <div class="checks"><span class="small muted">Ou só estes:</span>@foreach ($centers as $cc)<label><input type="checkbox" name="cost_centers[]" value="{{ $cc->id }}" @checked(is_array($r?->cost_center_ids) && in_array($cc->id, $r->cost_center_ids, true))> {{ $cc->name }} <span class="muted">({{ $cc->company->name }})</span></label>@endforeach</div>
  @endif
  <p class="small muted mt0">Administrador e gestor nunca precisam de aprovação. Despesa pendente não entra no saldo nem nos relatórios até ser aprovada.</p>
</fieldset>
