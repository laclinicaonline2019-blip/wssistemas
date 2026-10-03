<?php

namespace App\Modules\Ai\Services;

use App\Core\Support\Format;
use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Models\AiConfig;
use App\Modules\Ai\Models\AiRequest;
use App\Modules\Ai\Models\AiSession;
use App\Modules\Ai\Models\AiToolCall;
use App\Modules\Ai\Providers\ClaudeProvider;
use App\Modules\Ai\Providers\LlmProvider;
use App\Modules\Ai\Providers\MockLlmProvider;
use App\Modules\Ai\Providers\OpenAiProvider;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageThread;
use App\Modules\Messaging\Services\MessageService;
use App\Modules\Organization\Models\Branch;
use App\Modules\Patients\Models\Patient;
use App\Modules\Platform\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Recepcionista virtual no WhatsApp.
 *
 * Regras fixas (aplicadas pelo SISTEMA, antes e depois do modelo):
 * - sinais de emergência → orientação SAMU 192 e equipe avisada, sem chamar a IA;
 * - "atendente"/"humano" → conversa vai direto para a equipe;
 * - consentimento "atendimento por IA" revogado → equipe;
 * - limites por conversa (hora) e por clínica (dia);
 * - erro, recusa ou resposta vazia do modelo → mensagem padrão e equipe avisada;
 * - a primeira resposta sempre se identifica como assistente virtual.
 */
class AiReceptionist
{
    public const HISTORY_LIMIT = 20;

    private const EMERGENCY = [
        'dor no peito', 'falta de ar', 'nao consigo respirar', 'infarto', 'avc', 'derrame', 'desmai', 'convuls', 'inconsciente',
        'sangramento intenso', 'hemorragia', 'envenen', 'overdose', 'suicid', 'me matar', 'tirar minha vida', 'nao quero mais viver',
        'engasg', 'queimadura grave', 'acidente grave',
    ];

    private const SELF_HARM = ['suicid', 'me matar', 'tirar minha vida', 'nao quero mais viver'];

    private const HUMAN = ['atendente', 'humano', 'falar com alguem', 'falar com uma pessoa', 'falar com a recepcao', 'pessoa de verdade'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly MessageService $messages,
        private readonly HandoffService $handoff,
        private readonly ReceptionistTools $tools,
    ) {}

    public function config(): ?AiConfig
    {
        return AiConfig::query()->first();
    }

    /** A IA deve responder esta conversa? (configurada, ativa no WhatsApp e não está com a equipe) */
    public function accepts(MessageThread $thread): bool
    {
        $config = $this->config();
        if (! $config?->is_active || ! $config->setting('whatsapp_enabled', true)) {
            return false;
        }

        return AiSession::query()->where('thread_id', $thread->id)->value('status') !== 'handoff';
    }

    /** Responde a mensagem $messageId (chamado pelo job, depois de devolver o 200 ao WhatsApp). */
    public function respond(string $companyId, string $threadId, string $messageId): void
    {
        $this->context->runFor($companyId, function () use ($threadId, $messageId) {
            // Uma resposta por vez na conversa; várias mensagens seguidas recebem UMA resposta (a da última).
            $lock = Cache::lock('ai-thread-'.$threadId, 180);
            if (! $lock->block(90)) {
                return;
            }
            try {
                $thread = MessageThread::query()->with('patient')->find($threadId);
                $last = $thread ? Message::query()->where('thread_id', $thread->id)->where('direction', 'in')->latest('created_at')->latest('id')->first() : null;
                if (! $thread || ! $last || $last->id !== $messageId || ! $this->accepts($thread)) {
                    return;
                }
                $session = $this->handoff->session($thread);
                if ($session->stateValue('answered') === $last->id) {
                    return; // já respondida (evento repetido)
                }
                $session->putState('answered', $last->id);
                $session->save();
                $this->handle($thread, $last);
            } finally {
                $lock->release();
            }
        });
    }

