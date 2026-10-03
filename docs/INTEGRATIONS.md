# Integrações

Regra geral: **toda integração externa fica atrás de uma interface**, com adapters reais e um
adapter **MOCK** explicitamente identificado. Credenciais por clínica ficam criptografadas no banco
(`integration_credentials`); credenciais da plataforma, apenas em variáveis de ambiente.

| Modo | Uso | Indicação na interface |
|---|---|---|
| `mock` | desenvolvimento/testes — nenhuma chamada externa | selo "MOCK" em cobranças/mensagens |
| `sandbox` | homologação com ambiente de testes do provedor | selo "SANDBOX" |
| `production` | produção | — |

## Pagamentos (Fase 8) — implementado

Configuração: *Administração → Pagamentos online* (permissão `integracao.gerenciar`).

| Gateway | O que está implementado | Webhook | Observações |
|---|---|---|---|
| **ASAAS** | clientes, cobranças PIX/boleto/cartão/"paciente escolhe" (`/payments`), QR Code PIX (`/payments/{id}/pixQrCode`), consulta, cancelamento, estorno, **split nativo** (`walletId` + `percentualValue`/`fixedValue`) | `POST /webhooks/pagamentos/{id}` com header `asaas-access-token` | Sandbox `https://api-sandbox.asaas.com/v3`; produção `https://api.asaas.com/v3`. CPF do paciente obrigatório |
| **Cielo** | Link de Pagamento (OAuth2 `/v2/token`, `/v1/products`), consulta `/v1/products/{id}/payments`, estorno `/v2/orders/{n}/void` | URL de notificação com `?token=` (a Cielo não assina) | Sem sandbox no Link de Pagamento: homologar com valor baixo. Sem split nativo (repasse interno) |
| **Cielo API E-commerce (split)** | `cielo_api`: venda `POST /1/sales/` com `Payment.Type = SplittedCreditCard` e `SplitPayments [{SubordinateMerchantId, Amount}]`; consulta `GET apiquery…/1/sales/{PaymentId}`; estorno `PUT /1/sales/{id}/void`. Cartão tokenizado no navegador pelo **Silent Order Post** (OAuth `auth(sandbox).braspag.com.br/oauth2/token` → AccessToken `transaction(sandbox).pagador.com.br/post/api/public/v2/accesstoken` → script `silentorderpost-1.0.min.js`) | "Post de Notificação" com `?token=` + consulta obrigatória | Somente cartão de crédito. Exige **contrato de Split** com a Cielo e cada médico cadastrado como **subordinado** (ID em *Repasses*). Credenciais: MerchantId/MerchantKey + ClientId/ClientSecret |
| **MOCK** | simulação completa (pago, valor divergente, vencido, estorno) pelo **mesmo fluxo** do webhook | header `X-Mock-Token` | bloqueado em produção (`PAYMENTS_ALLOW_MOCK_IN_PRODUCTION`) |

**Maquininha Cielo com split:** quando a clínica contrata o split na maquininha (Cielo Smart/Flash), a
divisão acontece na própria Cielo. No recebimento por cartão marque "Venda na maquininha Cielo com split"
(exige NSU/autorização e o ID de subordinado do médico): o sistema registra a parte do médico como já
liquidada (origem *Maquininha Cielo*) e ela não entra no fechamento de repasse. Sem a marcação, a venda na
maquininha gera repasse interno como antes. Não há integração direta com o terminal (a Cielo não expõe API
pública para o POS de balcão); a conciliação é pelo NSU.

**Homologação:** os adaptadores seguem a documentação oficial e têm testes automatizados com respostas
simuladas dos gateways. Antes de usar em produção, faça em SANDBOX (ASAAS) ou com valor baixo (Cielo): gerar
cobrança, pagar (Cielo split: no SANDBOX da API E-commerce, com subordinado de teste; confirme no sandbox os
nomes dos campos do Silent Order Post), conferir o aviso em *Últimos avisos recebidos*, conferir a baixa e um estorno.

### Desenho (referência)

```php
interface PaymentProviderInterface {
    public function createCharge(ChargeRequest $r): ChargeResult;   // PIX, cartão, boleto
    public function getCharge(string $providerId): ChargeStatus;     // confirmação ativa
    public function refund(string $providerId, int $amountCents): RefundResult;
    public function parseWebhook(Request $request): WebhookEvent;   // valida assinatura/token
    public function supportsNativeSplit(): bool;
}
```

- **ASAAS** — API v3 (`/payments`, `/pix/qrCode`, split por `walletId`), webhook com
  `asaas-access-token` validado; sandbox `https://sandbox.asaas.com/api/v3`.
