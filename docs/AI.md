# Inteligência artificial

## Princípios

1. **A IA não substitui o médico.** Não diagnostica, não prescreve, não interpreta exames, não diz se
   algo é grave. Dúvida clínica → oferece consulta ou a equipe.
2. **Nada inventado:** médicos, horários, valores, convênios e endereços só vêm das ferramentas do
   sistema (agenda real, cadastros) ou das informações escritas pela clínica. Se não sabe, diz que
   não sabe e oferece atendimento humano.
3. **Humano sempre disponível:** "ATENDENTE" passa a conversa para a equipe; a equipe pode assumir e
   devolver a conversa a qualquer momento.
4. **Tudo registrado:** cada chamada ao modelo (`ai_requests`: provedor, modelo, tokens, cache,
   tempo, erro) e cada ação (`ai_tool_calls`: entrada com CPF mascarado e resultado).
5. **Minimização (LGPD):** ao provedor vão só a conversa e os resultados das ações (sem prontuário).

## Recepcionista virtual (Fase 12) — WhatsApp

```
Webhook do WhatsApp → InboundService (respostas 1/2/3 e botões continuam automáticos)
   → mensagem livre + IA ativa → AiReply (dispatchAfterResponse: roda depois do 200 à Meta)
   → AiReceptionist
        regras fixas ANTES do modelo: emergência (SAMU 192 / CVV 188) · "atendente" · consentimento
        revogado · limites por conversa/hora e por clínica/dia
        → LlmProvider: ClaudeProvider (SDK oficial anthropic-ai/sdk) | OpenAiProvider (ChatGPT) | MockLlmProvider
        → ReceptionistTools (executadas NO BACKEND, com o TenantContext da clínica)
   → resposta pelo WhatsApp (janela de 24 h) · erro/recusa → mensagem padrão + equipe avisada
```

### Ferramentas

| Ferramenta | O que faz | Proteções |
|---|---|---|
| `list_specialties`, `list_doctors` | Especialidades e médicos ativos, unidades, tipos de atendimento, valor particular, se aceita convênio | Só da clínica (escopo multiempresa) |
| `find_available_slots` | Próximos horários **livres reais** (grade, bloqueios, feriados, limites) | Antecedência mínima e janela do portal; sem encaixe |
| `identify_patient` | CPF + data de nascimento | Não revela se o CPF existe; 3 erros → equipe |
| `register_patient` | Cadastro novo com o WhatsApp da conversa | Possível duplicidade → equipe |
| `my_appointments`, `cancel_appointment` | Consultas do paciente identificado | Só do próprio paciente; prazo de cancelamento do WhatsApp |
| `propose_appointment` → `confirm_appointment` | Agendamento em **duas etapas** | A confirmação só vale numa **mensagem nova** do paciente; reserva pela mesma regra da recepção (sem dupla marcação, idempotente) |
| `handoff_to_human` | Passa a conversa para a equipe | Notificação no sino (`ia.conversas`) |

Com **pré-pagamento** ligado, a confirmação gera a cobrança no gateway padrão da clínica (Fase 8) e
a IA envia o link; o pagamento só é confirmado pelo webhook do gateway.

### Fluxo de agendamento

especialidade/médico → horários livres → identificação (ou cadastro: nome, CPF, nascimento) →
particular ou convênio → **resumo com valor** → paciente confirma → consulta marcada (canal "IA",
protocolo) → link de pagamento (opcional) → aviso de agendamento pelo modelo aprovado (Fase 11).

### Provedores

| Provedor | Como | Modelo |
|---|---|---|
| **Claude (Anthropic)** — padrão | SDK oficial PHP (`anthropic-ai/sdk`), tool use manual, *prompt caching* no prefixo fixo (ferramentas + instruções), esforço `low` configurável, fallback de recusa no servidor quando o modelo aceita | `claude-opus-5-5` (padrão) ou outro informado |
| **ChatGPT (OpenAI)** | Chat Completions com *function calling* (`tools`, `tool_calls` → mensagens `tool`) | Obrigatório informar (o nome contratado na conta) |
| **MOCK** | Respostas fixas marcadas `[MOCK]`, usando as ferramentas reais | — |

