# API REST v1

Base: `/api/v1` · JSON · autenticação **Bearer** (Sanctum) · especificação: [openapi.yaml](openapi.yaml)

## Convenções

- Autorização sempre validada no backend (tenant → permissão → regra do serviço).
- Filial de trabalho: header opcional `X-Branch-Id` (validado contra os vínculos do usuário; 403 se não permitido).
- Correlação: envie/receba `X-Request-Id`.
- Erros: `401` não autenticado · `403` sem permissão (`{message, code?}`) · `404` inexistente **ou de outra empresa** ·
  `422` validação (`errors`) ou regra de negócio (`code`, ex.: `plan_limit_reached`, `last_admin`, `role_locked`) · `429` rate limit.
- Paginação: `?page=`, `?per_page=` (máx. 100; auditoria 200) — resposta `data`, `links`, `meta`.
- Rate limit: 120 req/min por usuário; emissão de token 10/min por IP + 5/min por e-mail+IP.

## Autenticação

| Método | Rota | Descrição |
|---|---|---|
| POST | `/auth/token` | `{email, password, device_name, code?, recovery_code?}` → `201 {access_token, expires_at, user}`. Com 2FA ativo sem código: `401 {code: two_factor_required}` |
| GET | `/auth/me` | usuário, empresa, filiais acessíveis, permissões (empresa toda) |
| POST | `/auth/logout` | revoga o token atual |
| POST | `/auth/two-factor/setup` | `{password}` → `{secret, otpauth_url, qr_svg}` |
| POST | `/auth/two-factor/confirm` | `{code}` → `{recovery_codes[8]}` (exibidos uma única vez) |
| POST | `/auth/two-factor/disable` | `{password, code}` |

## Clínica (tenant)

| Método | Rota | Permissão |
|---|---|---|
| GET/PATCH | `/company` | `empresa.visualizar` / `empresa.editar` (empresa toda) |
| GET/POST | `/branches` | `filial.visualizar` / `filial.criar` (empresa toda; respeita limite do plano) |
| GET/PATCH | `/branches/{id}` | `filial.visualizar` / `filial.editar` (na filial) |
| PATCH | `/branches/{id}/status` | `filial.desativar` (matriz não pode ser desativada) |
| GET/POST | `/users` | `usuario.visualizar` / `usuario.criar` |
| GET/PATCH | `/users/{id}` | `usuario.visualizar` / `usuario.editar` |
| POST | `/users/{id}/block`, `/users/{id}/unblock` | `usuario.bloquear` |
| PUT | `/users/{id}/roles` | `usuario.perfis` — `{roles: [{role_id, branch_id|null}]}` substitui os vínculos |
| GET | `/permissions` | `perfil.visualizar` — catálogo por módulo |
| GET/POST | `/roles` | `perfil.visualizar` / `perfil.gerenciar` |
| GET/PATCH/DELETE | `/roles/{id}` | `perfil.visualizar` / `perfil.gerenciar` |
| GET | `/audit-logs` | `auditoria.visualizar` — filtros `user_id, branch_id, action (prefixo), auditable_type, auditable_id, result, from, to` |
| GET/POST | `/specialties` | `medico.visualizar` ou `agenda.visualizar` / `especialidade.gerenciar` (`?include_inactive=1`) |
| PATCH | `/specialties/{id}` | `especialidade.gerenciar` |
| GET/POST | `/doctors` | `medico.visualizar` ou `agenda.visualizar` / `medico.gerenciar` — filtros `search, specialty_id, branch_id, status`; corpo `{name, crm, crm_state, cpf?, user_id?, specialties: [{id, rqe?}], branches: [ids]}`; respeita limite `max_doctors` |
| GET/PATCH | `/doctors/{id}` | idem; gestor de filial só altera médicos que atendem exclusivamente nas suas filiais |
| PATCH | `/doctors/{id}/status` | `medico.gerenciar` |
| GET/POST | `/patients` | `paciente.visualizar` / `paciente.criar` — `?search=` (nome sem acento, CPF, telefone, nº prontuário); **CPF mascarado na listagem**. Possível duplicidade → `409 {code: possible_duplicate, candidates}`; reenviar com `confirm_duplicate: true`. Menor sem responsável → `422 guardian_required` |
| GET/PATCH | `/patients/{id}` | `paciente.visualizar` (acesso registrado na auditoria) / `paciente.editar` — `contacts[]` e `insurances[]` substituem as listas |
| PATCH | `/patients/{id}/status` | `paciente.editar` (inativar; nunca excluir) |
| POST | `/patients/{id}/consents` | `paciente.editar` — `{purpose, granted, channel, notes?}` (finalidades em `config/consents.php`) |
| GET | `/patients/{id}/export` | `paciente.exportar` — dados do titular (LGPD), auditado |
| POST | `/patients/{id}/anonymize` | `paciente.anonimizar` (empresa toda) — `{reason, confirm: true}`; irreversível |

