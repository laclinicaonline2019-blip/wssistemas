@extends('layouts.app')
@section('title', 'Cotação '.$quote->code)
@section('content')
@php($c = $quote->customer)
<div class="top" @if($quote->isOpen()) data-poll="{{ route('quotes.status', $quote) }}" @endif>
  <div>
    <h1>Cotação {{ $quote->code }} <span class="badge b-{{ $quote->status }}">{{ $quote->statusLabel() }}</span></h1>
    <div class="sub"><a href="{{ route('customers.show', $c) }}"><b>{{ $c->displayName() }}</b></a> · {{ \App\Support\Text::formatPhone($c->phone) }} · <a href="{{ route('inbox.show', $c) }}">💬 conversa</a>
      · televendas: {{ $quote->operator?->name ?? '—' }} @if($quote->isOpen())· <span id="live-status" class="muted"></span>@endif</div>
  </div>
  @if($quote->order)<a class="btn g" href="{{ route('orders.show', $quote->order) }}">🧾 Pedido {{ $quote->order->code }} · {{ money($quote->order->total) }}</a>@endif
</div>

<div class="grid g4" style="margin-bottom:16px">
  <div class="kpi"><div class="l">Recebida</div><div class="v" style="font-size:16px">{{ $quote->requested_at?->format('d/m H:i') }}</div><div class="s">canal: {{ $quote->channel }}</div></div>
  <div class="kpi"><div class="l">Envio às farmácias</div><div class="v" style="font-size:16px">{{ minutes_label(\App\Models\Quote::minutesBetween($quote->requested_at, $quote->dispatched_at)) }}</div><div class="s">{{ $quote->dispatched_at?->format('H:i') ?? 'não enviada' }}</div></div>
  <div class="kpi"><div class="l">1ª resposta</div><div class="v" style="font-size:16px">{{ minutes_label(\App\Models\Quote::minutesBetween($quote->dispatched_at, $quote->first_response_at)) }}</div><div class="s">após o envio</div></div>
  <div class="kpi"><div class="l">Pedido até finalizar</div><div class="v" style="font-size:16px">{{ minutes_label(\App\Models\Quote::minutesBetween($quote->requested_at, $quote->closed_at ?? ($quote->isOpen() ? now() : null))) }}</div>
    <div class="s">Indicaria: <b>{{ $quote->nps_score !== null ? $quote->nps_score.'/10' : '—' }}</b>@if($quote->feedback_rating) · <span class="stars">{{ str_repeat('★', $quote->feedback_rating) }}</span>@endif
      @if($quote->customer_reported === 'comprou') · <b>cliente disse que comprou</b>@elseif($quote->customer_reported === 'desistiu') · cliente desistiu @endif</div></div>
</div>

<div class="grid g2">
  {{-- PEDIDO DO CLIENTE --}}
  <div class="card">
    <h2>📩 O que o cliente pediu</h2>
    @if($quote->raw_text)<div style="white-space:pre-wrap;background:#f8fafc;padding:10px;border-radius:8px">{{ $quote->raw_text }}</div>@endif
    @if($attachmentUrl)
      @if(str_starts_with((string)$quote->attachment_mime, 'image'))<a href="{{ $attachmentUrl }}" target="_blank"><img src="{{ $attachmentUrl }}" style="max-width:100%;max-height:340px;border-radius:8px;margin-top:8px" alt="anexo"></a>
      @else<p><a class="btn" href="{{ $attachmentUrl }}" target="_blank">📎 Abrir anexo</a></p>@endif
    @endif
    @if($quote->isOpen())<form method="post" action="{{ route('quotes.ai', $quote) }}" style="margin-top:8px">@csrf<button class="btn sm">🤖 Ler de novo com IA</button>
      @if($quote->ai_extracted)<span class="small muted">Itens montados pela IA — confira antes de enviar.</span>@endif</form>@endif
  </div>

  {{-- ENTREGA / ALCANCE --}}
  <div class="card">
    <h2>📍 Entrega e alcance</h2>
    <form method="post" action="{{ route('quotes.update', $quote) }}">@csrf @method('PUT')
      <div class="row">
        <div class="f"><label>Bairro do cliente</label><select name="neighborhood_id"><option value="">—</option>@include('partials.neighborhood-options', ['selected' => $quote->neighborhood_id])</select></div>
        <div class="f"><label>Receber</label><select name="fulfillment"><option value="entrega" @selected($quote->fulfillment==='entrega')>🛵 Entrega</option><option value="retirada" @selected($quote->fulfillment==='retirada')>🏪 Retirada</option></select></div>
      </div>
      <div class="f"><label>Endereço de entrega</label><input name="delivery_address" value="{{ $quote->delivery_address }}"></div>
      <div class="row">
        <div class="f"><label>Farmácias</label><select name="scope"><option value="proximas" @selected($quote->scope==='proximas')>Mais próximas</option><option value="ampliada" @selected($quote->scope==='ampliada')>Ampliada (mais distantes)</option></select></div>
        <div class="f"><label>Raio (km)</label><input name="radius_km" type="number" step="0.5" value="{{ $quote->radius_km }}"></div>
      </div>
      <div class="f"><label>Observações internas</label><input name="notes" value="{{ $quote->notes }}"></div>
      <button class="btn sm">Salvar</button>
    </form>
  </div>