Chave: a da clínica (criptografada no banco, nunca exibida) ou, se vazia, a da plataforma
(`ANTHROPIC_API_KEY` / `OPENAI_API_KEY` no `.env`). Trocar de provedor descarta a chave anterior.

### Configuração (Atendimento IA — `ia.configurar`)

Ativar, provedor, modelo, chave, nome da assistente, **informações da clínica** (horário, convênios,
preparo, políticas — não mudam as regras de segurança), responder no WhatsApp, permitir agendar,
cancelar, enviar link de pagamento, esforço, limites, **teste de conexão** (sem dados de pacientes) e
uso dos últimos 30 dias (chamadas, tokens, cache, conversas passadas para a equipe, consultas marcadas).

### Conversas (`ia.conversas`)

Selo "IA atendendo" / "com a equipe", motivo do handoff, **Assumir (pausar IA)**, **Devolver à IA**,
registro das ações executadas. Responder manualmente também pausa a IA na conversa.

### Hospedagem compartilhada

A resposta roda depois do 200 ao webhook (`dispatchAfterResponse` — `fastcgi_finish_request` /
LiteSpeed), sem worker de fila. Uma resposta por vez por conversa (lock em cache); várias mensagens
seguidas recebem uma resposta. Tempo máximo do processo estendido para 240 s no job.

## Áudio, imagem e OCR (Fase 13)

```
WhatsApp (oficial, Z-API, Evolution ou MOCK) → mídia → ProcessInboundMedia (depois do 200)
   → download (Meta: GET /{media_id} + URL temporária com token; Z-API: URL https; Evolution: base64)
   → MediaStore: disco PRIVADO, tipo pelo CONTEÚDO, limites (imagem 5 MB, PDF 10 MB, áudio 16 MB)
   → áudio: Transcriber (OpenAI /audio/transcriptions, pt, modelo configurável — padrão whisper-1)
     imagem/PDF: DocumentReader (Claude: bloco image/document + structured outputs;
                                 ChatGPT: image_url/file + json_schema estrito; MOCK)
   → mensagem vira o resumo "NÃO VERIFICADO" → equipe avisada (comprovante: alerta)
   → recepcionista virtual responde (se estiver atendendo) — regras de emergência valem para áudio
```

- **A IA só transcreve:** não corrige, não completa, não deduz medicamentos/doses/exames; trechos
  ilegíveis ficam vazios e listados como incertos; resultado de exame só como texto, sem interpretação.
- Campos: tipo (receita, pedido de exame, resultado, comprovante, atestado, carteirinha, documento pessoal,
  outro, ilegível), resumo, paciente, data, profissional e registro, medicamentos (nome, concentração,
  forma, posologia, quantidade), exames, pagamento (valor, data, pagador, recebedor, forma, identificador),
  legibilidade, incertezas e texto completo.
- **Conferência humana** em *Documentos recebidos* (`ia.conversas`): original (download auditado, sem
  cache), dados lidos, **Marcar como conferido** (com observação), **Descartar** (com motivo — nada é
  apagado), **Anexar à ficha** (vira arquivo do paciente, não liberado no portal; `documento.anexar`).
- **Comprovante nunca dá baixa**: o pagamento só é confirmado pelo financeiro/gateway.
- **Ler com IA** em qualquer imagem/PDF já anexado à ficha do paciente.
- A IA na conversa diz o que foi identificado deixando claro que a equipe vai conferir, pergunta se o
  paciente quer agendar ou só registrar, e nunca comenta receitas, doses ou resultados.
- Configuração: ligar/desligar leitura de documentos e transcrição; modelo e chave da OpenAI para áudio
  (o Claude não recebe áudio — com o Claude na conversa, o áudio usa a chave OpenAI da clínica ou da plataforma).

## Próximas fases

- Assistente clínico na área do médico (resumos e preenchimento assistido, sempre como sugestão editável).
- Limites de IA por plano (`ai_enabled`, quotas mensais) usando `ai_requests`.