### Agenda e fila (Fase 4)

| Método | Rota | Permissão / notas |
|---|---|---|
| GET | `/availability/slots` | `agenda.visualizar` — `doctor_id, branch_id, date_from, date_to?, service_id?, only_free?`; por período: capacidade, ocupados, encaixes e cada horário (`free, booked, blocked, past, full` + motivo) |
| GET | `/availability/next` | `agenda.visualizar` — `branch_id` + `doctor_id` **ou** `specialty_id`, `limit` — próximos horários livres (base para a IA) |
| GET/POST | `/appointments` | `agenda.visualizar` / `agenda.criar` — `{doctor_id, branch_id, patient_id, starts_at, service_id?, is_overbook?, payer_type?, patient_insurance_id?, channel?, notes?, idempotency_key?}`. Erros: `409 slot_booked/slot_taken`, `422 slot_full, slot_blocked, off_grid, no_schedule, past_time, patient_conflict, overbook_limit, insurance_*`, `403 overbook_forbidden`. Repetição com a mesma `idempotency_key` devolve `200` com o mesmo agendamento |
| GET | `/appointments/{id}` | `agenda.visualizar` |
| POST | `/appointments/{id}/confirm` · `/no-show` · `/reschedule` | `agenda.editar` — remarcação `{starts_at, doctor_id?, branch_id?}` mantém o protocolo |
| POST | `/appointments/{id}/cancel` | `agenda.cancelar` — `{reason}`; libera o horário |
| POST | `/appointments/{id}/arrive` | `fila.gerenciar` — registra chegada e **emite a senha** (`ticket_type?`, sugerido automaticamente: 60+ → prioridade) |
| GET/POST/PATCH | `/doctors/{id}/schedule-templates`, `/doctors/{id}/services`, `/schedule-blocks`, `/holidays`, `/rooms` | `agenda.configurar` — bloqueio retorna `affected_appointments` |
| GET | `/queue` | `fila.visualizar` — fila do dia da unidade (`X-Branch-Id`) |
| POST | `/queue/tickets`, `/queue/call-next`, `/queue/tickets/{id}/call`, `/recall`, `/start`, `/finish`, `/skip`, `/transfer` | `fila.gerenciar` — início/fim da senha atualizam o agendamento (em atendimento/realizado) |
| GET | `/painel/{token}/estado` (fora de `/api`) | público com token secreto da unidade — dados mínimos para a TV |

### Prontuário, triagem e bases clínicas (Fase 5)

| Método | Rota | Permissão / notas |
|---|---|---|
| GET | `/encounters?patient_id=` | `prontuario.visualizar` — atendimentos finalizados do paciente (diagnósticos da versão vigente) |
| POST | `/encounters` | `prontuario.editar` — `{appointment_id}` ou `{patient_id, branch_id}` (avulso). Somente usuário vinculado a médico ativo (`403 not_a_doctor`); agendamento de outro médico → `403 not_your_patient`. Repetir devolve o mesmo atendimento. Rascunho pré-preenchido pela triagem do dia |
| GET | `/encounters/{id}` | `prontuario.visualizar` — rascunho (se houver), todas as versões com hash e `integrity {ok, broken_at}`. Acesso auditado (`medical_record.viewed`) |
| PUT | `/encounters/{id}/draft` | `prontuario.editar` — `{revision, data}`; devolve `{revision, saved_at}`. Revisão desatualizada → `409 stale_draft`; finalizado → `409 already_finalized`; outro médico → `403 not_author` |
| POST | `/encounters/{id}/finalize` | `prontuario.finalizar` — `{revision?, data?}`; exige queixa principal e conduta (`422 incomplete_record`) |
| POST | `/encounters/{id}/addenda` | `prontuario.editar` — `{reason (≥10), data}` → nova versão completa |
| GET/POST | `/triages` | `triagem.visualizar`/`triagem.registrar` — sinais vitais + `risk` (vermelho…azul); imutável |
| GET/POST | `/patients/{id}/allergies` | visualizar: prontuário ou triagem; registrar: `prontuario.editar` ou `triagem.registrar` (`422 duplicate_allergy`) |
| GET | `/cid?q=` · `/medications?q=` | busca (favoritos/mais usados primeiro); medicamentos indicam `control_type` e `controlled` |

