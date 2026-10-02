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
| **MOCK** | simulação completa (pago, valor divergente, vencido, estorno) pelo **mesmo fluxo** do webhook | header `X-Mock-Token` | bloqueado em produção (`PAYMENTS_ALLOW_MOCK_IN_PRODUCTION`) |

**Homologação:** os adaptadores seguem a documentação oficial e têm testes automatizados com respostas
simuladas dos gateways. Antes de usar em produção, faça em SANDBOX (ASAAS) ou com valor baixo (Cielo): gerar
cobrança, pagar, conferir o aviso em *Últimos avisos recebidos*, conferir a baixa e um estorno.

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

## WhatsApp (Fase 11)

WhatsApp Business **Cloud API (Meta)**. Webhook `GET` (verify token) e `POST` (assinatura
`X-Hub-Signature-256` com App Secret) → evento enfileirado → IA/atendente. Mensagens proativas
(lembretes, confirmações) somente com **templates aprovados** e opt-in. Idempotência por `wamid`.

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