    public function handle(MessageThread $thread, Message $inbound): void
    {
        $config = $this->config();
        $session = $this->handoff->session($thread);
        if ($thread->patient_id && ! $session->patient_id) {
            $session->forceFill(['patient_id' => $thread->patient_id])->save();
        }
        $text = Format::searchable($inbound->body);

        if ($this->contains($text, self::EMERGENCY)) {
            $selfHarm = $this->contains($text, self::SELF_HARM);
            $this->send($thread, $session, 'Pelo que você descreveu, isso pode ser uma *emergência*. Ligue agora para o *SAMU 192* ou procure o pronto-socorro mais próximo.'
                .($selfHarm ? ' Se precisar conversar com alguém agora, o *CVV* atende 24 h pelo telefone *188*.' : '')
                .' Sou uma assistente virtual e não posso avaliar sintomas. Já avisei a equipe da clínica.');
            $this->handoff->handoff($session, $thread, 'Possível emergência relatada pelo paciente', 'danger');

            return;
        }

        if ($this->contains($text, self::HUMAN)) {
            $this->send($thread, $session, 'Certo! Vou chamar alguém da equipe da clínica para continuar o atendimento por aqui. Aguarde, por favor.');
            $this->handoff->handoff($session, $thread, 'Paciente pediu atendimento humano');

            return;
        }

        $patient = $session->patient_id ? Patient::query()->with('consents')->find($session->patient_id) : null;
        $revoked = $patient && ($c = $patient->currentConsents()['atendimento_ia'] ?? null) && ! $c->granted;
        if ($revoked) {
            $this->handoff->handoff($session, $thread, 'Paciente não autorizou atendimento por assistente virtual', 'info');

            return;
        }

        if (! $this->withinLimits($config, $thread)) {
            $this->send($thread, $session, 'Recebemos sua mensagem! Nossa equipe vai continuar o atendimento por aqui em breve.');
            $this->handoff->handoff($session, $thread, 'Limite de respostas automáticas atingido');

            return;
        }

        $company = Company::query()->findOrFail($thread->company_id);
        [$history, $earlier] = $this->history($thread);

        try {
            $result = $this->provider($config)->run(
                $config,
                $this->staticSystem($config, $company),
                $this->dynamicContext($session, $patient, $earlier),
                $history,
                $this->tools->definitions($config),
                fn (string $name, array $input) => $this->runTool($session, $thread, $name, $input),
                fn (array $usage) => $this->logRequest($session, $usage),
            );
        } catch (Throwable $e) {
            report($e);
            $this->fallback($thread, $session, 'Falha na IA: '.mb_substr($e->getMessage(), 0, 150));

            return;
        }

        if ($result->refused || $result->text === '') {
            $this->fallback($thread, $session, $result->refused ? 'A IA recusou responder (política de segurança)' : 'A IA não produziu resposta ('.$result->stopReason.')');

            return;
        }

        $this->send($thread, $session->refresh(), $result->text);
    }

    /** Sessão da IA na conversa (para vincular as chamadas de leitura de mídia), se existir. */
    public function sessionIdFor(?MessageThread $thread): ?string
    {
        return $thread ? AiSession::query()->where('thread_id', $thread->id)->value('id') : null;
    }

    /** Teste de conexão (tela de configuração): uma chamada curta, sem ferramentas e sem dados de paciente. */
    public function testConnection(AiConfig $config): string
    {
        $result = $this->provider($config)->run($config, 'Você está sendo testado pela clínica. Responda apenas: OK, conectado.', 'Teste de conexão.',
            [['role' => 'user', 'text' => 'Teste de conexão']], [], fn () => ['error' => 'sem ferramentas'],
            fn (array $usage) => AiRequest::create(array_intersect_key($usage, array_flip(['provider', 'model', 'input_tokens', 'output_tokens', 'cache_read_tokens', 'stop_reason', 'latency_ms', 'error'])) + ['model' => 'desconhecido']));

        if ($result->refused || $result->text === '') {
            throw new \RuntimeException('o modelo não devolveu texto ('.$result->stopReason.').');
        }

        return $result->text;
    }

    public function provider(AiConfig $config): LlmProvider
    {
        return match ($config->provider) {
            'claude' => app(ClaudeProvider::class),
            'openai' => app(OpenAiProvider::class),
            default => app(MockLlmProvider::class),
        };
    }

