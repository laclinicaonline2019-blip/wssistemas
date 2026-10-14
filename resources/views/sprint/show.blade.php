@extends('layouts.app', ['title' => $sale->code().' · Melhor Sprint'])

@php
    use App\Core\Support\Format;
    use App\Modules\Payments\Providers\CieloApiProvider;
    use App\Modules\Sprint\Models\SprintInvoice;
    use App\Modules\Sprint\Models\SprintPayment;
    use App\Modules\Sprint\Models\SprintSplit;
    use Illuminate\Support\Str;
    $me = auth()->user();
    $tz = 'America/Sao_Paulo';
    $paid = (int) $sale->payments->where('status', 'paid')->sum('amount_cents');
    $pending = $sale->payments->where('status', 'pending')->filter(fn ($p) => $p->charge);
    $canSell = $me->hasPermission('sprint.vender') && $sale->isOpen() && $available > 0;
    $suggest = number_format(max(0, $available) / 100, 2, ',', '.');
    $has = fn (string $provider) => isset($gateways[$provider]);
    $activeSplits = $sale->splits->where('status', '!=', 'reversed');
    $byParty = $activeSplits->groupBy('party')->map(fn ($g) => (int) $g->sum('amount_cents'));
    $gross = max(1, $byParty->sum());
    $badge = ['open' => 'badge-warning', 'partial' => 'badge-info', 'paid' => 'badge-success', 'cancelled' => '', 'refunded' => 'badge-danger'];
    $pBadge = ['pending' => 'badge-warning', 'paid' => 'badge-success', 'cancelled' => '', 'failed' => 'badge-danger', 'refunded' => 'badge-danger'];
    $activeInvoice = $sale->invoices->first(fn ($i) => $i->isActive());
@endphp

@section('content')
@include('sprint._head', ['subtitle' => 'Venda '.$sale->code().' · '.$sale->customerLabel()])

<div data-sprint-checkout data-status-url="{{ route('sprint.sales.status', $sale) }}" data-pending="{{ $pending->pluck('id')->implode(',') }}" data-confirmed="{{ session('confirmed') ? '1' : '' }}">

<div class="grid grid-4">
    <div class="card kpi"><div class="kpi__label">Total da venda</div><div class="ms-total">{{ Format::money($sale->total_cents) }}</div><div class="kpi__hint"><span class="badge {{ $badge[$sale->status] ?? '' }}">{{ $sale->statusLabel() }}</span></div></div>
    <div class="card kpi"><div class="kpi__label">Recebido</div><div class="kpi__value kpi-pos">{{ Format::money($paid) }}</div><div class="kpi__hint">{{ $sale->payments->where('status', 'paid')->count() }} pagamento(s)</div></div>
    <div class="card kpi"><div class="kpi__label">Falta receber</div><div class="kpi__value">{{ Format::money(max(0, $sale->total_cents - $paid)) }}</div><div class="kpi__hint">{{ $pending->count() ? Format::money($pending->sum('amount_cents')).' aguardando gateway' : 'disponível '.Format::money(max(0, $available)) }}</div></div>
    <div class="card kpi"><div class="kpi__label">Divisão planejada</div><div class="kpi__value small">Prof. {{ Format::money($sale->professional_cents) }}</div><div class="kpi__hint">Clínica {{ Format::money($sale->house_cents) }} · comissão {{ number_format($sale->commission_bp / 100, 2, ',', '.') }}%</div></div>
</div>

