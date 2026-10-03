@extends('layouts.app', ['title' => 'WhatsApp e notificações'])

@php
    use App\Modules\Messaging\Models\Message;
    use App\Modules\Messaging\Models\MessagingChannel;
    $m = fn ($k, $d = true) => (bool) $company->setting('messaging.'.$k, $d);
@endphp

@section('content')
<div class="page-head">
    <div><h1>WhatsApp e mensagens automáticas</h1><p>Confirmação de agendamento, lembretes, cancelamento, remarcação e falta — pelo WhatsApp (API oficial da Meta ou, por escolha da clínica, não oficial) ou e-mail, com consentimento do paciente (LGPD).</p></div>
</div>

<div class="grid grid-4 mb-2">
    @foreach (['sent' => 'Enviadas', 'delivered' => 'Entregues', 'read' => 'Lidas', 'failed' => 'Falharam'] as $k => $l)
        <div class="card kpi"><div class="kpi__label">{{ $l }} (30 dias)</div><div class="kpi__value">{{ (int) ($stats[$k] ?? 0) }}</div><div class="kpi__hint">&nbsp;</div></div>
    @endforeach
</div>

<div class="grid grid-2">
    <section class="card">
        <div class="card__head"><h2>Número de WhatsApp</h2>
            @if ($channel)<span class="badge {{ $channel->isUnofficial() ? 'badge-danger' : ($channel->isMock() ? 'badge-warning' : 'badge-success') }}">{{ $channel->modeBadge() ?? 'OFICIAL · PRODUÇÃO' }}</span>@endif</div>
        <form method="post" action="{{ route('messaging.channel.save') }}" class="card__body form-grid">
            @csrf @method('put')
            <div class="field col-12"><label for="ch-p">Como a clínica conecta o WhatsApp</label>
                <select id="ch-p" name="provider" class="input" data-toggle-group="prov">@foreach (MessagingChannel::PROVIDERS as $k => $l)<option value="{{ $k }}" @selected(old('provider', $channel?->provider ?? 'mock') === $k)>{{ $l }}</option>@endforeach</select>
                <div class="help">A escolha é da clínica. Recomendado: API oficial (Meta).</div></div>
            <x-field name="name" label="Nome" col="col-6" :value="$channel?->name ?? 'WhatsApp da clínica'" required />
            <x-field name="display_phone" label="Número exibido" col="col-6" :value="$channel?->display_phone" mask="phone" />

            <div class="col-12 form-grid" data-toggle-show="prov:meta">
                <h3 class="col-12 mb-0">API oficial (Meta)</h3>
                <div class="field col-6"><label for="ch-m">Modo</label>
                    <select id="ch-m" name="mode" class="input">@foreach (MessagingChannel::MODES as $k => $l)<option value="{{ $k }}" @selected(old('mode', $channel?->mode ?? 'test') === $k)>{{ $l }}</option>@endforeach</select></div>
                <x-field name="api_version" label="Versão da Graph API" col="col-6" :value="$channel?->api_version ?? 'v21.0'" required />
                <x-field name="phone_number_id" label="Phone Number ID (Meta)" col="col-6" :value="$channel?->phone_number_id" inputmode="numeric" />
                <x-field name="waba_id" label="WhatsApp Business Account ID" col="col-6" :value="$channel?->waba_id" inputmode="numeric" />
                <x-field name="access_token" label="Token de acesso (System User)" type="password" col="col-6" autocomplete="off" :help="$channel?->credential('access_token') ? 'Configurado — deixe vazio para manter.' : 'Gere um token permanente de System User no Business Manager.'" />
                <x-field name="app_secret" label="App Secret (assinatura do webhook)" type="password" col="col-6" autocomplete="off" :help="$channel?->credential('app_secret') ? 'Configurado — deixe vazio para manter.' : null" />
                <h3 class="col-12 mb-0">Modelos aprovados na Meta</h3>
                <p class="help col-12">Crie na Meta (categoria Utilidade, pt_BR) modelos com o texto abaixo e as variáveis na mesma ordem. Lembrete: inclua 3 botões de resposta rápida (Confirmar, Cancelar, Remarcar).</p>
                @foreach (config('messaging.purposes') as $key => $p)
                    <div class="field col-6"><label for="tp-{{ $key }}">{{ $p['label'] }}</label>
                        <input id="tp-{{ $key }}" name="templates[{{ $key }}][name]" class="input mono" value="{{ old("templates.$key.name", data_get($channel?->templates, "$key.name") ?: $p['template']) }}">
                        <input type="hidden" name="templates[{{ $key }}][language]" value="{{ data_get($channel?->templates, "$key.language") ?: 'pt_BR' }}">
                        <div class="help">{{ $p['text'] }}</div></div>
                @endforeach
            </div>

            <div class="col-12 form-grid" data-toggle-show="prov:zapi">
                <h3 class="col-12 mb-0">Z-API (não oficial)</h3>
                <p class="help col-12">Painel da Z-API → sua instância. Serviço em nuvem: funciona na hospedagem compartilhada.</p>
                <x-field name="zapi_instance_id" label="ID da instância" col="col-6" :value="$channel?->credential('zapi_instance_id')" autocomplete="off" />
                <x-field name="zapi_token" label="Token da instância" type="password" col="col-6" autocomplete="off" :help="$channel?->credential('zapi_token') ? 'Configurado — deixe vazio para manter.' : null" />
                <x-field name="zapi_client_token" label="Client-Token (token de segurança da conta)" type="password" col="col-12" autocomplete="off" :help="$channel?->credential('zapi_client_token') ? 'Configurado — deixe vazio para manter.' : 'Recomendado: ative o token de segurança na Z-API.'" />
            </div>

            <div class="col-12 form-grid" data-toggle-show="prov:evolution">
                <h3 class="col-12 mb-0">Evolution API (não oficial)</h3>
                <p class="help col-12">Precisa de um servidor próprio (VPS/Docker) com a Evolution API v2 — não roda na hospedagem compartilhada.</p>
                <x-field name="evolution_url" label="URL do servidor (https://…)" col="col-6" :value="$channel?->credential('evolution_url')" placeholder="https://evolution.suaclinica.com.br" />
                <x-field name="evolution_instance" label="Nome da instância" col="col-6" :value="$channel?->credential('evolution_instance')" />
                <x-field name="evolution_api_key" label="API key" type="password" col="col-12" autocomplete="off" :help="$channel?->credential('evolution_api_key') ? 'Configurada — deixe vazio para manter.' : null" />
            </div>

            <div class="col-12 form-grid" data-toggle-show="prov:zapi|evolution">
                <div class="alert alert-error col-12"><strong>Atenção — WhatsApp NÃO OFICIAL.</strong> Conecta um WhatsApp comum por QR Code (como o WhatsApp Web).
                    Isso <strong>viola os termos do WhatsApp</strong>: o número pode ser <strong>bloqueado sem aviso</strong>, levando junto lembretes e atendimento por IA.
                    Os dados dos pacientes passam pelo serviço contratado — exija contrato de tratamento de dados (LGPD). Cai quando o celular fica sem internet.
                    Sem modelos da Meta: as mensagens vão como texto e os lembretes pedem resposta 1/2/3.</div>
                <label class="check col-12"><input type="checkbox" name="accept_risk" value="1" @checked($channel?->isUnofficial() && $channel->risk_accepted_at)><span><strong>A clínica está ciente dos riscos e escolhe usar o WhatsApp não oficial.</strong>
                    @if ($channel?->risk_accepted_at)<span class="muted">(aceito em {{ $channel->risk_accepted_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }})</span>@endif</span></label>
            </div>

            <label class="check col-12"><input type="checkbox" name="is_active" value="1" @checked($channel?->is_active ?? true)><span>Canal ativo</span></label>
            <div class="col-12 form-actions"><button class="btn btn-primary" type="submit">Salvar WhatsApp</button></div>
        </form>
        @if ($channel)
            <div class="card__body stack small">
                @if ($channel->isUnofficial())
                    <div><strong>URL do webhook</strong> — cadastre no painel {{ $channel->provider === 'zapi' ? 'da Z-API ("Ao receber" e "Status da mensagem")' : 'da Evolution API (eventos MESSAGES_UPSERT e MESSAGES_UPDATE)' }}:
                        <span class="mono" id="wh">{{ $channel->webhookUrl() }}</span> <button class="btn btn-sm" type="button" data-copy="#wh">Copiar</button></div>
                    <div class="muted">A URL contém um token secreto: não compartilhe. Gerar novo token invalida a URL anterior.</div>
                    <form method="post" action="{{ route('messaging.channel.check') }}">@csrf<button class="btn btn-sm" type="submit">Verificar conexão do WhatsApp</button></form>
                @else
                    <div><strong>URL do webhook</strong> (cadastre no app da Meta — campos "messages"): <span class="mono">{{ $channel->webhookUrl() }}</span></div>
                    <div><strong>Token de verificação</strong>: <span class="mono" id="vt">{{ $channel->verify_token }}</span> <button class="btn btn-sm" type="button" data-copy="#vt">Copiar</button></div>
                @endif
                <form method="post" action="{{ route('messaging.channel.rotate') }}" data-confirm="Gerar novo token? A URL/token atual deixa de funcionar.">@csrf<button class="btn btn-sm btn-ghost" type="submit">Gerar novo token</button></form>
                @if ($channel->last_webhook_at)<div class="muted">Último aviso recebido: {{ $channel->last_webhook_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}</div>@endif
            </div>
        @endif
    </section>

    <section class="card">
        <div class="card__head"><h2>Mensagens automáticas</h2></div>
        <form method="post" action="{{ route('messaging.automation.save') }}" class="card__body form-grid">
            @csrf @method('put')
            <label class="check col-12"><input type="checkbox" name="reminders_enabled" value="1" @checked($m('reminders_enabled'))><span><strong>Lembretes de consulta</strong> (com botões confirmar/cancelar/remarcar)</span></label>
            <x-field name="reminder_hours" label="Enviar lembrete quantas horas antes" col="col-6" :value="implode(', ', $reminderHours)" help="Ex.: 24, 2 — ou horários personalizados (até 168 h)." />
            <x-field name="cancel_min_hours" label="Cancelar pelo WhatsApp até (horas antes)" type="number" min="0" max="168" col="col-6" :value="(int) $company->setting('messaging.cancel_min_hours', 2)" required />
            <label class="check col-6"><input type="checkbox" name="on_booking" value="1" @checked($m('on_booking'))><span>Aviso de agendamento</span></label>
            <label class="check col-6"><input type="checkbox" name="on_reschedule" value="1" @checked($m('on_reschedule'))><span>Aviso de remarcação</span></label>
            <label class="check col-6"><input type="checkbox" name="on_cancel" value="1" @checked($m('on_cancel'))><span>Aviso de cancelamento</span></label>
            <label class="check col-6"><input type="checkbox" name="on_no_show" value="1" @checked($m('on_no_show'))><span>Mensagem de falta (oferece remarcar)</span></label>
            <h3 class="col-12 mb-0">Canais</h3>
            <label class="check col-6"><input type="checkbox" name="whatsapp_enabled" value="1" @checked($m('whatsapp_enabled'))><span>WhatsApp</span></label>
            <label class="check col-6"><input type="checkbox" name="email_enabled" value="1" @checked($m('email_enabled'))><span>E-mail (quando não houver WhatsApp)</span></label>
            <label class="check col-12"><input type="checkbox" name="require_consent" value="1" @checked($m('require_consent'))><span><strong>Exigir consentimento do paciente</strong> (recomendado — LGPD). Registre na ficha do paciente → Consentimentos.</span></label>
            <p class="help col-12">SMS: não integrado nesta versão.</p>
            <div class="col-12 form-actions"><button class="btn btn-primary" type="submit">Salvar automações</button></div>
        </form>
    </section>
</div>

<section class="card mt-2">
    <div class="card__head"><h2>Últimas mensagens enviadas</h2></div>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Quando</th><th>Paciente</th><th>Tipo</th><th>Canal</th><th>Situação</th></tr></thead>
        <tbody>
        @forelse ($recent as $msg)
            <tr><td class="nowrap small">{{ $msg->created_at->timezone('America/Sao_Paulo')->format('d/m H:i') }}</td><td>{{ $msg->patient?->displayName() ?? $msg->recipient }}</td>
                <td>{{ $msg->purposeLabel() }}</td><td>{{ $msg->channel === 'email' ? 'E-mail' : 'WhatsApp' }}</td>
                <td><span class="badge {{ ['failed' => 'badge-danger', 'skipped' => '', 'read' => 'badge-success', 'delivered' => 'badge-success'][$msg->status] ?? 'badge-info' }}">{{ $msg->statusLabel() }}</span>
                    @if ($msg->error)<div class="small muted">{{ $msg->error }}</div>@endif</td></tr>
        @empty
            <tr><td colspan="5" class="empty">Nenhuma mensagem ainda.</td></tr>
        @endforelse
        </tbody>
    </table></div>
</section>
@endsection