`data` aceita: `chief_complaint, history, past_history, medications_in_use, vital_signs, physical_exam,
assessment, conduct, exam_requests, guidance, notes` (texto), `return_in_days` e
`diagnoses: [{cid_code_id, is_primary?, notes?}]` (código/descrição copiados da base; exatamente um principal).
Campos desconhecidos são descartados.

### Documentos médicos (Fase 6)

| Método | Rota | Permissão / notas |
|---|---|---|
| POST | `/documents` | por tipo: `prescription` → `receita.emitir`; `certificate` → `atestado.emitir`; `exam_request` → `exame.solicitar`; `report` → `prontuario.editar`. Somente médico ativo (`403 not_a_doctor`). Comuns: `patient_id`, `encounter_id?` (do próprio médico), `branch_id?`. Devolve **lista** (uma receita pode virar até 3 documentos do mesmo `group_id`) |
| | prescription | `items[]: {medication_id? \| name, quantity, posology, route?, control_type?, notification_number?}`, `notes?`. Erros: `notification_required` (listas A/B/C2/C3), `quantity_required` (controle especial), `incomplete_item` |
| | certificate | `subtype: leave` (`days`, `start_date?`) ou `attendance` (`start_time`, `end_time`); `cid_code_id?` + `cid_authorized` (`cid_not_authorized`), `purpose?`, `notes?`; `invalid_start_date` |
| | exam_request | `exams[]`, `indication?`, `cid_code_id?`, `urgent?` |
| | report | `subtype: report \| declaration \| referral`, `title?`, `recipient?`, `body` |
| GET | `/documents?patient_id=` · `/documents/{id}` | permissão de impressão do tipo; `show` traz `content` e `intact` (selo HMAC) |
| POST | `/documents/{id}/cancel` | permissão de cancelamento do tipo + ser o médico emitente (`403 not_author`); `{reason ≥ 10}`; `409 already_cancelled` |
| GET | `/documents/{id}/pdf?format=a4\|a5` | PDF (conta como impressão; cancelado → `409 document_cancelled`) |
| GET | `/validar/{código}` (fora de `/api`, público) | validação pelo código/QR: tipo, número, data, médico, situação, iniciais do paciente, itens da receita |

### Financeiro (Fase 7) — valores sempre em centavos

| Método | Rota | Permissão / notas |
|---|---|---|
| GET/POST | `/receivables` | `financeiro.visualizar\|caixa.operar` / `financeiro.editar\|caixa.operar` — `{branch_id, category_id, patient_id?, description, amount_cents, due_date}` |
| POST | `/receivables/{id}/receive` | `caixa.operar\|financeiro.editar` — `{method, amount_cents, discount_cents?, card_installments?, card_brand?, authorization_code?, paid_on?, terminal_split?}` (`terminal_split`: venda na maquininha Cielo com split — `terminal_split_invalid`, `terminal_split_unavailable`). Erros: `cash_session_required`, `amount_exceeds_balance`, `discount_forbidden` (403), `not_receivable` (409) |
| POST | `/receivables/{id}/cancel` | `financeiro.editar` — só sem recebimentos (`has_payments`) |
| GET/POST | `/payables` | `financeiro.visualizar` / `financeiro.editar` — `installments` gera N parcelas mensais |
| POST | `/payables/{id}/pay` · `/cancel` | `financeiro.editar` |
| POST | `/transactions/{id}/reverse` | `pagamento.estornar` — `{reason ≥ 10}`; `already_reversed` (409), `reversal_of_reversal` |
| GET | `/cash-sessions/current` | `caixa.operar` — caixa aberto do usuário + resumo por forma (`cash_expected`) |
| POST | `/cash-sessions` · `/{id}/movements` · `/{id}/close` | `caixa.operar` — abrir `{branch_id, opening_cents}`; sangria/suprimento `{kind: withdrawal\|deposit, amount_cents, reason}`; fechar `{declared: {cash: …, pix: …}}` (fechamento cego) |
| POST | `/cash-sessions/{id}/review` | `caixa.conferir` — outra pessoa; `notes` obrigatório se houver diferença |
| GET | `/finance/summary?from=&to=&branch_id=` | fluxo de caixa: entradas, saídas, por forma, categoria e dia |

