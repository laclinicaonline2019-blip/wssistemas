# WS Finanças em Dia — análise, arquitetura e escopo

## 1. Análise do sistema atual (HTML)

O WS Finanças original é **um único arquivo HTML** (~58 KB) com CSS e JavaScript próprios, sem servidor:

| Aspecto | Situação encontrada |
|---|---|
| Armazenamento | `localStorage` do navegador, chave `wsfin:v1` (lista de itens `despesa`, `receita`, `divida`, `config`) ou o armazenamento do artefato do Claude |
| Funções | Contas do mês (pagas/a vencer/vencidas, atrasadas de meses anteriores), entradas, contas fixas (12 meses), Plano das dívidas (avalanche × bola de neve, simulação mês a mês e gráfico), Fechamento e economia (gastos por categoria × limite recomendado, sugestões, impacto nas dívidas, análise do Claude), Lançar por mensagem (interpretador de frases) |
| Visual | Tipografia Bricolage Grotesque + Manrope, tema claro/escuro, verde = pago, vermelho = atrasado, layout de uma coluna |
| Limitações | Dados presos a um navegador, um único usuário, sem empresas, sem backup, sem WhatsApp real, sem cobrança |

**Preservado integralmente:** o CSS original (copiado sem alterações em `public/assets/css/app.css`), a estrutura das abas, as regras de cálculo (resumo do mês, atrasadas, simulação das dívidas, limites por categoria, sugestões e dicas) e os formatos de mensagem do "Lançar rápido" (`luz 230 vence 15`, `aluguel 1100 dia 5 fixa`, `paguei luz`…). O JavaScript foi mantido e só a camada de armazenamento foi trocada pela API do servidor.

## 2. Arquitetura

```
 Navegador (computador/celular)          WhatsApp do cliente
        │  HTTPS (sessão + CSRF)                 │
        ▼                                        ▼  Cloud API (Meta) → webhook assinado
 ┌──────────────────────────────────────────────────────────────────────┐
 │ Laravel 13 / PHP 8.3 — monólito modular (HostGator: Apache + PHP)    │
 │  Middleware: Instalado → Sessão → Auth → 2FA → Cliente (tenant)      │
 │              → Permissão (ability:) → Assinatura (can.write)          │
 │  Serviços: Finance · Assistant(regras + IA) · WhatsApp · Billing      │
 │            Report · Import · Backup · Privacy(LGPD) · Audit           │
 └───────┬───────────────────────────┬──────────────────────┬───────────┘
         ▼                           ▼                      ▼
   MySQL/MariaDB              Asaas (assinaturas)     Anthropic (IA, opcional)
   (dados, sessões,           webhook + consulta
    cache, fila)
   Cron do cPanel (1/min) → schedule:run → cobrança, lembretes, retenção LGPD, backup, auditoria
```

* **Sem processo permanente:** fila, cache e sessão no banco; tudo que é periódico roda pelo cron a cada minuto. O webhook do WhatsApp responde 200 e processa a mensagem logo após a resposta (PHP-FPM) — sem worker.
* **Migração futura para VPS/nuvem:** o mesmo código roda com Redis, workers e PostgreSQL/MySQL gerenciado trocando só o `.env` (`CACHE_STORE`, `QUEUE_CONNECTION`, `SESSION_DRIVER`, `DB_*`).

### Modelo multiempresa / multicliente

| Conceito | Tabela | Observação |
|---|---|---|
| Cliente (assinante, PF ou PJ) | `accounts` | fronteira de isolamento |
| Empresa | `companies` | várias por cliente, com apelido para o WhatsApp |
| Usuário | `users` | um login pode ter vínculo com vários clientes (ex.: contador) |
| Vínculo + papel | `memberships` | admin, gestor, financeiro, consulta, contador |
| Empresas autorizadas | `company_access` | obrigatória para o contador |
| Lançamentos | `entries` | receita/despesa, competência, vencimento, pago, conta bancária, série fixa/parcelada, origem (web/whatsapp/import) |
| Dívidas e plano | `debts`, `company_settings` | |
| Contas bancárias | `bank_accounts` | saldo = inicial + entradas − pagamentos |
| Assinatura | `plans`, `subscriptions`, `invoices`, `subscription_events` | |
| WhatsApp | `whatsapp_contacts`, `whatsapp_messages`, `whatsapp_pendings`, `usage_counters` | |
| Auditoria | `audit_logs` | append-only com cadeia HMAC |

**Isolamento em camadas:** (1) contexto do cliente montado no servidor a partir do usuário logado; (2) escopo global em todo model financeiro que **falha fechado** sem contexto e filtra também as empresas autorizadas; (3) criação recusa empresa não autorizada e `account_id`/`company_id` são imutáveis; (4) **chaves estrangeiras compostas** `(account_id, company_id) → companies(account_id, id)` impedem, mesmo por SQL direto, ligar dados de clientes diferentes; (5) route model binding depois do contexto: id de outro cliente → 404.

### Permissões (verificadas no servidor)

| Papel | Ver | Lançar | Relatórios/exportar | Empresas/contas | Usuários | WhatsApp | Importar | Assinatura/LGPD |
|---|---|---|---|---|---|---|---|---|
| Administrador | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ |
| Gestor | ✔ | ✔ | ✔ | ✔ | | ✔ | ✔ | |
| Financeiro | ✔ | ✔ | ✔ | | | | ✔ | |
| Consulta | ✔ | | | | | | | |
| Contador | ✔ (só empresas autorizadas) | | ✔ | | | | | |