{{-- Pagamentos aguardando confirmação (PIX / cartão online) --}}
@foreach ($pending as $p)
    @php $c = $p->charge; @endphp
    <section class="card mt-2">
        <div class="card__body ms-pending">
            @if (isset($qr[$p->id]))<img src="{{ $qr[$p->id] }}" alt="QR Code para pagamento" width="200" height="200">@endif
            <div class="stack">
                <div class="spread"><strong>{{ Format::money($p->amount_cents) }} · {{ $p->label() }}</strong>
                    @if ($c->modeBadge())<span class="badge badge-warning">{{ $c->modeBadge() }}</span>@endif</div>
                @if ($c->status === 'review')
                    <div class="alert alert-error small">{{ $c->review_reason }}</div>
                @else
                    <span class="ms-waiting" role="status">Aguardando o pagamento… a confirmação aparece aqui automaticamente.</span>
                @endif
                @if ($p->method === 'pix' && $c->pix_payload)
                    <label class="label small" for="pix-{{ $p->id }}">PIX copia e cola</label>
                    <div class="row"><input id="pix-{{ $p->id }}" class="input input-sm mono" readonly value="{{ $c->pix_payload }}"><button type="button" class="btn btn-sm" data-copy="#pix-{{ $p->id }}">Copiar</button></div>
                @elseif ($c->provider === 'cielo_api')
                    <p class="small">Cartão digitado com segurança (os dados vão direto para a Cielo). Abra no terminal da recepção ou peça ao cliente para ler o QR Code.</p>
                    <div class="row"><a class="btn btn-sm btn-primary" href="{{ $c->publicUrl() }}" target="_blank" rel="noopener">Abrir página do cartão</a>
                        <input id="lnk-{{ $p->id }}" class="input input-sm" readonly value="{{ $c->publicUrl() }}"><button type="button" class="btn btn-sm" data-copy="#lnk-{{ $p->id }}">Copiar link</button></div>
                @elseif ($c->payment_url)
                    <p class="small">O cliente paga na página segura do ASAAS (lendo o QR Code ou pelo link).</p>
                    <div class="row"><a class="btn btn-sm btn-primary" href="{{ $c->payment_url }}" target="_blank" rel="noopener">Abrir fatura</a>
                        <input id="lnk-{{ $p->id }}" class="input input-sm" readonly value="{{ $c->payment_url }}"><button type="button" class="btn btn-sm" data-copy="#lnk-{{ $p->id }}">Copiar link</button></div>
                @endif
                <div class="row">
                    <form method="post" action="{{ route('sprint.payments.sync', $p) }}">@csrf<button class="btn btn-sm" type="submit">Verificar agora</button></form>
                    @if ($me->hasPermission('sprint.vender'))
                        <form method="post" action="{{ route('sprint.payments.cancel', $p) }}" data-confirm="Cancelar esta cobrança? O valor volta a ficar disponível para outra forma de pagamento.">@csrf<button class="btn btn-sm btn-ghost" type="submit">Cancelar cobrança</button></form>
                    @endif
                    @if ($c->provider === 'mock' && $me->hasPermission('sprint.vender'))
                        <form method="post" action="{{ route('sprint.payments.simulate', $p) }}">@csrf<button class="btn btn-sm" type="submit">MOCK: cliente pagou</button></form>
                    @endif
                </div>
            </div>
        </div>
    </section>
@endforeach