- **Cielo** — API e-commerce 3.0 (cartão, PIX), `MerchantId/MerchantKey`; consulta de status após notificação.
- **Fluxo seguro:** cobrança criada com `idempotency_key` → link enviado → webhook recebido e
  armazenado (`provider_event_id` único) → **consulta à API do gateway** confirma valor/status →
  transação e split registrados → consulta confirmada → comprovante. Nenhum pagamento é confirmado
  por declaração do paciente nem por webhook não autenticado.

## Convênios — padrão TISS (Fase 9) — implementado

| Item | Como está |
|---|---|
| Versão | **TISS 4.01.00**. Schemas oficiais da ANS em `resources/tiss/4.01.00/` (fonte: portal da ANS, *Padrões e schemas*; cópia de [renatofagalde/app-tiss-schemas](https://github.com/renatofagalde/app-tiss-schemas)). Nova versão: copiar os XSD para `resources/tiss/<versão>/` e incluir em `Insurer::TISS_VERSIONS` |
| Mensagem | `ENVIO_LOTE_GUIAS` com `loteGuias` de **guiaConsulta** ou **guiaSP-SADT** (um tipo por lote, até 100 guias) |
| Validação | **Todo XML é validado contra o XSD antes de fechar o lote** (`TissMessageBuilder::validate`). Erro de schema → o lote não fecha e a mensagem do validador aparece na tela |
| Hash | Epílogo com MD5 da concatenação dos valores de todos os elementos (sem tags), em ISO-8859-1 |
| Identificação | Prestador: `codigoPrestadorNaOperadora` (cadastro do convênio) ou CNPJ da unidade; CNES da unidade (sem CNES: `9999999`, conforme o padrão); profissional: CRM (`06`), UF (código IBGE), CBO (especialidade do médico; padrão 225125 — editável na guia) |
| Envio | **Manual**: baixe o XML no lote e envie no portal da operadora; registre o protocolo. O webservice TISS de cada operadora (SOAP, credenciais próprias) **não** está implementado |
| Retorno | Demonstrativo digitado por guia (valor pago, código de glosa da tabela TISS 38 ou motivo). Importação automática do XML de demonstrativo: futura |
| Limitações conhecidas | SP/SADT: o solicitante é a própria clínica/médico executante (pedido externo vai na observação); sem guia de honorários/internação/odonto; sem assinatura digital do XML (opcional no padrão) |

**Homologação:** antes do primeiro envio real, valide o XML no validador/portal da operadora (cada operadora
pode ter regras próprias além do schema) e confira os códigos TUSS e valores da tabela contratada.

## WhatsApp (Fase 11) — implementado

Configuração: *Administração → WhatsApp e mensagens* (permissão `integracao.gerenciar`).

| Item | Como está |
|---|---|
| Provedor | **WhatsApp Business Platform — Cloud API (Meta)**, `POST https://graph.facebook.com/{versão}/{phone_number_id}/messages` com token de System User (Bearer). **MOCK** para demonstração (nada sai) |
| Webhook | `GET/POST /webhooks/whatsapp/{canal}` — verificação `hub.mode=subscribe` + `hub.verify_token`; eventos com **`X-Hub-Signature-256`** (HMAC-SHA256 do corpo com o App Secret, comparação em tempo constante). Assine o campo **messages** no app da Meta |
| Mensagens da clínica | Só com **modelos aprovados** (categoria Utilidade, pt_BR). Nomes e textos sugeridos em `config/messaging.php` (variáveis na mesma ordem); lembrete com 3 botões de resposta rápida, o sistema envia o payload `CONFIRM/CANCEL/RESCHEDULE:{agendamento}` |
| Texto livre | Só na **janela de 24 h** após a última mensagem do paciente (respostas automáticas e da recepção) |
| Recebido | Status `sent/delivered/read/failed`; mensagens de texto e botões. Áudio/imagem/documento são registrados como "[tipo]" — o tratamento chega na Fase 13 |
| Idempotência | `messages.provider_message_id` único (wamid); chave de deduplicação por aviso (`reminder:24:{agendamento}:{horário}`) |
| E-mail | Mesmo texto, via SMTP, quando não há WhatsApp (com consentimento de e-mail) |
| SMS | **Não integrado** nesta versão |

**Homologação:** use o número de teste da Meta (modo "Teste"), cadastre os modelos e aguarde a aprovação,
configure o webhook e valide: agendamento → mensagem; lembrete → botão Confirmar → agenda confirmada.

### WhatsApp NÃO OFICIAL (opcional, escolha da clínica)

Na mesma tela, a clínica pode escolher um provedor **não oficial** (WhatsApp comum conectado por QR Code).
Exige marcar o **aceite do risco** (registrado com usuário, data e auditoria); a interface mostra o selo
**NÃO OFICIAL** no canal, nas conversas e nas configurações. Recomendação do sistema: API oficial.

| Provedor | Envio | Webhook | Onde roda |
|---|---|---|---|
| **Z-API** | `POST https://api.z-api.io/instances/{instância}/token/{token}/send-text` `{phone, message}` + cabeçalho `Client-Token` | Painel → "Ao receber" e "Status da mensagem" = URL do canal com `?token=` | Nuvem da Z-API (funciona com a HostGator) |
| **Evolution API v2** | `POST {URL}/message/sendText/{instância}` `{number, text}` + cabeçalho `apikey` | Webhook da instância (`MESSAGES_UPSERT`, `MESSAGES_UPDATE`) = URL do canal com `?token=` | Servidor próprio (VPS/Docker), só HTTPS |

- Sem modelos da Meta e sem janela de 24 h: tudo vai como texto; o lembrete pede resposta **1/2/3**.
- Esses serviços não assinam os eventos: o webhook é autenticado pelo token secreto na URL (tempo constante).
- Grupos, canais, status e mensagens enviadas pelo próprio número são ignorados.
- "Verificar conexão" consulta se o WhatsApp está conectado (QR Code lido, celular online).
- **Riscos:** viola os termos do WhatsApp (o número pode ser bloqueado sem aviso); dados dos pacientes
  passam pelo serviço contratado (exija contrato de tratamento — LGPD); instabilidade quando o celular desconecta.

## IA — recepcionista virtual (Fase 12) — implementado

Configuração: *Administração → Atendimento IA* (permissão `ia.configurar`). Detalhes em [AI.md](AI.md).

| Provedor | Integração | Chave | Situação |
|---|---|---|---|
| **Claude (Anthropic)** — padrão | SDK oficial `anthropic-ai/sdk` (PHP), Messages API com *tool use*, *prompt caching*, esforço configurável; modelo padrão `claude-opus-5-5` | `ANTHROPIC_API_KEY` (plataforma) ou chave da clínica (criptografada) | Produção (requer chave) |
| **ChatGPT (OpenAI)** | `POST {OPENAI_BASE_URL}/chat/completions` com *function calling*; modelo **informado pela clínica** | `OPENAI_API_KEY` (plataforma) ou chave da clínica | Produção (requer chave e modelo) |
| **MOCK** | Respostas fixas `[MOCK]` com as ferramentas reais | — | Demonstração/homologação |

- Sem chave configurada, a IA não inventa resposta: a conversa vai para a equipe com o motivo.
- "Testar conexão" envia só uma mensagem curta, sem dados de pacientes.
- Tokens, cache, tempo e erros de cada chamada ficam em `ai_requests` (base para custos e cotas).
- Contrate o provedor com contrato de tratamento de dados (DPA) e sem uso dos dados para treinamento.

**Homologação:** ative em modo MOCK com o WhatsApp MOCK e use "Simular mensagem recebida" na conversa;
depois troque para Claude ou ChatGPT com a chave, "Testar conexão" e uma conversa real pelo número de teste da Meta.

## Assinatura digital ICP-Brasil (arquitetura pronta — Fase 6)

Receita e atestado **digitais** só valem com assinatura qualificada ICP-Brasil (MP 2.200-2/2001,
Lei 14.063/2020, Res. CFM 2.299/2021). Interface `App\Modules\Documents\Signature\DocumentSigner`
(`sign(document, pdf) → {pdf, reference}`), driver em `SIGNATURE_DRIVER` (padrão `none`). Integração
prevista com certificados em nuvem (BirdID, VIDaaS, SafeID, Certillion) gerando PDF PAdES; os campos
`signature_*` de `medical_documents` já existem. **Nada é simulado**: sem provedor configurado, o
documento é impresso e assinado de próprio punho.

## Outras

- **E-mail:** SMTP/SES; **SMS:** adapter (Zenvia/Twilio).
- **Armazenamento:** S3 compatível.
- **Convênios/TISS:** estrutura de guias e tabelas preparada para XML TISS (ANS).
- **Bancos:** OFX/CSV e Open Finance (quando permitido) na Fase 14.
- **Telemedicina:** `VideoRoomProviderInterface` (ex.: Daily, Twilio, Jitsi) — sem impedimentos na arquitetura.
- **Webhooks de saída:** assinatura HMAC, retry exponencial, histórico de entregas.
