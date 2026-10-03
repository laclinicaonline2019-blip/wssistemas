@extends('layouts.app', ['title' => 'Atendimento IA'])

@php
    use App\Modules\Ai\Models\AiConfig;
    $s = fn ($k, $d = true) => (bool) $config->setting($k, $d);
    $hasKey = (bool) $config->api_key;
@endphp

@section('content')
<div class="page-head">
    <div><h1>Recepcionista virtual (IA)</h1><p>Atende pacientes no WhatsApp: especialidades, médicos, horários livres, agendamento em duas etapas, cancelamento e dúvidas da clínica. Nunca dá diagnóstico nem orientação de remédio — passa para a equipe.</p></div>
    <div class="row">
        @if ($config->is_active)<span class="badge {{ $config->provider === 'mock' ? 'badge-warning' : 'badge-success' }}">{{ $config->provider === 'mock' ? 'MOCK — sem IA real' : 'ATIVA' }}</span>
        @else<span class="badge">DESLIGADA</span>@endif
    </div>
</div>

@if (! $channel)
    <div class="alert alert-warning">Configure primeiro o WhatsApp em <a href="{{ route('messaging.settings') }}">WhatsApp e mensagens</a> — a IA responde as mensagens que chegam por lá.</div>
@endif

<div class="grid grid-4 mb-2">
    <div class="card kpi"><div class="kpi__label">Chamadas à IA (30 dias)</div><div class="kpi__value">{{ (int) $usage?->calls }}</div><div class="kpi__hint">{{ (int) $usage?->errors }} com erro</div></div>
    <div class="card kpi"><div class="kpi__label">Tokens (entrada / saída)</div><div class="kpi__value">{{ number_format((int) $usage?->input_tokens, 0, ',', '.') }}</div><div class="kpi__hint">{{ number_format((int) $usage?->output_tokens, 0, ',', '.') }} saída · {{ number_format((int) $usage?->cache_read_tokens, 0, ',', '.') }} em cache</div></div>
    <div class="card kpi"><div class="kpi__label">Conversas (30 dias)</div><div class="kpi__value">{{ (int) (($sessions['active'] ?? 0) + ($sessions['handoff'] ?? 0)) }}</div><div class="kpi__hint">{{ (int) ($sessions['handoff'] ?? 0) }} passadas para a equipe</div></div>
    <div class="card kpi"><div class="kpi__label">Consultas marcadas pela IA</div><div class="kpi__value">{{ $booked }}</div><div class="kpi__hint">últimos 30 dias</div></div>
</div>

