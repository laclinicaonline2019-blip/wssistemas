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
