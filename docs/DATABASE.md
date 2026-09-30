# Banco de dados

**Produção: MySQL 5.7.8+ / MariaDB 10.3+ (HostGator).** Também compatível com PostgreSQL 13+ (VPS).
Charset `utf8mb4_unicode_ci`. Chaves primárias **ULID** (`char(26)`), datas `DATETIME` em UTC
(sem o limite de 2038 do `TIMESTAMP`; exibição no fuso da filial), valores monetários em
**centavos (bigint)**, exclusão lógica (`deleted_at`) em cadastros e **nunca** exclusão física de
dados clínicos.

### Técnicas portáveis de integridade

| Necessidade | PostgreSQL puro | Solução portável adotada |
|---|---|---|
| Único apenas entre não excluídos | índice parcial | coluna gerada `CASE WHEN deleted_at IS NULL THEN … END` + `UNIQUE` (NULLs não colidem) |
| E-mail único sem diferenciar maiúsculas | índice em `lower(email)` | coluna gerada `active_email = lower(email)` + `UNIQUE` |
| Um único "empresa toda" por usuário/perfil | `UNIQUE NULLS NOT DISTINCT` | coluna gerada `branch_key = COALESCE(branch_id,'*')` |
| Mesma empresa entre tabelas | FK composta | FK composta (InnoDB suporta) |
| Auditoria imutável | trigger | cadeia HMAC (sempre) + trigger quando houver privilégio |
| Sem dupla marcação na agenda (Fase 4) | exclusion constraint | índice único (médico, início do slot, ativo) + `SELECT … FOR UPDATE` na grade do dia |

Observação MariaDB: colunas `CHAR` não podem ser usadas diretamente em colunas geradas — por isso
as expressões usam `RTRIM(coluna)`.

## Tabelas implementadas (Fases 1–2)

| Tabela | Descrição | Integridade |
|---|---|---|
| `saas_plans` | Planos comerciais: preços, trial, `limits` (JSONB: max_users, max_branches, max_doctors, storage_mb, ai_enabled, whatsapp_enabled) | `code` único |
| `companies` | Empresa cliente (tenant): razão social, CNPJ, slug, status (`trial/active/suspended/cancelled`), plano, `settings` JSONB | CHECK de status; CNPJ único entre ativas (índice parcial) |
| `branches` | Matriz e filiais | `UNIQUE(company_id, code)`; **uma matriz por empresa** (índice parcial); `UNIQUE(company_id, id)` alvo de FKs compostas |
| `users` | Equipe e Super Admin. Segredo 2FA e códigos de recuperação criptografados; controle de bloqueio | CHECK: super admin ⇔ `company_id IS NULL`; e-mail único case-insensitive (`lower(email)`) |
| `permissions` | Catálogo global sincronizado de `config/permissions.php` | `key` único; `scope` tenant/platform |
| `roles` | Perfis por empresa (`is_system`, `is_locked`) | `UNIQUE(company_id, key)` |
| `role_permission` | N:N perfil × permissão | PK composta |
| `user_role_assignments` | Usuário × perfil × filial (NULL = empresa toda) | **FKs compostas** `(company_id, user_id/role_id/branch_id)`; `UNIQUE NULLS NOT DISTINCT (user_id, role_id, branch_id)` |
| `audit_logs` | Trilha de auditoria (`prev_hash`, `hash` HMAC) | cadeia criptográfica por empresa; trigger de bloqueio quando o banco permitir; sem FKs |
| `audit_chain_heads` | Topo da cadeia por empresa (lock serializa as inserções) | PK `scope` |
| `personal_access_tokens` | Tokens de API (Sanctum) com `expires_at` | token hash único |
| `sessions`, `cache`, `jobs`, `failed_jobs`, `job_batches`, `password_reset_tokens` | Infraestrutura | |

### Fase 3 — cadastros clínicos