<div class="grid grid-2">
    <section class="card">
        <div class="card__head"><h2>Configuração</h2></div>
        <form method="post" action="{{ route('ai.save') }}" class="card__body form-grid">
            @csrf @method('put')
            <label class="check col-12"><input type="checkbox" name="is_active" value="1" @checked($config->is_active)><span><strong>Ativar recepcionista virtual</strong></span></label>
            <div class="field col-6"><label for="ai-p">Provedor de IA</label>
                <select id="ai-p" name="provider" class="input">@foreach (AiConfig::PROVIDERS as $k => $l)<option value="{{ $k }}" @selected(old('provider', $config->provider) === $k)>{{ $l }}</option>@endforeach</select>
                <div class="help">Claude: modelo padrão {{ AiConfig::DEFAULT_MODELS['claude'] }}. ChatGPT: informe o modelo contratado na sua conta OpenAI.</div></div>
            <x-field name="model" label="Modelo (opcional no Claude)" col="col-6" :value="$config->model" placeholder="vazio = padrão" />
            <x-field name="api_key" label="Chave da API da clínica" type="password" col="col-12" autocomplete="off"
                :help="$hasKey ? 'Chave própria configurada (criptografada, não é exibida) — deixe vazio para manter.' : (($platformKeys[$config->provider] ?? false) ? 'Vazio = usa a chave da plataforma (contrato da aivexaclinica).' : 'Sem chave da plataforma para este provedor: informe a chave da sua conta.')" />
            @if ($hasKey)<label class="check col-12"><input type="checkbox" name="remove_key" value="1"><span>Remover a chave própria (voltar à chave da plataforma)</span></label>@endif
            <x-field name="assistant_name" label="Nome da assistente" col="col-6" :value="$config->assistant_name" required />
            <div class="field col-6"><label for="ai-e">Esforço de raciocínio</label>
                <select id="ai-e" name="effort" class="input">@foreach (['low' => 'Baixo (mais rápido — recomendado)', 'medium' => 'Médio', 'high' => 'Alto (mais lento e caro)'] as $k => $l)<option value="{{ $k }}" @selected($config->setting('effort', 'low') === $k)>{{ $l }}</option>@endforeach</select>
                <div class="help">Usado no Claude. No ChatGPT, vale o padrão do modelo.</div></div>
            <div class="field col-12"><label for="ai-i">Informações da clínica para a IA</label>
                <textarea id="ai-i" name="instructions" class="input" rows="7" maxlength="8000" placeholder="Horário de funcionamento, convênios aceitos, estacionamento, preparo de exames, política de atraso, formas de pagamento…">{{ old('instructions', $config->instructions) }}</textarea>
                <div class="help">A IA só responde dúvidas administrativas com base nestas informações e nos cadastros (médicos, agenda, valores). Não coloque dados de pacientes aqui.</div></div>
            <h3 class="col-12 mb-0">O que a IA pode fazer</h3>
            <label class="check col-6"><input type="checkbox" name="whatsapp_enabled" value="1" @checked($s('whatsapp_enabled'))><span>Responder no WhatsApp</span></label>
            <label class="check col-6"><input type="checkbox" name="allow_booking" value="1" @checked($s('allow_booking'))><span>Agendar (com confirmação do paciente)</span></label>
            <label class="check col-6"><input type="checkbox" name="allow_cancel" value="1" @checked($s('allow_cancel'))><span>Cancelar (respeita o prazo do WhatsApp)</span></label>
            <label class="check col-6"><input type="checkbox" name="prepayment" value="1" @checked($s('prepayment', false))><span>Enviar link de pagamento (particular)</span></label>
            <h3 class="col-12 mb-0">Áudio, fotos e documentos (Fase 13)</h3>
            <label class="check col-6"><input type="checkbox" name="media_enabled" value="1" @checked($s('media_enabled'))><span>Ler fotos e PDFs (receitas, pedidos de exame, comprovantes)</span></label>
            <label class="check col-6"><input type="checkbox" name="audio_enabled" value="1" @checked($s('audio_enabled'))><span>Transcrever áudios do WhatsApp</span></label>
            <p class="help col-12">A leitura usa o provedor acima (Claude ou ChatGPT) e fica sempre como <strong>não verificada</strong> até a conferência em "Documentos recebidos". Áudio: transcrição pela OpenAI (o Claude não recebe áudio).</p>
            <x-field name="transcription_model" label="Modelo de transcrição (OpenAI)" col="col-6" :value="$config->setting('transcription_model', 'whisper-1')" />
            <x-field name="transcription_api_key" label="Chave da OpenAI para áudio" type="password" col="col-6" autocomplete="off"
                :help="$config->transcription_api_key ? 'Configurada — deixe vazio para manter.' : ($config->provider === 'openai' ? 'Vazio = usa a chave do ChatGPT acima.' : (config('services.openai.key') ? 'Vazio = chave da plataforma.' : 'Necessária para transcrever áudio.'))" />
            @if ($config->transcription_api_key)<label class="check col-12"><input type="checkbox" name="remove_transcription_key" value="1"><span>Remover a chave de áudio</span></label>@endif
            <h3 class="col-12 mb-0">Limites</h3>
            <x-field name="max_replies_per_hour" label="Máx. respostas por conversa/hora" type="number" min="5" max="200" col="col-6" :value="(int) $config->setting('max_replies_per_hour', 30)" required />
            <x-field name="max_daily_replies" label="Máx. respostas da clínica/dia" type="number" min="10" max="20000" col="col-6" :value="(int) $config->setting('max_daily_replies', 500)" required />
            <div class="col-12 form-actions"><button class="btn btn-primary" type="submit">Salvar</button></div>
        </form>
        @if ($config->exists)
            <form method="post" action="{{ route('ai.test') }}" class="card__body">@csrf
                <button class="btn" type="submit">Testar conexão com a IA</button>
                <span class="help">Envia uma mensagem curta de teste (sem dados de pacientes) e mostra a resposta.</span></form>
        @endif
    </section>

    <section class="card">
        <div class="card__head"><h2>Regras de segurança (fixas)</h2></div>
        <div class="card__body stack small">
            <p>Estas regras são aplicadas pelo sistema e não podem ser desligadas pelas instruções:</p>
            <ul class="list">
                <li><strong>Sem diagnóstico, prescrição ou interpretação de exames.</strong> Dúvida clínica → oferece consulta ou a equipe.</li>
                <li><strong>Emergência</strong> (dor no peito, falta de ar, desmaio, sangramento, convulsão, ideação suicida): orientação SAMU 192 / CVV 188 e equipe avisada — sem passar pela IA.</li>
                <li><strong>Nada inventado:</strong> médicos, horários e valores só vêm da agenda e dos cadastros.</li>
                <li><strong>Agendamento em duas etapas:</strong> proposta lida para o paciente e confirmação numa mensagem nova. Sem encaixe, sem dupla marcação.</li>
                <li><strong>Identificação:</strong> consultas só do paciente identificado (telefone único ou CPF + nascimento); 3 erros → equipe.</li>
                <li><strong>Humano a qualquer momento:</strong> "ATENDENTE" passa a conversa para a equipe; responder pela tela de conversas pausa a IA.</li>
                <li><strong>Transparência:</strong> a primeira resposta sempre informa que é uma assistente virtual.</li>
                <li><strong>Registro:</strong> cada chamada (tokens, tempo) e cada ação da IA ficam registradas; CPF mascarado.</li>
                <li><strong>Fotos, PDFs e áudios:</strong> a IA só transcreve (sem interpretar exames ou receitas); tudo fica "não verificado" até a conferência; comprovante nunca dá baixa em pagamento.</li>
                <li><strong>LGPD:</strong> paciente que revogar o consentimento "atendimento por assistente virtual" é atendido pela equipe.</li>
            </ul>
            <p class="muted">Ao provedor de IA vão só a conversa, os resultados das ações e — se ligado — as fotos/PDFs/áudios enviados pelo paciente (sem prontuário). Revise o contrato/DPA do provedor escolhido.</p>
        </div>
    </section>
</div>

<section class="card mt-2">
    <div class="card__head"><h2>Últimas ações da IA</h2></div>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Quando</th><th>Ação</th><th>Situação</th><th></th></tr></thead>
        <tbody>
        @forelse ($recentCalls as $c)
            <tr><td class="nowrap small">{{ $c->created_at->timezone('America/Sao_Paulo')->format('d/m H:i') }}</td><td class="mono">{{ $c->tool }}</td>
                <td>@if ($c->is_error)<span class="badge badge-danger">erro</span> <span class="small">{{ $c->result['error'] ?? '' }}</span>@else<span class="badge badge-success">ok</span>@endif</td>
                <td class="actions">@if ($threadOf[$c->session_id] ?? null)<a class="btn btn-sm" href="{{ route('messaging.threads.show', $threadOf[$c->session_id]) }}">Conversa</a>@endif</td></tr>
        @empty
            <tr><td colspan="4" class="empty">Nenhuma ação ainda.</td></tr>
        @endforelse
        </tbody>
    </table></div>
</section>
@endsection