</div>

{{-- ITENS --}}
<div class="card">
  <h2>💊 Itens da cotação</h2>
  <form method="post" action="{{ route('quotes.items', $quote) }}">@csrf
    <div class="table-wrap"><table>
      <tr><th style="width:70px">Qtd</th><th>Medicamento / produto</th><th>Apresentação</th><th>Preferência</th><th>Obs.</th><th></th></tr>
      <tbody id="items">
      @foreach($quote->items as $i => $item)
      <tr class="it"><td><input type="hidden" name="items[{{ $i }}][id]" value="{{ $item->id }}"><input type="number" min="1" name="items[{{ $i }}][quantity]" value="{{ $item->quantity }}"></td>
        <td><input name="items[{{ $i }}][description]" value="{{ $item->description }}" required></td>
        <td><input name="items[{{ $i }}][presentation]" value="{{ $item->presentation }}"></td>
        <td><select name="items[{{ $i }}][preference]">@foreach(\App\Models\QuoteItem::PREFERENCES as $k => $v)<option value="{{ $k }}" @selected($item->preference === $k)>{{ $v }}</option>@endforeach</select></td>
        <td><input name="items[{{ $i }}][notes]" value="{{ $item->notes }}"></td>
        <td>@if($quote->isOpen())<a href="#" class="btn sm r" data-remove-row=".it">✕</a>@endif</td></tr>
      @endforeach
      </tbody>
    </table></div>
    <template id="item-tpl"><tr class="it"><td><input type="number" min="1" name="items[__i__][quantity]" value="1"></td><td><input name="items[__i__][description]" placeholder="Ex.: Dipirona" required></td>
      <td><input name="items[__i__][presentation]" placeholder="500mg 10 comp."></td><td><select name="items[__i__][preference]">@foreach(\App\Models\QuoteItem::PREFERENCES as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></td>
      <td><input name="items[__i__][notes]"></td><td><a href="#" class="btn sm r" data-remove-row=".it">✕</a></td></tr></template>
    @if($quote->isOpen())
    <div class="row auto" style="margin-top:8px"><a href="#" class="btn sm" data-add-row="item-tpl" data-target="#items">+ item</a><button class="btn p sm">Salvar itens</button></div>
    @endif
  </form>
</div>

{{-- ENVIO ÀS FARMÁCIAS --}}
@if($quote->isOpen() && $quote->status !== 'pedido')
<div class="card">
  <h2>🏪 Enviar para farmácias {{ $quote->scope === 'ampliada' ? '(busca ampliada)' : 'mais próximas' }}</h2>
  @if($candidates->isEmpty() && $far->isEmpty())
    <div class="empty">Nenhuma farmácia disponível. Confira o bairro do cliente, aumente o raio ou use a busca ampliada.</div>
  @else
  <form method="post" action="{{ route('quotes.dispatch', $quote) }}">@csrf
    <div class="table-wrap"><table>
      <tr><th><input type="checkbox" data-check-all=".ph-near"></th><th>Farmácia</th><th>Bairro</th><th class="num">Distância</th><th>Entrega</th><th class="num">Comissão</th></tr>
      @foreach($candidates as $cand)
      <tr><td><input type="checkbox" class="ph-near" name="pharmacies[]" value="{{ $cand['pharmacy']->id }}" @checked($loop->index < $maxPharmacies)></td>
        <td><b>{{ $cand['pharmacy']->trade_name }}</b></td><td>{{ $cand['pharmacy']->neighborhood?->name }}</td>
        <td class="num">{{ $cand['distance'] !== null ? number_format($cand['distance'], 1, ',', '.').' km' : 'mesmo bairro' }}</td>
        <td>{!! $cand['delivers'] ? '🛵 '.($cand['fee'] > 0 ? money($cand['fee']) : 'grátis') : '<span class="muted">só retirada</span>' !!}</td>
        <td class="num">{{ number_format($cand['pharmacy']->commission_percent, 1, ',', '') }}%</td></tr>
      @endforeach
      @if($far->isNotEmpty())
      <tr><th colspan="6">Mais distantes — envie se quiser comparar se vale a pena ir mais longe</th></tr>
      @foreach($far as $cand)
      <tr><td><input type="checkbox" name="pharmacies[]" value="{{ $cand['pharmacy']->id }}"></td>
        <td>{{ $cand['pharmacy']->trade_name }}</td><td>{{ $cand['pharmacy']->neighborhood?->name }}</td>
        <td class="num">{{ $cand['distance'] !== null ? number_format($cand['distance'], 1, ',', '.').' km' : '—' }}</td>
        <td>{!! $cand['delivers'] ? '🛵 '.money($cand['fee']) : '<span class="muted">só retirada</span>' !!}</td>
        <td class="num">{{ number_format($cand['pharmacy']->commission_percent, 1, ',', '') }}%</td></tr>
      @endforeach
      @endif
    </table></div>
    <button class="btn g lg" style="margin-top:10px">📤 Enviar cotação pelo WhatsApp</button>
  </form>
  @endif
</div>
@endif

{{-- RESPOSTAS --}}
@if($quote->dispatches->isNotEmpty())
<div class="card">
  <h2>⏱️ Retorno das farmácias</h2>
  <div class="table-wrap"><table>
    <tr><th>Farmácia</th><th>Situação</th><th class="num">Distância</th><th>Enviada</th><th class="num">Tempo de resposta</th><th class="num">Total</th><th></th></tr>
    @foreach($quote->dispatches->sortBy('rank') as $d)
    <tr>
      <td><b>{{ $d->pharmacy->trade_name }}</b><div class="small muted">{{ $d->pharmacy->neighborhood?->name }} @if(! $d->within_radius)· fora do raio @endif</div></td>
      <td><span class="badge b-{{ $d->status }}">{{ $d->statusLabel() }}</span>@if($d->viewed_at && $d->status==='visualizada')<div class="small muted">viu {{ $d->viewed_at->format('H:i') }}</div>@endif</td>
      <td class="num">{{ $d->distance_km !== null ? number_format($d->distance_km, 1, ',', '.').' km' : '—' }}</td>
      <td class="small">{{ $d->sent_at?->format('H:i') }}</td>
      <td class="num">{{ minutes_label($d->responseMinutes()) }}</td>
      <td class="num">{{ $d->total ? money($d->total) : '—' }}</td>
      <td>@if($quote->isOpen())<details><summary class="small">Registrar resposta (telefone)</summary>
        <div style="min-width:min(80vw,760px)">@include('pharmacy._form', ['dispatch' => $d, 'action' => route('quotes.respond_for', [$quote, $d]), 'declineAction' => null])
        <form method="post" action="{{ route('quotes.respond_for', [$quote, $d]) }}">@csrf<input type="hidden" name="decline" value="1"><button class="btn sm r" style="margin-top:6px">Farmácia não atende</button></form></div></details>@endif</td>
    </tr>
    @endforeach
  </table></div>
</div>
@endif

{{-- RANKING --}}
@if($ranking->isNotEmpty())
<div class="card">
  <h2>🏆 Melhor preço geral (por ordem)</h2>
  <p class="small muted">Ordem: quem tem mais itens da cotação e, depois, o menor total com entrega.</p>
  <div class="grid g3">
  @foreach($ranking as $r)
    @php($d = $r['dispatch'])
    <div class="card {{ $r['rank'] === 1 ? 'rank1' : '' }}" style="margin:0">
      <div class="top" style="margin-bottom:6px"><div><span class="medal">{{ ['🥇','🥈','🥉'][$r['rank']-1] ?? $r['rank'].'º' }}</span> <b>{{ $d->pharmacy->trade_name }}</b>
        <div class="small muted">📍 {{ $d->pharmacy->fullAddress() }} · <a href="{{ $d->pharmacy->mapsUrl() }}" target="_blank">mapa</a></div>
        <div class="small">{{ $d->distance_km !== null ? number_format($d->distance_km, 1, ',', '.').' km' : '' }}
          {{ $d->delivers ? '· 🛵 entrega '.($r['delivery'] > 0 ? money($r['delivery']) : 'grátis').($d->delivery_eta_min ? ' ~'.$d->delivery_eta_min.'min' : '') : '· 🏪 só retirada' }}</div></div></div>
      <table>
        @foreach($quote->items as $item)
        @php($o = $r['lines'][$item->id] ?? null)
        <tr><td class="small">{{ $item->quantity }}x {{ $item->description }}@if($o)<div class="muted">{{ $o->typeLabel() }} {{ $o->product_name }} {{ $o->laboratory ? '· '.$o->laboratory : '' }}</div>@endif</td>
          <td class="num small">{!! $o ? money($o->unit_price * $item->quantity) : '<span style="color:var(--red)">não tem</span>' !!}</td></tr>
        @endforeach
        <tr><td class="small">Subtotal</td><td class="num small">{{ money($r['subtotal']) }}</td></tr>
        <tr><td class="small">Entrega</td><td class="num small">{{ money($r['delivery']) }}</td></tr>
        <tr><td><b>Total</b> @if(! $r['complete'])<span class="badge b-pendente">{{ $r['items_available'] }}/{{ $quote->items->count() }} itens</span>@endif</td><td class="num price">{{ money($r['total']) }}</td></tr>
      </table>
      @if($d->suggestions->isNotEmpty())<div class="small" style="margin-top:6px"><b>🎁 Sugestões:</b> @foreach($d->suggestions as $s){{ $s->product_name }} {{ money($s->price) }}@if(! $loop->last), @endif @endforeach</div>@endif
      @if($d->notes)<div class="small muted">Obs.: {{ $d->notes }}</div>@endif
      @if($d->payment_methods)<div class="small muted">Pagamento: {{ $d->payment_methods }}</div>@endif

      @if($quote->isOpen() && ! ($quote->order && $quote->order->status !== 'cancelado'))
      <details style="margin-top:8px"><summary>🧾 Gerar pedido nesta farmácia</summary>
        <form method="post" action="{{ route('quotes.order', $quote) }}" style="margin-top:6px">@csrf
          <input type="hidden" name="dispatch_id" value="{{ $d->id }}">
          @foreach($quote->items as $item)
            @php($opts = $d->offers->where('quote_item_id', $item->id)->sortBy('unit_price'))
            <div class="f"><label>{{ $item->quantity }}x {{ $item->description }}</label>
              <select name="offer[{{ $item->id }}]"><option value="0">— não incluir —</option>
                @foreach($opts as $o)<option value="{{ $o->id }}" @selected(($r['lines'][$item->id] ?? null)?->id === $o->id)>{{ $o->typeLabel() }} {{ $o->product_name }} — {{ money($o->unit_price) }} un.</option>@endforeach
              </select></div>
          @endforeach
          @foreach($d->suggestions as $s)<label class="check"><input type="checkbox" name="suggestions[]" value="{{ $s->id }}"> + {{ $s->product_name }} ({{ money($s->price) }})</label>@endforeach
          <div class="f"><label>Receber</label><select name="fulfillment"><option value="entrega" @selected($quote->fulfillment==='entrega' && $d->delivers) @disabled(! $d->delivers)>Entrega</option><option value="retirada" @selected($quote->fulfillment==='retirada' || ! $d->delivers)>Retirada</option></select></div>
          <button class="btn g" data-confirm="Gerar o pedido e avisar a farmácia e o cliente?">Confirmar pedido</button>
        </form>
      </details>
      @endif
    </div>
  @endforeach
  </div>

  <h3 style="margin-top:16px">🧮 Cesta ideal (menor preço de cada item, em qualquer farmácia)</h3>
  <div class="table-wrap"><table>
    <tr><th>Item</th><th>Onde é mais barato</th><th class="num">Preço unit.</th><th class="num">Total</th></tr>
    @php($ideal = 0)
    @foreach($quote->items as $item)
      @php($b = $bestPerItem[$item->id] ?? null)
      @php($ideal += $b ? $b['offer']->unit_price * $item->quantity : 0)
      <tr><td>{{ $item->label() }}</td><td>{{ $b ? $b['pharmacy']->trade_name.' ('.$b['offer']->typeLabel().')' : '—' }}</td>
        <td class="num">{{ $b ? money($b['offer']->unit_price) : '—' }}</td><td class="num">{{ $b ? money($b['offer']->unit_price * $item->quantity) : '—' }}</td></tr>
    @endforeach
    <tr><td colspan="3"><b>Total sem entrega</b> <span class="small muted">— diferença para o 1º lugar: {{ money(($ranking->first()['subtotal'] ?? 0) - $ideal) }}</span></td><td class="num"><b>{{ money($ideal) }}</b></td></tr>
  </table></div>
</div>

@if($quote->isOpen())
<div class="card">
  <h2>📲 Enviar resultado ao cliente</h2>
  <div class="grid g2">
    <textarea id="preview" rows="14" readonly>{{ $preview }}</textarea>
    <div>
      <form method="post" action="{{ route('quotes.present', $quote) }}">@csrf
        <div class="f"><label>Quantas opções enviar</label><select name="top"><option>1</option><option>2</option><option selected>3</option><option>5</option></select></div>
        <button class="btn g lg">Enviar pelo WhatsApp</button>
      </form>
      <p><a href="#" class="btn sm" data-copy="#preview">Copiar texto</a></p>
      <p class="small muted">O cliente responde 1, 2 ou 3 e o pedido é gerado automaticamente na farmácia escolhida.</p>
    </div>
  </div>
</div>
@endif
@endif

<div class="grid g2">
  @if($quote->isOpen())
  <div class="card"><h3>Encerrar sem venda</h3>
    <form method="post" action="{{ route('quotes.lost', $quote) }}" class="row">@csrf
      <select name="reason"><option>Achou mais barato em outro lugar</option><option>Desistiu da compra</option><option>Farmácias sem o produto</option><option>Sem retorno do cliente</option><option>Preço alto</option><option>Outro</option></select>
      <button class="btn r auto">Marcar como perdida</button></form></div>
  @endif
  <div class="card"><h3>Avaliação do cliente</h3>
    <form method="post" action="{{ route('quotes.feedback', $quote) }}" class="row">@csrf
      <select name="rating" class="auto">@for($i=5;$i>=1;$i--)<option value="{{ $i }}" @selected($quote->feedback_rating==$i)>{{ $i }} ★</option>@endfor</select>
      <input name="comment" value="{{ $quote->feedback_comment }}" placeholder="Comentário"><button class="btn auto">Salvar</button></form></div>
</div>

<div class="card"><h3>🕘 Histórico</h3>
  @foreach($timeline as $log)<div class="small"><span class="muted">{{ $log->created_at->format('d/m H:i:s') }}</span> — {{ $log->description }} <span class="muted">{{ $log->user?->name ? '('.$log->user->name.')' : '' }}</span></div>@endforeach
</div>
@endsection