### Cobranças online (Fase 8)

| Método | Rota | Permissão / notas |
|---|---|---|
| POST | `/receivables/{id}/charges` | `pagamento.cobrar` — header **`Idempotency-Key`** (16–64); `{billing_type: pix\|boleto\|credit_card\|undefined, amount_cents, due_date, gateway_id?}`. Mesma chave → `200` com a mesma cobrança. Resposta inclui `public_url`, `payment_url`, `pix_payload`, `test_mode` (`MOCK`/`SANDBOX`/`null`) |
| GET | `/charges/{id}` | `pagamento.visualizar\|pagamento.cobrar` |
| POST | `/charges/{id}/sync` | consulta o gateway e aplica a situação |
| POST | `/charges/{id}/cancel` · `/refund` | `pagamento.cobrar` · `pagamento.estornar` |
| POST | `/webhooks/pagamentos/{gateway_id}` (fora de `/api`, público) | autenticado pelo token do gateway; `401` sem token; eventos repetidos → `200 "evento já recebido"` |

Gateway `cielo_api` (split Cielo): só `billing_type: credit_card` (`invalid_billing_type`); o pagamento é
feito pelo paciente na `public_url` (cartão tokenizado no navegador; rotas públicas `GET /pagar/{token}/cartao/sessao`
e `POST /pagar/{token}/cartao` recebem apenas o `PaymentToken`).

Situações: `pending`, `paid`, `overdue`, `cancelled`, `refunded`, `failed`, `review` (valor divergente,
duplicidade, chargeback — exige conferência).

### Convênios (Fase 9)

| Método | Rota | Permissão / notas |
|---|---|---|
| GET | `/insurers` | `convenio.*` — convênios ativos com planos e médicos credenciados |
| GET | `/procedures?q=` | `convenio.*` — procedimentos ativos (código/descrição) |
| GET | `/insurance/price?insurer_id=&plan_id=&procedure_id=&date=` | `convenio.*` — valor vigente (`price_cents`, `requires_authorization`, coparticipação) ou `null` |
| GET/POST | `/insurance/authorizations` | `convenio.autorizar\|convenio.faturar` — `{patient_id, patient_insurance_id, branch_id, procedure_id, doctor_id?, quantity?}` |
| POST | `/insurance/authorizations/{id}/decision` | `{decision: authorized\|denied, password?, operator_guide_number?, valid_until?, denial_reason?}` — `authorization_decided` (409) |
| GET | `/insurance/guides` · `/insurance/guides/{id}` | `convenio.faturar` — detalhe com itens e `issues` (pendências) |
| POST | `/insurance/guides/{id}/ready` | `guide_incomplete`, `guide_locked` (409) |
| GET | `/insurance/batches` · `/{id}` · `/{id}/xml` | `convenio.faturar` — XML TISS (ISO-8859-1) |

Agendamento por convênio (`payer_type: insurance`) passa a retornar `insurance_expired`, `insurer_inactive`,
`doctor_not_credentialed`. Montagem/fechamento de lote, retorno e glosas são feitos pela interface web.

## Plataforma (Super Admin)

| Método | Rota | Descrição |
|---|---|---|
| GET | `/platform/health` | banco, migrations, cache, storage, fila (`200 ok` / `503 degraded`) |
| GET | `/platform/metrics` | empresas por status, MRR, usuários, falhas de login 24 h, jobs com falha |
| GET/POST | `/platform/companies` | lista / provisiona (empresa + matriz + perfis + admin) |
| GET/PATCH | `/platform/companies/{id}` | detalhes / status e plano (suspender revoga tokens e sessões) |
| GET/POST/PATCH | `/platform/plans[/{id}]` | planos e limites |

## Exemplo

```bash
TOKEN=$(curl -s -X POST http://localhost:8080/api/v1/auth/token \
  -H 'Content-Type: application/json' \
  -d '{"email":"admin@demo.aivexa.local","password":"Demo@12345","device_name":"cli"}' | jq -r .access_token)

curl -s http://localhost:8080/api/v1/branches -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json'
```

## Versionamento e webhooks

Mudanças incompatíveis → `/api/v2`. Webhooks de saída (Fase 8): eventos `appointment.*`,
`payment.*`, `patient.created`, `consultation.finished`, `document.created`, assinados com
HMAC-SHA256 (`X-Aivexa-Signature`), `X-Aivexa-Event-Id` para idempotência e retry exponencial.