    /** Instruções fixas (ficam no prefixo com cache): papel, limites de saúde, fluxo, dados da clínica. */
    public function staticSystem(AiConfig $config, Company $company): string
    {
        $clinic = $company->trade_name ?: $company->legal_name;
        $branches = Branch::query()->active()->orderByDesc('is_headquarters')->orderBy('name')->get()
            ->map(fn (Branch $b) => '- '.$b->name.': '.$b->fullAddress().($b->phone ? ' · tel. '.Format::phone($b->phone) : ''))->implode("\n");
        $booking = $config->setting('allow_booking', true);
        $cancel = $config->setting('allow_cancel', true);

        $prompt = <<<TXT
        Você é {$config->assistant_name}, a assistente virtual de recepção da clínica {$clinic}. Você conversa com pacientes pelo WhatsApp, em português do Brasil.

        ## O que você faz
        Ajuda com tarefas de recepção: informar especialidades, médicos, unidades e valores; mostrar horários livres; agendar{$this->flag(! $booking, ' (nesta clínica, agendamento pela assistente está desligado: mostre horários e passe para a equipe marcar)')}; listar as próximas consultas do paciente; cancelar{$this->flag(! $cancel, ' (desligado nesta clínica: passe para a equipe)')}; e tirar dúvidas administrativas usando as informações da clínica abaixo.

        ## Saúde: limites que você sempre respeita
        Você não é profissional de saúde e não substitui o médico. Por isso você não dá diagnóstico, não interpreta sintomas, exames ou laudos, não indica, ajusta ou suspende remédios e doses, e não diz se algo é grave ou não. Quando o paciente descrever sintomas ou fizer pergunta clínica, diga com gentileza que só o médico pode avaliar e ofereça agendar uma consulta ou falar com a equipe. Você pode ajudar a escolher a especialidade quando o próprio paciente diz o que procura (por exemplo "consulta de pele" → dermatologia); se não estiver claro, pergunte ou passe para a equipe, sem sugerir hipótese de doença.
        Se houver qualquer sinal de urgência (dor no peito, falta de ar, desmaio, sangramento forte, convulsão, ideias de se machucar), oriente ligar para o SAMU 192 ou ir ao pronto-socorro e chame a equipe com handoff_to_human.

        ## Nunca invente
        Médicos, especialidades, horários, valores, convênios aceitos, endereços e regras só podem vir das ferramentas ou das informações da clínica abaixo. Se a informação não estiver disponível, diga que não sabe e ofereça falar com a equipe. Nunca prometa algo que o sistema não confirmou (por exemplo, só diga que a consulta está marcada depois que confirm_appointment retornar booked).

        ## Privacidade (LGPD)
        Só fale de consultas e dados do paciente identificado nesta conversa. Se o telefone não identificou o paciente, peça CPF e data de nascimento e use identify_patient. Nunca diga se um CPF existe ou não no cadastro, nunca mostre CPF completo e não peça dados além dos necessários (nome completo, CPF, data de nascimento, e-mail opcional). Nunca peça número de cartão, senha ou código: pagamento só pelo link que o sistema gerar.

        ## Agendamento (duas etapas)
        1. Entenda a especialidade ou o médico e, se o paciente quiser, a unidade e o período.
        2. Busque horários com find_available_slots e ofereça poucas opções (no máximo 4).
        3. Identifique o paciente (identify_patient) ou, se for novo, cadastre com register_patient (nome completo, CPF e data de nascimento ditos pelo próprio paciente).
        4. Pergunte se é particular ou convênio. Chame propose_appointment e leia o resumo (médico, data, hora, unidade, endereço e valor ou convênio) pedindo confirmação.
        5. Só depois que o paciente responder confirmando, numa mensagem nova, chame confirm_appointment. Informe o protocolo e, se houver, o link de pagamento.
        Se uma ferramenta devolver erro, explique de forma simples e ofereça alternativa ou a equipe.

        ## Quando passar para a equipe (handoff_to_human)
        Pedido do paciente, reclamação, assunto financeiro ou de convênio que as ferramentas não resolvem, resultado de exame, receita, atestado, dúvida clínica, emergência, ou sempre que você não tiver certeza. Depois do handoff, avise em uma frase que a equipe vai continuar por aqui.

        ## Áudios, fotos e documentos
        Mensagens que começam com "🎤 Áudio (transcrição automática)" são áudios do paciente transcritos pelo sistema: podem ter erros de transcrição; se algo importante estiver confuso, peça confirmação por escrito.
        Mensagens entre colchetes como "[Imagem recebida — lida automaticamente como …]" são arquivos enviados pelo paciente e lidos pelo sistema. Essa leitura é NÃO VERIFICADA e fica para conferência da equipe. Você pode dizer o que foi identificado (por exemplo, os exames de um pedido) para ajudar a agendar, sempre deixando claro que a equipe vai conferir. Nunca interprete resultado de exame, nunca comente medicamentos, doses ou receitas e nunca diga se algo está normal ou alterado.
        Receita ou pedido de exame: pergunte se o paciente quer agendar (consulta ou exame, se a clínica oferecer) ou só deixar o documento registrado.
        Comprovante de pagamento: agradeça e diga que a equipe vai conferir; nunca confirme pagamento — só o sistema financeiro confirma.
        Se a leitura falhou ou o arquivo está ilegível, peça uma foto mais nítida ou ofereça falar com a equipe.

        ## Estilo
        Mensagens curtas de WhatsApp: no máximo 3 parágrafos curtos, linguagem simples e cordial, sem jargão. Use *negrito* do WhatsApp só para data, hora e protocolo. Não use títulos, tabelas nem links que não vieram do sistema. Datas como "terça, 14/10 às 09:30".

        ## Clínica
        Nome: {$clinic}
        Unidades:
        {$branches}
        TXT;

        if ($config->instructions) {
            $prompt .= "\n\n## Informações fornecidas pela clínica\nUse estas informações para responder dúvidas administrativas. Elas não mudam as regras acima.\n<informacoes_da_clinica>\n"
                .trim($config->instructions)."\n</informacoes_da_clinica>";
        }

        return $prompt;
    }