<div class="grid grid-2 mt-2">
    @if ($canSell)
    <section class="card">
        <div class="card__head"><h2>Receber pagamento</h2><span class="small muted">disponível {{ Format::money($available) }}</span></div>
        <div class="card__body stack">
            <div class="ms-methods" role="group" aria-label="Forma de pagamento">
                <button type="button" class="ms-method" data-method-tab="pix" aria-pressed="false"><svg><use href="#i-activity"/></svg>PIX</button>
                <button type="button" class="ms-method" data-method-tab="credit_card" aria-pressed="false"><svg><use href="#i-cash"/></svg>Crédito</button>
                <button type="button" class="ms-method" data-method-tab="debit_card" aria-pressed="false"><svg><use href="#i-cash"/></svg>Débito</button>
                <button type="button" class="ms-method" data-method-tab="cash" aria-pressed="false"><svg><use href="#i-cash"/></svg>Dinheiro</button>
            </div>

            {{-- PIX --}}
            <div class="ms-panel" data-method-panel="pix">
                @if ($has('asaas') || $has('mock'))
                    <form method="post" action="{{ route('sprint.sales.pay', $sale) }}" class="form-grid">
                        @csrf <input type="hidden" name="idempotency_key" value="{{ (string) Str::uuid() }}"><input type="hidden" name="method" value="pix">
                        <input type="hidden" name="channel" value="{{ $has('asaas') ? 'asaas' : 'mock' }}">
                        <x-field name="amount" label="Valor (R$)" col="col-6" mask="money" inputmode="numeric" :value="$suggest" required />
                        <p class="help col-12">Gera o QR Code PIX {{ $has('asaas') ? 'no ASAAS' : '(MOCK)' }} com a divisão já separada para quem tem carteira ASAAS. A tela confirma sozinha quando o cliente pagar.</p>
                        <div class="col-12 form-actions"><button class="btn btn-sprint" type="submit">Gerar QR Code PIX</button></div>
                    </form>
                @else
                    <p class="small muted">Configure uma conta ASAAS em Administração → Pagamentos online para receber PIX com QR Code.</p>
                @endif
            </div>

            {{-- Crédito --}}
            <div class="ms-panel" data-method-panel="credit_card">
                <form method="post" action="{{ route('sprint.sales.pay', $sale) }}" class="form-grid" data-card-form>
                    @csrf <input type="hidden" name="idempotency_key" value="{{ (string) Str::uuid() }}"><input type="hidden" name="method" value="credit_card">
                    <fieldset class="col-12 stack"><legend class="small strong">Onde o cartão será passado</legend>
                        <label class="check"><input type="radio" name="channel" value="cielo_terminal" checked><span>Maquininha Cielo</span></label>
                        <label class="check"><input type="radio" name="channel" value="terminal"><span>Outra maquininha</span></label>
                        @if ($has('cielo_api'))<label class="check"><input type="radio" name="channel" value="cielo_api"><span>Cielo online — cartão digitado (split automático)</span></label>@endif
                        @if ($has('asaas'))<label class="check"><input type="radio" name="channel" value="asaas"><span>Link de pagamento ASAAS (cliente paga no celular)</span></label>@endif
                        @if ($has('mock'))<label class="check"><input type="radio" name="channel" value="mock"><span>MOCK (simulação)</span></label>@endif
                    </fieldset>
                    <x-field name="amount" label="Valor (R$)" col="col-4" mask="money" inputmode="numeric" :value="$suggest" required />
                    <x-field name="installments" label="Parcelas" type="number" min="1" max="24" col="col-2" value="1" />
                    <div class="field col-3" data-terminal-only><label for="cc-brand">Bandeira</label>
                        <select id="cc-brand" name="card_brand" class="input"><option value="">—</option>@foreach (CieloApiProvider::BRANDS as $k => $l)<option value="{{ $l }}">{{ $l }}</option>@endforeach</select></div>
                    <div class="field col-3" data-terminal-only><label for="cc-nsu">NSU / autorização</label><input id="cc-nsu" name="nsu" class="input" maxlength="60"></div>
                    <label class="check col-12" data-cielo-split><input type="hidden" name="terminal_split" value="0"><input type="checkbox" name="terminal_split" value="1">
                        <span><strong>A maquininha Cielo já fez o split</strong> — a parte dos profissionais com ID de subordinado Cielo cai direto para eles (não gera repasse interno).</span></label>
                    <div class="col-12 form-actions"><button class="btn btn-sprint" type="submit">Registrar crédito</button></div>
                </form>
            </div>

            {{-- Débito --}}
            <div class="ms-panel" data-method-panel="debit_card">
                <form method="post" action="{{ route('sprint.sales.pay', $sale) }}" class="form-grid" data-card-form>
                    @csrf <input type="hidden" name="idempotency_key" value="{{ (string) Str::uuid() }}"><input type="hidden" name="method" value="debit_card">
                    <fieldset class="col-12 stack"><legend class="small strong">Maquininha</legend>
                        <label class="check"><input type="radio" name="channel" value="cielo_terminal" checked><span>Maquininha Cielo</span></label>
                        <label class="check"><input type="radio" name="channel" value="terminal"><span>Outra maquininha</span></label>
                    </fieldset>
                    <x-field name="amount" label="Valor (R$)" col="col-4" mask="money" inputmode="numeric" :value="$suggest" required />
                    <div class="field col-4"><label for="dc-brand">Bandeira</label>
                        <select id="dc-brand" name="card_brand" class="input"><option value="">—</option>@foreach (CieloApiProvider::BRANDS as $k => $l)<option value="{{ $l }}">{{ $l }}</option>@endforeach</select></div>
                    <div class="field col-4"><label for="dc-nsu">NSU / autorização</label><input id="dc-nsu" name="nsu" class="input" maxlength="60" required></div>
                    <label class="check col-12" data-cielo-split><input type="hidden" name="terminal_split" value="0"><input type="checkbox" name="terminal_split" value="1">
                        <span><strong>A maquininha Cielo já fez o split</strong> dos profissionais com ID de subordinado Cielo.</span></label>
                    <p class="help col-12">Débito é sempre na maquininha (os gateways não oferecem débito online sem autenticação do banco).</p>
                    <div class="col-12 form-actions"><button class="btn btn-sprint" type="submit">Registrar débito</button></div>
                </form>
            </div>

            {{-- Dinheiro --}}
            <div class="ms-panel" data-method-panel="cash">
                <form method="post" action="{{ route('sprint.sales.pay', $sale) }}" class="form-grid" data-cash-form>
                    @csrf <input type="hidden" name="idempotency_key" value="{{ (string) Str::uuid() }}"><input type="hidden" name="method" value="cash"><input type="hidden" name="channel" value="cash">
                    <x-field name="amount" label="Valor a receber (R$)" col="col-4" mask="money" inputmode="numeric" :value="$suggest" required />
                    <x-field name="cash_given" label="Valor entregue (R$)" col="col-4" mask="money" inputmode="numeric" />
                    <div class="field col-4"><span class="label">Troco</span><div class="kpi__value" data-change>—</div></div>
                    @unless ($openSession)<p class="help col-12 text-danger">Dinheiro entra pelo caixa: <a href="{{ route('cash.index') }}">abra o seu caixa</a> antes de receber.</p>@endunless
                    <p class="help col-12">A parte dos profissionais recebida em dinheiro fica como <strong>repasse a pagar</strong> (fechamento em Repasses).</p>
                    <div class="col-12 form-actions"><button class="btn btn-sprint" type="submit">Receber em dinheiro</button></div>
                </form>
            </div>
        </div>
    </section>
    @endif

    <section class="card">
        <div class="card__head"><h2>Itens e divisão</h2><a class="btn btn-sm" href="{{ route('sprint.sales.receipt', $sale) }}" target="_blank" rel="noopener">Comprovante</a></div>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Item</th><th>Profissional</th><th class="t-right">Valor</th><th class="t-right">Parte prof.</th><th class="t-right">Clínica</th></tr></thead>
            <tbody>
            @foreach ($sale->items as $i)
                <tr><td class="small">{{ $i->description }}</td><td class="small">{{ $i->professional?->name ?? '—' }} @if ($i->split_type)<span class="muted">({{ $i->splitLabel() }})</span>@endif</td>
                    <td class="t-right">{{ Format::money($i->amount_cents) }}</td><td class="t-right small">{{ Format::money($i->professional_cents) }}</td><td class="t-right small">{{ Format::money($i->house_cents) }}</td></tr>
            @endforeach
            </tbody>
            <tfoot><tr><th colspan="2">Total</th><th class="t-right">{{ Format::money($sale->total_cents) }}</th><th class="t-right">{{ Format::money($sale->professional_cents) }}</th><th class="t-right">{{ Format::money($sale->house_cents) }}</th></tr></tfoot>
        </table></div>
        @if ($activeSplits->isNotEmpty())
            <div class="card__body ms-division">
                <strong class="small">Divisão do que já foi recebido</strong>
                <div class="ms-bar" aria-hidden="true">
                    @foreach (['professional' => 'p-pro', 'house' => 'p-house', 'platform' => 'p-platform', 'fee' => 'p-fee'] as $party => $cls)
                        @if (($byParty[$party] ?? 0) > 0)<span class="{{ $cls }} ms-w-{{ (int) round(($byParty[$party] / $gross) * 20) * 5 }}"></span>@endif
                    @endforeach
                </div>
                <div class="ms-legend">
                    <span><i class="p-pro"></i>Profissionais {{ Format::money($byParty['professional'] ?? 0) }}</span>
                    <span><i class="p-house"></i>Clínica {{ Format::money($byParty['house'] ?? 0) }}</span>
                    <span><i class="p-platform"></i>Comissão {{ Format::money($byParty['platform'] ?? 0) }}</span>
                    <span><i class="p-fee"></i>Tarifa {{ Format::money($byParty['fee'] ?? 0) }}</span>
                </div>
            </div>
        @endif
    </section>
