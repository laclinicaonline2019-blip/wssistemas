{{-- Cartão fidelidade: $customer, $card (LoyaltyService::card) --}}
<div class="lcard">
  <div class="lcard-top"><img src="{{ asset('img/icone.png') }}" alt=""><div><b>Pesquisa<span>Farma</span></b><small>Cartão Fidelidade</small></div></div>
  <div class="lcard-pts"><span>{{ number_format($card['points'], 0, ',', '.') }}</span> pontos</div>
  @if($card['next'])
    <div class="lcard-bar"><i style="width:{{ $card['progress'] }}%"></i></div>
    <div class="lcard-next">Faltam <b>{{ $card['missing'] }}</b> pts para <b>{{ $card['next']->name }}</b></div>
  @elseif($card['available']->isNotEmpty())
    <div class="lcard-next">🎁 Todos os brindes liberados!</div>
  @endif
  <div class="lcard-foot"><span>{{ mb_strtoupper(\Illuminate\Support\Str::limit($customer->displayName(), 26, '')) }}</span><span>Nº {{ $card['number'] }}</span></div>
</div>
@if($card['available']->isNotEmpty())<div class="small" style="margin-top:6px">🎁 Já pode trocar por: <b>{{ $card['available']->pluck('name')->implode(', ') }}</b></div>@endif
<div class="small muted">{{ $card['purchases'] }} compra(s) com nota conferida · {{ $card['earned'] }} pts ganhos no total</div>