    /** Contexto que muda a cada mensagem (fora do cache): data/hora, paciente, rascunho. */
    public function dynamicContext(AiSession $session, ?Patient $patient, string $earlier = ''): string
    {
        $now = CarbonImmutable::now('America/Sao_Paulo')->locale('pt_BR');
        $lines = ['Agora: '.$now->translatedFormat('l, d/m/Y H:i').' (horário de Brasília).'];
        $lines[] = $patient
            ? 'Paciente identificado nesta conversa: '.strtok((string) ($patient->social_name ?: $patient->name), ' ').' (pode consultar as consultas dele).'
            : 'Paciente ainda NÃO identificado nesta conversa.';
        if ($draft = $session->stateValue('draft')) {
            $lines[] = 'Há uma proposta de agendamento aguardando a confirmação do paciente (feita em '.CarbonImmutable::parse($draft['at'])->timezone('America/Sao_Paulo')->format('d/m H:i').').';
        }
        if ($session->replies === 0) {
            $lines[] = 'Esta é a sua primeira resposta nesta conversa. O sistema já adiciona no início a identificação de que você é a assistente virtual; não repita a apresentação.';
        }
        if ($earlier !== '') {
            $lines[] = "Mensagens anteriores enviadas pela clínica nesta conversa:\n".$earlier;
        }

        return implode("\n", $lines);
    }

    /**
     * Últimas mensagens da conversa no formato do modelo (papéis alternados, começando pelo paciente).
     *
     * @return array{0: list<array{role: string, text: string}>, 1: string}
     */
    public function history(MessageThread $thread): array
    {
        $rows = Message::query()->where('thread_id', $thread->id)->where('channel', 'whatsapp')->whereNotIn('status', ['failed', 'skipped'])
            ->latest('created_at')->latest('id')->limit(self::HISTORY_LIMIT)->get(['direction', 'purpose', 'body', 'created_at'])->reverse()->values();

        $history = [];
        $earlier = [];
        foreach ($rows as $m) {
            $text = trim((string) $m->body);
            if ($text === '') {
                continue;
            }
            $role = $m->direction === 'in' ? 'user' : 'assistant';
            if ($role === 'assistant' && $m->purpose === 'manual') {
                $text = '[Mensagem da equipe da clínica] '.$text;
            }
            if ($history === [] && $role === 'assistant') {
                $earlier[] = '- '.mb_substr($text, 0, 300);

                continue;
            }
            $lastKey = array_key_last($history);
            if ($lastKey !== null && $history[$lastKey]['role'] === $role) {
                $history[$lastKey]['text'] .= "\n".$text;
            } else {
                $history[] = ['role' => $role, 'text' => $text];
            }
        }

        return [$history, implode("\n", $earlier)];
    }