</div>

<section class="card mt-2">
    <div class="card__head"><h2>Pagamentos</h2></div>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Data</th><th>Forma</th><th class="t-right">Valor</th><th>Detalhes</th><th>Situação</th></tr></thead>
        <tbody>
        @forelse ($sale->payments as $p)
            <tr><td class="small nowrap">{{ ($p->paid_at ?? $p->created_at)->timezone($tz)->format('d/m/Y H:i') }}</td><td class="small">{{ $p->label() }}</td>
                <td class="t-right">{{ Format::money($p->amount_cents) }}</td>
                <td class="small">{{ collect([$p->card_brand, $p->installments > 1 ? $p->installments.'x' : null, $p->nsu ? 'NSU '.$p->nsu : null, $p->change_cents ? 'troco '.Format::money($p->change_cents) : null, $p->terminal_split ? 'split na maquininha' : null])->filter()->implode(' · ') ?: '—' }}</td>
                <td><span class="badge {{ $pBadge[$p->status] ?? '' }}">{{ SprintPayment::STATUSES[$p->status] }}</span></td></tr>
        @empty
            <tr><td colspan="5" class="empty">Nenhum pagamento ainda.</td></tr>
        @endforelse
        </tbody>
    </table></div>
</section>

@if ($activeSplits->isNotEmpty() && ($me->hasPermission('sprint.repasse') || $me->hasPermission('sprint.vender')))
<section class="card mt-2">
    <div class="card__head"><h2>Lançamentos da divisão</h2></div>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Parte</th><th>Profissional</th><th class="t-right">Valor</th><th>Como</th><th>Situação</th></tr></thead>
        <tbody>
        @foreach ($sale->splits as $s)
            <tr class="{{ $s->status === 'reversed' ? 'muted' : '' }}"><td class="small">{{ SprintSplit::PARTIES[$s->party] }}</td><td class="small">{{ $s->professional?->name ?? '—' }}</td>
                <td class="t-right {{ $s->amount_cents < 0 ? 'text-danger' : '' }}">{{ Format::money($s->amount_cents) }}</td>
                <td class="small">{{ $s->mode === 'native' ? 'separado pelo gateway ('.(['asaas' => 'ASAAS', 'cielo_api' => 'Cielo', 'cielo_terminal' => 'maquininha Cielo', 'mock' => 'MOCK'][$s->source] ?? 'gateway').')' : ($s->party === 'professional' ? 'repasse da clínica' : ($s->party === 'platform' ? 'cobrada da clínica' : 'caixa da clínica')) }}</td>
                <td><span class="badge {{ ['settled' => 'badge-success', 'pending' => 'badge-warning', 'to_invoice' => 'badge-warning', 'invoiced' => 'badge-info'][$s->status] ?? '' }}">{{ SprintSplit::STATUSES[$s->status] }}</span></td></tr>
        @endforeach
        </tbody>
    </table></div>
