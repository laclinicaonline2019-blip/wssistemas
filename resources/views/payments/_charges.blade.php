{{-- Cobranças online da conta a receber. $r, $charges, $gateways --}}
@php
    use App\Core\Support\Format;
    use App\Modules\Payments\Models\PaymentCharge;
    $me = auth()->user();
    $open = in_array($r->status, ['open', 'partial'], true);
    $badge = ['pending' => 'badge-warning', 'paid' => 'badge-success', 'overdue' => 'badge-danger', 'cancelled' => '', 'refunded' => '', 'failed' => 'badge-danger', 'review' => 'badge-danger'];
@endphp
<section class="card mt-2">
    <div class="card__head"><h2>Cobrança online (PIX, boleto, cartão)</h2></div>
    <div class="card__body stack">
        @if ($open && $me->hasPermission('pagamento.cobrar'))
            @if ($gateways->isEmpty())
                <p class="small muted">Nenhum gateway configurado.@if ($me->hasPermission('integracao.gerenciar')) <a href="{{ route('gateways.index') }}">Configurar ASAAS/Cielo</a>.@endif</p>
            @else
                <form method="post" action="{{ route('charges.store', $r) }}" class="form-grid">
                    @csrf
                    <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                    <div class="field col-3"><label for="ch-g">Gateway</label>
                        <select id="ch-g" name="gateway_id" class="input">@foreach ($gateways as $g)<option value="{{ $g->id }}">{{ $g->name }}{{ $g->modeBadge() ? ' ('.$g->modeBadge().')' : '' }}</option>@endforeach</select></div>
                    <div class="field col-3"><label for="ch-t">Forma</label>
                        <select id="ch-t" name="billing_type" class="input">@foreach (PaymentCharge::BILLING_TYPES as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
                    <x-field name="amount" label="Valor (R$)" col="col-3" mask="money" inputmode="numeric" :value="number_format($r->balanceCents() / 100, 2, ',', '.')" />
                    <x-field name="due_date" label="Vencimento" type="date" col="col-3" :value="now('America/Sao_Paulo')->addDays(3)->toDateString()" />
                    <div class="col-12 form-actions"><span class="help">O pagamento só é baixado depois que o gateway confirma (webhook autenticado + consulta).</span><button class="btn btn-primary" type="submit">Gerar link de pagamento</button></div>
                </form>
            @endif
        @endif

        @forelse ($charges as $c)
            <div class="card {{ session('new_charge') === $c->id ? 'charge-new' : '' }}"><div class="card__body stack">
                <div class="spread">
                    <span><strong>{{ Format::money($c->amount_cents) }}</strong> · {{ PaymentCharge::BILLING_TYPES[$c->billing_type] }} · {{ $c->gateway->name }}
                        @if ($c->modeBadge())<span class="badge badge-warning">{{ $c->modeBadge() }}</span>@endif
                        <span class="badge {{ $badge[$c->status] ?? '' }}">{{ PaymentCharge::STATUSES[$c->status] }}</span></span>
                    <span class="small muted">criada {{ $c->created_at->timezone('America/Sao_Paulo')->format('d/m H:i') }} · vence {{ $c->due_date->format('d/m/Y') }}</span>
                </div>
                @if ($c->review_reason)<div class="alert alert-error small">{{ $c->review_reason }}</div>@endif
                @if ($c->isOpen())
                    <div class="row">
                        <input class="input input-sm" readonly value="{{ $c->publicUrl() }}" aria-label="Link de pagamento" id="link-{{ $c->id }}">
                        <button type="button" class="btn btn-sm" data-copy="#link-{{ $c->id }}">Copiar link</button>
                        @if ($r->patient && ($r->patient->whatsapp ?? $r->patient->phone))
                            <a class="btn btn-sm" target="_blank" rel="noopener" href="https://wa.me/55{{ preg_replace('/\D/', '', $r->patient->whatsapp ?? $r->patient->phone) }}?text={{ rawurlencode('Olá! Segue o link para pagamento ('.Format::money($c->amount_cents).'): '.$c->publicUrl()) }}">Enviar por WhatsApp</a>
                        @endif
                        @if ($c->payment_url)<a class="btn btn-sm btn-ghost" href="{{ $c->payment_url }}" target="_blank" rel="noopener">Fatura no gateway</a>@endif
                    </div>
                @endif
                <div class="row">
                    @if ($c->provider_charge_id && ! in_array($c->status, ['cancelled', 'failed'], true))
                        <form method="post" action="{{ route('charges.sync', $c) }}">@csrf<button class="btn btn-sm" type="submit">Consultar no gateway</button></form>
                    @endif
                    @if ($c->isOpen() && $me->hasPermission('pagamento.cobrar'))
                        <form method="post" action="{{ route('charges.cancel', $c) }}" data-confirm="Cancelar esta cobrança no gateway?">@csrf<button class="btn btn-sm btn-ghost" type="submit">Cancelar cobrança</button></form>
                    @endif
                    @if ($c->status === 'paid' && $me->hasPermission('pagamento.estornar'))
                        <form method="post" action="{{ route('charges.refund', $c) }}" data-confirm="Estornar {{ Format::money($c->paid_cents) }} ao paciente pelo gateway?">@csrf<input type="hidden" name="confirm" value="1"><button class="btn btn-sm btn-danger" type="submit">Estornar no gateway</button></form>
                    @endif
                    @if ($c->provider === 'mock' && $c->isOpen() && $me->hasPermission('pagamento.cobrar'))
                        <form method="post" action="{{ route('charges.simulate', $c) }}" class="row">@csrf
                            <label class="sr-only" for="sim-{{ $c->id }}">Simulação</label>
                            <select id="sim-{{ $c->id }}" name="outcome" class="input input-sm w-auto"><option value="paid">MOCK: paciente pagou</option><option value="paid_wrong">MOCK: pagou valor diferente</option><option value="overdue">MOCK: venceu</option></select>
                            <button class="btn btn-sm" type="submit">Simular</button></form>
                    @endif
                    @if ($c->provider === 'mock' && $c->status === 'paid' && $me->hasPermission('pagamento.cobrar'))
                        <form method="post" action="{{ route('charges.simulate', $c) }}">@csrf<input type="hidden" name="outcome" value="refunded"><button class="btn btn-sm btn-ghost" type="submit">MOCK: estorno pelo gateway</button></form>
                    @endif
                </div>
            </div></div>
        @empty
            <p class="small muted">Nenhuma cobrança online gerada.</p>
        @endforelse
    </div>
</section>