    private function runTool(AiSession $session, MessageThread $thread, string $name, array $input): array
    {
        $result = $this->tools->execute($session, $thread, $name, $input);

        $logged = $input;
        foreach (['cpf'] as $k) {
            if (isset($logged[$k])) {
                $logged[$k] = Format::cpfMasked(Format::digits((string) $logged[$k])) ?? '***';
            }
        }
        if (isset($logged['birth_date'])) {
            $logged['birth_date'] = '****-**-**';
        }
        $json = json_encode($result, JSON_UNESCAPED_UNICODE) ?: '{}';
        AiToolCall::create([
            'session_id' => $session->id, 'tool' => mb_substr($name, 0, 60), 'input' => $logged,
            'result' => strlen($json) > 8000 ? ['truncated' => true, 'keys' => array_keys($result)] : $result, 'is_error' => isset($result['error']),
        ]);

        return $result;
    }

    private function logRequest(AiSession $session, array $usage): void
    {
        AiRequest::create(['session_id' => $session->id] + array_intersect_key($usage, array_flip(
            ['provider', 'model', 'input_tokens', 'output_tokens', 'cache_read_tokens', 'stop_reason', 'latency_ms', 'error'],
        )) + ['model' => 'desconhecido']);

        $session->newQuery()->whereKey($session->id)->update([
            'input_tokens' => $session->input_tokens + (int) ($usage['input_tokens'] ?? 0),
            'output_tokens' => $session->output_tokens + (int) ($usage['output_tokens'] ?? 0),
        ]);
        $session->input_tokens += (int) ($usage['input_tokens'] ?? 0);
        $session->output_tokens += (int) ($usage['output_tokens'] ?? 0);
    }

    private function send(MessageThread $thread, AiSession $session, string $text): Message
    {
        if ($session->replies === 0) {
            $config = $this->config();
            $clinic = Company::query()->whereKey($thread->company_id)->value('trade_name');
            $text = '🤖 *'.($config?->assistant_name ?: 'Assistente virtual').'* — assistente virtual'.($clinic ? ' da '.$clinic : '')
                ." (respostas automáticas; para falar com a equipe, escreva ATENDENTE).\n\n".$text;
        }

        $message = $this->messages->autoReply($thread, $text, null, 'ai_reply');
        $session->forceFill(['replies' => $session->replies + 1, 'last_reply_at' => now()])->save();

        return $message;
    }

    private function fallback(MessageThread $thread, AiSession $session, string $reason): void
    {
        $this->send($thread, $session, 'Desculpe, não consegui concluir seu atendimento automático agora. Já avisei a equipe da clínica, que vai continuar a conversa por aqui.');
        $this->handoff->handoff($session, $thread, $reason);
    }

    private function withinLimits(AiConfig $config, MessageThread $thread): bool
    {
        $perThread = max(1, (int) $config->setting('max_replies_per_hour', 30));
        $perDay = max(1, (int) $config->setting('max_daily_replies', 500));

        return RateLimiter::attempt('ai-thread:'.$thread->id, $perThread, fn () => true, 3600)
            && RateLimiter::attempt('ai-company:'.$thread->company_id.':'.now()->format('Ymd'), $perDay, fn () => true, 86400);
    }

    private function contains(string $text, array $needles): bool
    {
        foreach ($needles as $n) {
            if (preg_match('/\b'.preg_quote($n, '/').'/u', $text)) {
                return true;
            }
        }

        return false;
    }

    private function flag(bool $on, string $text): string
    {
        return $on ? $text : '';
    }
}