| Tabela | Descrição | Integridade |
|---|---|---|
| `company_sequences` | Sequências por empresa (nº de prontuário) | PK `(company_id, name)`; incremento sob `SELECT … FOR UPDATE` |
| `specialties` | Especialidades da clínica (padrões criados no provisionamento) | `UNIQUE(company_id, name)` |
| `doctors` | Médicos: CRM/UF, CPF, contato, apresentação, vínculo opcional com `users` | CRM/UF único por empresa entre não excluídos; um usuário ↔ no máximo um médico; FK composta para `users` |
| `doctor_specialty` | Médico × especialidade (+ RQE) | FKs compostas (mesma empresa) |
| `doctor_branch` | Unidades onde o médico atende | FKs compostas |
| `patients` | Paciente: nº de prontuário, nome civil/social, CPF, RG, CNS, nascimento, sexo, identidade de gênero, mãe, contatos, endereço, `search_name` normalizado, status, anonimização | CPF único por empresa entre não excluídos (NULL permitido); `UNIQUE(company_id, record_number)`; sem exclusão física |
| `patient_contacts` | Responsável legal / emergência / outros | FK composta; CHECK de tipo |
| `patient_insurances` | Carteirinhas (convênio, plano, número, validade, principal) — `insurance_company_id` será ligado na Fase 9 | FK composta |
| `patient_consents` | Consentimentos LGPD (finalidade, versão do termo, canal, quem registrou, IP) — cada concessão/revogação é um novo registro imutável | FK composta |

Índices: `audit_logs(company_id, created_at)`, `(company_id, auditable_type, auditable_id)`,
`(company_id, user_id, created_at)`, `(action, created_at)`; `users(company_id, status)`.

## Modelo alvo (fases seguintes)

Convenção: toda tabela de clínica tem `company_id` + (quando aplicável) `branch_id`, FKs compostas
para garantir mesma empresa, e é consultada via `BelongsToCompany`.

- **Cadastros (restantes):** `rooms` (Fase 4), `patient_documents`, `files` (Fase 6).
- **Agenda:** `schedule_templates` (dia, horário, duração, limite por período, encaixes),
  `schedule_blocks` (férias, feriados, bloqueios), `holidays`, `appointments` com coluna gerada
  `active_slot_key` (médico + início, só para status ativos) **UNIQUE** e reserva feita dentro de
  transação com `SELECT … FOR UPDATE` na grade do médico/dia (impossível marcar dois pacientes no
  mesmo horário, mesmo com requisições simultâneas; limites por período contados sob o mesmo lock),
  `appointment_status_history`, `queue_tickets` (sequência por filial/dia/tipo), `queue_calls`.
- **Clínico:** `medical_records`, `medical_record_versions` (imutável, hash encadeado),
  `vital_signs`, `diagnoses`, `cid_codes` (versão da tabela CID), `medications`,
  `prescriptions`, `prescription_items`, `medical_certificates`, `exam_requests`,
  `issued_documents` (snapshot + hash do documento emitido/impresso).
- **Convênios:** `insurance_companies`, `insurance_plans`, `insurance_price_tables`,
  `insurance_procedures`, `insurance_authorizations`.
- **Financeiro:** `financial_accounts`, `accounts_receivable`, `accounts_payable`,
  `cash_registers`, `cash_movements`, `cash_closings` (esperado × informado × diferença ×
  justificativa), `payments`, `payment_transactions`, `payment_splits`, `split_rules`,
  `payouts`, `payment_links`, `bank_accounts`, `bank_transactions`, `bank_reconciliations`,
  `monthly_closings`.
- **Comunicação/IA:** `notifications`, `message_templates`, `messages`,
  `whatsapp_conversations`, `ai_conversations`, `ai_messages`, `ai_tool_calls`, `ai_usage`.
- **Integrações:** `integration_credentials` (criptografado), `webhook_endpoints`,
  `webhook_deliveries` (retry/idempotência), `inbound_webhook_events` (`provider_event_id` único).
- **SaaS:** `subscriptions`, `subscription_invoices`, `usage_counters`.

## Migrations

```bash
php artisan migrate            # aplica
php artisan migrate:status
php artisan aivexa:permissions:sync --roles   # após alterar o catálogo
```

Migrations nunca são editadas depois de publicadas em produção — sempre uma nova migration.