</section>
@endif

<div class="grid grid-2 mt-2">
    @if ($settings->nfse_enabled || $sale->invoices->isNotEmpty())
    <section class="card">
        <div class="card__head"><h2>NFS-e (parte da clínica)</h2></div>
        <div class="card__body stack">
            <p class="small">A nota é emitida sobre <strong>{{ Format::money($sale->house_cents) }}</strong> — o valor que ficou para a clínica — e não sobre o total de {{ Format::money($sale->total_cents) }}. Os profissionais emitem o documento fiscal da parte deles.</p>
            @foreach ($sale->invoices as $inv)
                <div class="spread small">
                    <span>{{ Format::money($inv->value_cents) }} · <span class="badge {{ ['authorized' => 'badge-success', 'error' => 'badge-danger', 'scheduled' => 'badge-warning'][$inv->status] ?? '' }}">{{ SprintInvoice::STATUSES[$inv->status] }}</span>
                        @if ($inv->modeBadge())<span class="badge badge-warning">{{ $inv->modeBadge() }}</span>@endif
                        @if ($inv->number) nº {{ $inv->number }}@endif @if ($inv->error)<span class="text-danger">— {{ $inv->error }}</span>@endif</span>
                    <span class="row">
                        @if ($inv->pdf_url)<a class="btn btn-sm" href="{{ $inv->pdf_url }}" target="_blank" rel="noopener">PDF</a>@endif
                        @if ($me->hasPermission('sprint.nfse') && in_array($inv->status, ['scheduled', 'cancelling'], true))
                            <form method="post" action="{{ route('sprint.nfse.sync', $inv) }}">@csrf<button class="btn btn-sm" type="submit">Atualizar</button></form>@endif
                        @if ($me->hasPermission('sprint.nfse') && in_array($inv->status, ['scheduled', 'authorized'], true))
                            <form method="post" action="{{ route('sprint.nfse.cancel', $inv) }}" data-confirm="Cancelar esta NFS-e na prefeitura?">@csrf<button class="btn btn-sm btn-ghost" type="submit">Cancelar nota</button></form>@endif
                    </span>
                </div>
            @endforeach
            @if ($me->hasPermission('sprint.nfse') && $settings->nfse_enabled && $sale->status === 'paid' && ! $activeInvoice && $sale->house_cents > 0)
                <form method="post" action="{{ route('sprint.sales.nfse', $sale) }}" data-confirm="Emitir NFS-e de {{ Format::money($sale->house_cents) }} (parte da clínica)?">@csrf<button class="btn btn-primary" type="submit">Emitir NFS-e da parte da clínica</button></form>
            @elseif ($sale->status !== 'paid' && ! $activeInvoice)
                <p class="small muted">Disponível depois que a venda for quitada.</p>
            @endif
            @if ($activeInvoice && $sale->status === 'refunded')<div class="alert alert-warning small">Venda estornada com NFS-e ativa: cancele a nota.</div>@endif
        </div>
    </section>
    @endif

    @if ($me->hasPermission('sprint.estornar') && $sale->status !== 'cancelled')
    <section class="card">
        <div class="card__head"><h2>Cancelar / estornar</h2></div>
        <div class="card__body stack">
            @if (in_array($sale->status, ['open', 'refunded'], true))
                <form method="post" action="{{ route('sprint.sales.cancel', $sale) }}" class="row" data-confirm="Cancelar a venda {{ $sale->code() }}?">
                    @csrf <label class="sr-only" for="cr">Motivo</label><input id="cr" name="reason" class="input" minlength="5" maxlength="255" required placeholder="Motivo do cancelamento">
                    <button class="btn btn-danger" type="submit">Cancelar venda</button></form>
            @endif
            @if ($sale->payments->where('status', 'paid')->isNotEmpty())
                <form method="post" action="{{ route('sprint.sales.refund', $sale) }}" class="row" data-confirm="Estornar todos os pagamentos confirmados? Dinheiro sai do seu caixa; cartão/PIX online volta pelo gateway.">
                    @csrf <label class="sr-only" for="rr">Motivo</label><input id="rr" name="reason" class="input" minlength="10" maxlength="230" required placeholder="Motivo do estorno (mín. 10 caracteres)">
                    <button class="btn btn-danger" type="submit">Estornar pagamentos</button></form>
                <p class="help">O estorno desfaz a divisão: parte ainda não repassada é cancelada; parte já repassada vira devolução no próximo fechamento.
                    Venda na maquininha: estorne também na maquininha.</p>
            @endif
        </div>
    </section>
    @endif
</div>

<div class="ms-confirm" data-confirm-overlay role="dialog" aria-modal="true" aria-labelledby="ms-confirm-title" data-title="Pagamento confirmado!" data-text="{{ session('success') }}">
    <div class="ms-confirm__box">
        <div class="ms-confirm__check"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.2 4.2L19 7"/></svg></div>
        <h2 id="ms-confirm-title" data-confirm-title>Pagamento confirmado!</h2>
        <p data-confirm-text></p>
        <button type="button" class="btn btn-sprint" data-confirm-close>OK</button>
    </div>
</div>
</div>
@endsection
