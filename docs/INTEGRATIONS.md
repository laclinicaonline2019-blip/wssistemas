# Integrações

Regra geral: **toda integração externa fica atrás de uma interface**, com adapters reais e um
adapter **MOCK** explicitamente identificado. Credenciais por clínica ficam criptografadas no banco
(`integration_credentials`); credenciais da plataforma, apenas em variáveis de ambiente.

| Modo | Uso | Indicação na interface |
|---|---|---|
| `mock` | desenvolvimento/testes — nenhuma chamada externa | selo "MOCK" em cobranças/mensagens |
| `sandbox` | homologação com ambiente de testes do provedor | selo "SANDBOX" |
| `production` | produção | — |

## Pagamentos (Fase 8)

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

## Outras

- **E-mail:** SMTP/SES; **SMS:** adapter (Zenvia/Twilio).
- **Armazenamento:** S3 compatível.
- **Convênios/TISS:** estrutura de guias e tabelas preparada para XML TISS (ANS).
- **Bancos:** OFX/CSV e Open Finance (quando permitido) na Fase 14.
- **Telemedicina:** `VideoRoomProviderInterface` (ex.: Daily, Twilio, Jitsi) — sem impedimentos na arquitetura.
- **Webhooks de saída:** assinatura HMAC, retry exponencial, histórico de entregas.