### Assistente (WhatsApp e caixa "Lançar rápido")

1. **Interpretador por regras** (gratuito, instantâneo): valores (`R$ 1.200`, `5 mil`, `45,90`), datas (`dia 20`, `20/11`, `amanhã`), pago × a pagar, receita, fixa, parcelado (`em 10x`), empresa (`da empresa A`, nome ou apelido), categoria por palavras‑chave, consultas (`quanto tenho para pagar amanhã?`, `contas vencidas?`, `resumo do mês`), `DESFAZER`, `CORRIGIR PARA 360`.
2. **IA (opcional)** só quando as regras não entendem; o resultado da IA **sempre pede confirmação**.
3. **Confirmação quando há dúvida:** empresa não identificada (lista numerada), sem descrição, possível duplicidade, valor alto.
4. **Antiduplicidade:** `wamid` único (a Meta reentrega webhooks) + detecção de lançamento igual recente.
5. **Regras da Meta:** respostas só dentro da janela de 24 h aberta pelo cliente; lembretes ativos só com **modelo aprovado** e **opt‑in** por número.

## 3. Recursos

**Preservados:** contas do mês, atrasadas, entradas, contas fixas, plano das dívidas com simulação e gráfico, fechamento e economia, sugestões, análise do Claude, lançar por mensagem, exemplos, tema claro/escuro, uso no celular.

**Novos:** multicliente e multiempresa; visão consolidada; usuários e papéis; contador com empresas autorizadas; contas bancárias; parcelamento; fluxo de caixa (12 meses, dia a dia, saldos bancários); relatórios por período/categoria/empresa/situação com CSV e PDF; WhatsApp oficial; painel do proprietário (clientes, planos, faturamento, inadimplência, uso de WhatsApp/IA, histórico, políticas, backups, prontidão); assinatura com Asaas (teste grátis, mensal/anual, Pix/boleto/cartão, webhook, régua de inadimplência, suspensão só de lançamentos); segurança (2FA, bloqueio, CSP, auditoria imutável, criptografia de documentos/telefones/mensagens); LGPD (exportação, retenção, eliminação); backups criptografados com teste de restauração; instalador web e CLI; pacote ZIP; importação dos dados locais.

## 4. Planos (padrão — editáveis no painel do proprietário)

| | Básico | Profissional | Empresarial |
|---|---|---|---|
| Preço sugerido | R$ 29,90/mês · R$ 299/ano | R$ 69,90/mês · R$ 699/ano | R$ 149,90/mês · R$ 1.499/ano |
| Empresas / usuários | 1 / 2 | 5 / 5 | 20 / 25 |
| WhatsApp | — | 1.500 msg/mês | 6.000 msg/mês |
| IA | — | 300 usos/mês | 1.500 usos/mês |
| Consolidado, relatórios avançados (PDF), contador | — | ✔ | ✔ |
| Perfis gestor/financeiro/consulta | — | — | ✔ |

## 5. Serviços externos e custos estimados

Valores aproximados de referência (nov/2026) — **confirme nos sites dos fornecedores antes de precificar**.

| Serviço | Para quê | Custo aproximado |
|---|---|---|
| HostGator (plano compartilhado com PHP 8.3, MySQL, cron e SSL) | hospedagem inicial | ~R$ 20 a 60/mês, conforme plano e período contratado |
| Domínio `.com.br` | endereço | ~R$ 40/ano (Registro.br) |
| SSL | HTTPS | gratuito (AutoSSL do cPanel) |
| WhatsApp Business Platform (Cloud API) | assistente | A Meta cobra por mensagem de **modelo** (marketing/utilidade/autenticação) com preço por país; respostas dentro da janela de atendimento aberta pelo cliente são gratuitas. Lembretes (modelo de utilidade) custam alguns centavos de real cada. Número exclusivo e verificação do Business Manager são necessários. |
| Asaas | cobrança das assinaturas | sem mensalidade; tarifa por cobrança recebida (Pix e boleto na faixa de ~R$ 1–3; cartão ~3–5% + taxa fixa) |
| Anthropic (Claude) — opcional | entender frases fora do padrão e análise do mês | só quando usado. Com `claude-opus-5-5` (US$ 4/US$ 20 por milhão de tokens de entrada/saída) cada interpretação curta fica em torno de US$ 0,005–0,01; trocar `WSF_AI_MODEL` por `claude-haiku-5-5` (US$ 0,10/US$ 0,50) reduz ~40×. Limite mensal por plano evita surpresas. |
| E-mail transacional | recuperação de senha | SMTP do cPanel (incluso) ou serviço dedicado (gratuito até alguns milhares/mês) |
| Backup externo | cópia fora do servidor | Google Drive/Dropbox/S3: R$ 0–10/mês |

Exemplo: 100 clientes no Profissional ≈ R$ 6.990/mês de receita; custos variáveis típicos (Asaas + WhatsApp + IA) ficam bem abaixo de 10% disso.

## 6. Quando migrar da HostGator para VPS

Migre quando houver: mais de ~300 clientes ativos, picos de mensagens que deixem o webhook lento, necessidade de fila com worker permanente, ou exigências de SLA. O código já suporta Redis/worker/Supervisor (veja `docs/HOSTGATOR.md`, seção "Migração").
