{{-- Formulário de resposta de preços (usado pela farmácia e pelo televendas). --}}
@php($offers = $dispatch->offers->groupBy('quote_item_id'))
<form method="post" action="{{ $action }}">@csrf
  <div class="table-wrap"><table class="resp">
    <tr><th class="c-item">Item pedido</th><th>Tipo</th><th class="hide-sm">Produto / laboratório</th><th class="c-price">Preço unit.</th></tr>
    @foreach($dispatch->quote->items as $item)
      @foreach(\App\Models\QuoteOffer::TYPES as $type => $typeLabel)
      @php($o = ($offers[$item->id] ?? collect())->firstWhere('type', $type))
      <tr @if($item->preference !== 'qualquer' && $item->preference !== $type) style="opacity:.55" @endif>
        @if($loop->first)<td rowspan="3"><b>{{ $item->quantity }}x {{ $item->description }}</b><div class="small muted">{{ $item->presentation }}</div>
          @if($item->preference !== 'qualquer')<span class="badge b-pendente">{{ \App\Models\QuoteItem::PREFERENCES[$item->preference] }}</span>@endif
          @if($item->notes)<div class="small">Obs.: {{ $item->notes }}</div>@endif</td>@endif
        <td class="nowrap">{{ $typeLabel }}<input type="hidden" name="offers[{{ $item->id }}][{{ $loop->index }}][type]" value="{{ $type }}"></td>
        <td class="hide-sm"><div class="row" style="gap:4px"><input name="offers[{{ $item->id }}][{{ $loop->index }}][product_name]" value="{{ $o?->product_name }}" placeholder="Nome do produto">
          <input name="offers[{{ $item->id }}][{{ $loop->index }}][laboratory]" value="{{ $o?->laboratory }}" placeholder="Laboratório"></div></td>
        <td><input name="offers[{{ $item->id }}][{{ $loop->index }}][price]" value="{{ $o ? number_format($o->unit_price, 2, ',', '') : '' }}" placeholder="R$ 0,00" inputmode="decimal"></td>
      </tr>
      @endforeach
    @endforeach
  </table></div>
  <p class="small muted">Deixe o preço em branco quando não tiver o produto naquele tipo.</p>

  <div class="grid g3">
    <div class="f"><label class="check"><input type="checkbox" name="delivers" value="1" @checked($dispatch->delivers)> Entregamos no endereço do cliente</label></div>
    <div class="f"><label>Taxa de entrega (R$)</label><input name="delivery_fee" value="{{ $dispatch->delivery_fee !== null ? number_format($dispatch->delivery_fee, 2, ',', '') : '' }}" inputmode="decimal"></div>
    <div class="f"><label>Prazo de entrega (min)</label><input name="delivery_eta_min" type="number" value="{{ $dispatch->delivery_eta_min }}"></div>
  </div>
  <div class="grid g2">
    <div class="f"><label>Formas de pagamento</label><input name="payment_methods" value="{{ $dispatch->payment_methods ?? $dispatch->pharmacy->payment_methods }}" placeholder="PIX, cartão, dinheiro"></div>
    <div class="f"><label>Observações</label><input name="notes" value="{{ $dispatch->notes }}"></div>
  </div>

  <h3>🎁 Sugestões / promoções para agregar à venda</h3>
  <div id="sug-{{ $dispatch->id }}">
    @foreach($dispatch->suggestions->count() ? $dispatch->suggestions : [null] as $s)
    <div class="row sug" style="margin-bottom:6px">
      <input name="suggestions[{{ $loop->index }}][product_name]" value="{{ $s?->product_name }}" placeholder="Produto (ex.: Vitamina C 1g)">
      <input name="suggestions[{{ $loop->index }}][description]" value="{{ $s?->description }}" placeholder="Descrição (opcional)">
      <input name="suggestions[{{ $loop->index }}][original_price]" value="{{ $s?->original_price ? number_format($s->original_price, 2, ',', '') : '' }}" placeholder="De R$">
      <input name="suggestions[{{ $loop->index }}][price]" value="{{ $s ? number_format($s->price, 2, ',', '') : '' }}" placeholder="Por R$">
    </div>
    @endforeach
  </div>
  <template id="sug-tpl-{{ $dispatch->id }}"><div class="row sug" style="margin-bottom:6px">
      <input name="suggestions[__i__][product_name]" placeholder="Produto"><input name="suggestions[__i__][description]" placeholder="Descrição (opcional)">
      <input name="suggestions[__i__][original_price]" placeholder="De R$"><input name="suggestions[__i__][price]" placeholder="Por R$"></div></template>
  <p><a href="#" class="btn sm" data-add-row="sug-tpl-{{ $dispatch->id }}" data-target="#sug-{{ $dispatch->id }}">+ sugestão</a></p>

  <button class="btn g lg">{{ $dispatch->status === 'respondida' ? 'Atualizar resposta' : 'Enviar preços' }}</button>
</form>
@if($declineAction)
<form method="post" action="{{ $declineAction }}" style="margin-top:10px" class="row">@csrf
  <input name="reason" placeholder="Motivo (ex.: sem estoque)"><button class="btn r auto" data-confirm="Confirmar que sua farmácia não atende esta cotação?">Não temos / não atendemos</button>
</form>
@endif
