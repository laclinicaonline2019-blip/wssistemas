@extends('layouts.app')
@section('title', 'Nota '.$receipt->code)
@section('content')
<div class="top"><div><h1>Nota {{ $receipt->code }} <span class="badge b-{{ $receipt->status }}">{{ $receipt->statusLabel() }}</span></h1>
  <div class="sub"><a href="{{ route('customers.show', $receipt->customer) }}">{{ $receipt->customer->displayName() }}</a> · enviada {{ $receipt->created_at->format('d/m/Y H:i') }} por {{ $receipt->source }}
  @if($receipt->reviewer) · conferida por {{ $receipt->reviewer->name }} em {{ $receipt->reviewed_at->format('d/m/Y H:i') }}@endif</div></div>
  <form method="post" action="{{ route('receipts.ai', $receipt) }}">@csrf<button class="btn sm">🤖 Ler novamente com IA</button></form>
</div>
<div class="grid g2">
  <div class="card">
    @if($fileUrl)
      @if(str_starts_with((string)$receipt->mime, 'image'))<a href="{{ $fileUrl }}" target="_blank"><img src="{{ $fileUrl }}" style="width:100%;border-radius:8px" alt="nota fiscal"></a>
      @else<iframe src="{{ $fileUrl }}" style="width:100%;height:600px;border:0"></iframe>@endif
    @endif
    @if($receipt->issuer_cnpj || $receipt->issuer_name)<p class="small">Lido na nota: <b>{{ $receipt->issuer_name }}</b> · CNPJ {{ $receipt->issuer_cnpj }} · nº {{ $receipt->nf_number }}</p>@endif
  </div>
  <div>
    <div class="card">
      <h2>Conferência</h2>
      <form method="post" action="{{ route('receipts.verify', $receipt) }}">@csrf
        <div class="row">
          <div class="f"><label>Farmácia onde comprou *</label><select name="pharmacy_id" required><option value="">—</option>@foreach($pharmacies as $p)<option value="{{ $p->id }}" @selected($receipt->pharmacy_id === $p->id)>{{ $p->trade_name }} ({{ number_format($p->commission_percent, 1, ',', '') }}%)</option>@endforeach</select></div>
          <div class="f"><label>Total pago *</label><input name="total" value="{{ $receipt->total ? number_format($receipt->total, 2, ',', '') : '' }}" required></div>
        </div>
        <div class="row">
          <div class="f"><label>Data da compra</label><input type="date" name="purchase_date" value="{{ $receipt->purchase_date?->toDateString() }}"></div>
          <div class="f"><label>Nº da nota</label><input name="nf_number" value="{{ $receipt->nf_number }}"></div>
          <div class="f"><label>Pedido / cotação</label><select name="order_id"><option value="">Compra avulsa (sem pedido)</option>@foreach($orders as $o)<option value="{{ $o->id }}" @selected($receipt->order_id === $o->id)>{{ $o->code }} · {{ $o->pharmacy->trade_name }} · {{ money($o->total) }}</option>@endforeach</select></div>
        </div>
        <h3>Itens da nota</h3>
        <div class="table-wrap"><table>
          <tr><th>Descrição</th><th style="width:70px">Qtd</th><th style="width:100px">Unit.</th><th style="width:100px">Total</th><th>Cotado</th><th></th></tr>
          <tbody id="ritems">
          @foreach($receipt->items as $i => $it)
          <tr class="ri"><td><input name="items[{{ $i }}][description]" value="{{ $it->description }}"></td><td><input name="items[{{ $i }}][quantity]" value="{{ rtrim(rtrim(number_format($it->quantity, 2, ',', ''), '0'), ',') }}"></td>
            <td><input name="items[{{ $i }}][unit_price]" value="{{ number_format($it->unit_price, 2, ',', '') }}"></td><td><input name="items[{{ $i }}][total]" value="{{ number_format($it->total, 2, ',', '') }}"></td>
            <td class="small">@if($it->quoteItem)✔ {{ $it->quoteItem->description }} @if($it->quoted_unit_price)<br>cotado {{ money($it->quoted_unit_price) }}@endif @else<span class="badge b-aprovada">fora da cotação</span>@endif</td>
            <td><a href="#" class="btn sm r" data-remove-row=".ri">✕</a></td></tr>
          @endforeach
          </tbody>
        </table></div>
        <template id="ri-tpl"><tr class="ri"><td><input name="items[__i__][description]"></td><td><input name="items[__i__][quantity]" value="1"></td><td><input name="items[__i__][unit_price]"></td><td><input name="items[__i__][total]"></td><td></td><td><a href="#" class="btn sm r" data-remove-row=".ri">✕</a></td></tr></template>
        <p><a href="#" class="btn sm" data-add-row="ri-tpl" data-target="#ritems">+ item</a></p>
        <button class="btn g lg">✔ Confirmar conferência</button>
      </form>
    </div>
    <div class="card">
      <h3>Cotado × comprado</h3>
      <div class="grid g3">
        <div class="kpi"><div class="l">Cotado (pedido)</div><div class="v" style="font-size:17px">{{ money($comparison['quoted']) }}</div></div>
        <div class="kpi"><div class="l">Comprado (nota)</div><div class="v" style="font-size:17px">{{ money($comparison['bought']) }}</div></div>
        <div class="kpi"><div class="l">Diferença</div><div class="v" style="font-size:17px;color:{{ $comparison['difference'] >= 0 ? 'var(--green)' : 'var(--red)' }}">{{ money($comparison['difference']) }}</div><div class="s">itens extras {{ money($comparison['extra_items_total']) }}</div></div>
      </div>
      @if($pointsPreview)<p class="small">🎁 Pontos {{ $receipt->points_awarded ? 'lançados: '.$receipt->points_awarded : 'previstos: '.$pointsPreview['points'] }} — {{ implode(' · ', $pointsPreview['details']) }}</p>@endif
      @if($receipt->status === 'conferida')<p class="small">Comissão: {{ $receipt->order_id ? 'calculada no pedido sobre o total da nota' : money($receipt->commission_amount).' ('.number_format($receipt->commission_percent, 2, ',', '').'%)' }}</p>@endif
    </div>
    @if($receipt->status === 'pendente')
    <div class="card"><form method="post" action="{{ route('receipts.reject', $receipt) }}" class="row">@csrf<input name="reason" placeholder="Motivo (ilegível, duplicada, não é nota...)" required><button class="btn r auto">Rejeitar</button></form></div>
    @endif
  </div>
</div>
@endsection
