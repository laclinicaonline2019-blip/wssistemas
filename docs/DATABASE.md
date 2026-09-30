# Banco de dados

PostgreSQL 16. Chaves primárias **ULID** (`char(26)`), timestamps `timestamptz` (UTC; exibição no
fuso da filial), valores monetários em **centavos (bigint)**, exclusão lógica (`deleted_at`) em
cadastros e **nunca** exclusão física de dados clínicos.

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
| `audit_logs` | Trilha de auditoria | **Trigger bloqueia UPDATE/DELETE/TRUNCATE**; sem FKs (sobrevive a qualquer mudança) |
| `personal_access_tokens` | Tokens de API (Sanctum) com `expires_at` | token hash único |
| `sessions`, `cache`, `jobs`, `failed_jobs`, `job_batches`, `password_reset_tokens` | Infraestrutura | |

Índices: `audit_logs(company_id, created_at)`, `(company_id, auditable_type, auditable_id)`,
`(company_id, user_id, created_at)`, `(action, created_at)`; `users(company_id, status)`.

## Modelo alvo (fases seguintes)

Convenção: toda tabela de clínica tem `company_id` + (quando aplicável) `branch_id`, FKs compostas
para garantir mesma empresa, e é consultada via `BelongsToCompany`.

- **Cadastros:** `specialties`, `doctors` (CRM/UF, `user_id`), `doctor_specialties`, `rooms`,
  `patients` (CPF único por empresa, nome social, responsável, contatos), `patient_contacts`,
  `patient_insurances`, `patient_consents`, `patient_documents`, `files`.
- **Agenda:** `schedule_templates` (dia, horário, duração, limite por período, encaixes),
  `schedule_blocks` (férias, feriados, bloqueios), `holidays`, `appointments`
  com `tstzrange` + **`EXCLUDE USING gist (doctor_id WITH =, period WITH &&) WHERE status ativo`**
  (impossível marcar dois pacientes no mesmo horário, mesmo com requisições simultâneas),
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
