@extends('layouts.app', ['title' => 'Pagamentos online'])

@php use App\Modules\Payments\Models\PaymentGateway; $tz = 'America/Sao_Paulo'; @endphp

@section('content')
<div class="page-head"><div><h1>Pagamentos online</h1><p>Gateways da clínica. Credenciais ficam criptografadas e nunca são exibidas de volta.</p></div>
    <a class="btn" href="{{ route('company.edit') }}">Configurações</a></div>

<div class="alert alert-info small">
    <strong>Como funciona:</strong> o sistema gera a cobrança no gateway e envia o link ao paciente. A baixa só acontece quando o gateway
    avisa pelo <strong>webhook</strong> (com token) <em>e</em> a consulta à API confirma status e valor. Uma rotina a cada 10 minutos consulta as cobranças em aberto,
    caso algum aviso se perca. Use <strong>SANDBOX</strong> para homologar antes de ativar <strong>Produção</strong>.
</div>

@foreach ($gateways as $g)
    <section class="card mb-2">
        <div class="card__head"><h2>{{ $g->name }}
            @if ($g->modeBadge())<span class="badge badge-warning">{{ $g->modeBadge() }}</span>@else<span class="badge badge-success">PRODUÇÃO</span>@endif
            @if ($g->is_default)<span class="badge badge-primary">padrão</span>@endif
            @unless ($g->is_active)<span class="badge">inativo</span>@endunless</h2>
            <div class="row">
                <form method="post" action="{{ route('gateways.test', $g) }}">@csrf<button class="btn btn-sm" type="submit">Testar conexão</button></form>
            </div>
        </div>
        <div class="card__body stack">
            @if ($g->provider !== 'mock')
                @php $url = $g->webhookUrl().(in_array($g->provider, ['cielo', 'cielo_api'], true) ? '?token='.$g->webhook_token : ''); @endphp
                <div>
                    <span class="label">URL de webhook / notificação (cadastre no painel do {{ $g->provider === 'asaas' ? 'ASAAS' : 'Cielo' }})</span>
                    <div class="row"><input class="input" readonly value="{{ $url }}" id="wh-{{ $g->id }}" aria-label="URL de webhook"><button type="button" class="btn btn-sm" data-copy="#wh-{{ $g->id }}">Copiar</button></div>
                    @if ($g->provider === 'asaas')
                        <div class="row mt-1"><span class="small">Token de autenticação (campo "Token de autenticação" do webhook ASAAS):</span>
                            <input class="input input-sm w-auto mono" readonly value="{{ $g->webhook_token }}" id="tk-{{ $g->id }}" aria-label="Token do webhook"><button type="button" class="btn btn-sm" data-copy="#tk-{{ $g->id }}">Copiar</button></div>
                        <p class="help">No ASAAS: Integrações → Webhooks → eventos de <strong>Cobranças</strong> (PAYMENT_*), versão da API v3.</p>
                    @else
                        <p class="help">{{ $g->provider === 'cielo_api' ? 'Na Cielo (API E-commerce): cadastre esta URL como "URL de Notificação" junto ao suporte/painel Cielo. O split exige contrato de Split com a Cielo e cada médico cadastrado como subordinado (ID em Financeiro → Repasses).' : 'Na Cielo (Link de Pagamento): Configurações → URL de Notificação (POST) e URL de Mudança de Status.' }} O token vai na própria URL — trate-a como senha.</p>
                    @endif
                    <form method="post" action="{{ route('gateways.rotate', $g) }}" data-confirm="Gerar novo token? O atual deixa de funcionar e precisará ser atualizado no gateway." class="mt-1">@csrf<button class="btn btn-sm btn-ghost" type="submit">Gerar novo token</button></form>
                </div>
            @endif
            <details><summary class="btn btn-sm">Editar</summary>
                <form method="post" action="{{ route('gateways.update', $g) }}" class="form-grid mt-1">
                    @csrf @method('put')
                    @include('payments._gateway-fields', ['g' => $g, 'provider' => $g->provider])
                    <div class="col-12 form-actions"><button class="btn btn-primary" type="submit">Salvar</button></div>
                </form>
            </details>
        </div>
    </section>
@endforeach

<section class="card mb-2">
    <div class="card__head"><h2>Adicionar gateway</h2></div>
    <form method="post" action="{{ route('gateways.store') }}" class="card__body form-grid">
        @csrf
        <div class="field col-4"><label for="gw-p">Gateway</label>
            <select id="gw-p" name="provider" class="input">@foreach (PaymentGateway::PROVIDERS as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
        @include('payments._gateway-fields', ['g' => null, 'provider' => null])
        <div class="col-12 form-actions"><button class="btn btn-primary" type="submit">Adicionar</button></div>
    </form>
</section>

<section class="card">
    <div class="card__head"><h2>Últimos avisos recebidos dos gateways</h2></div>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Recebido</th><th>Gateway</th><th>Evento</th><th>Cobrança</th><th>Situação</th></tr></thead>
        <tbody>
        @forelse ($events as $e)
            <tr><td class="small nowrap">{{ $e->received_at->timezone($tz)->format('d/m H:i:s') }}</td><td class="small">{{ strtoupper($e->provider) }}</td>
                <td class="small mono">{{ $e->event_type }}</td><td class="small mono">{{ $e->provider_charge_id }}</td>
                <td><span class="badge {{ ['processed' => 'badge-success', 'failed' => 'badge-danger', 'ignored' => ''][$e->status] ?? 'badge-info' }}">{{ ['processed' => 'processado', 'failed' => 'falhou (nova tentativa automática)', 'ignored' => 'ignorado', 'received' => 'recebido'][$e->status] }}</span>
                    @if ($e->error)<div class="small muted">{{ $e->error }}</div>@endif</td></tr>
        @empty
            <tr><td colspan="5" class="empty">Nenhum aviso recebido ainda.</td></tr>
        @endforelse
        </tbody>
    </table></div>
</section>
@endsection
