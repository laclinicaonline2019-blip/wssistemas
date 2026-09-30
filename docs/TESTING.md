# Testes

```bash
# MySQL/MariaDB (padrão, igual à produção) — ver .env.testing
php artisan test
# PostgreSQL
DB_CONNECTION=pgsql DB_PORT=5432 DB_USERNAME=postgres DB_PASSWORD= php artisan test
vendor/bin/pint --test     # estilo
composer audit             # vulnerabilidades em dependências
```

Os testes rodam contra **bancos reais** (o CI roda a suíte inteira em MariaDB 10.6 e PostgreSQL 16,
com PHP 8.3): FKs compostas, colunas geradas únicas, cadeia de auditoria e locks fazem parte do
comportamento verificado. Cada teste roda em transação (RefreshDatabase).

## Cobertura atual (71 testes; 1 ignorado conforme o banco)

| Suíte | O que garante |
|---|---|
| `Auth/ApiAuthenticationTest` | token, mensagens neutras, bloqueio por tentativas, rate limit, usuário bloqueado, clínica suspensa, expiração de token, logout, 2FA (anti-replay, recuperação de uso único), Argon2id |
| `Auth/WebAuthenticationTest` | login/logout web, desafio 2FA e expiração, troca de senha obrigatória, senha fraca, 2FA obrigatório pela clínica, Super Admin → painel da plataforma |
| `Tenancy/TenantIsolationTest` | **empresa A não lê/altera/bloqueia nada da B** (404), `company_id` do cliente ignorado, perfis/filiais de outra empresa rejeitados, header de filial alheia, auditoria isolada, escopo falha fechado, imutabilidade de `company_id`, FK composta no banco |
| `Access/PermissionTest` | recepção/médico sem acesso administrativo, gestor de filial restrito à filial, **escalonamento de privilégio bloqueado**, auto-alteração proibida, último admin preservado, perfil protegido, plataforma × clínica, limites do plano, troca de senha inicial |
| `Audit/AuditTrailTest` | usuário/IP/antes/depois/request-id, segredos nunca na trilha, **cadeia HMAC íntegra e detecção de adulteração/exclusão**, trigger append-only (quando o banco permite), filtros e exportação |
| `Platform/PlatformTest` | provisionamento completo, CNPJ inválido, suspensão revoga acesso, health/metrics, instalador CLI idempotente |
| `Platform/WebInstallerTest` | instalador web oculto sem token, token inválido, instalação completa e autodesativação |
| `Security/SecurityHeadersTest` | CSP/headers, no-store, token CSRF, injeção tratada como dado, escape de HTML |
| `Web/WebPagesTest` | todas as telas renderizam, formulários web, menu por permissão, troca de filial, telas da plataforma |
| `Unit/*` | CNPJ, mascaramento da auditoria |

## Próximos testes obrigatórios (por fase)

- Agenda: **dupla marcação concorrente** (processos paralelos contra o índice único + lock), limites por período/encaixe.
- Pagamentos: confirmação falsa (webhook sem assinatura/valor divergente), **duplicação de cobrança** (idempotência), webhook repetido.
- Prontuário: alteração indevida (versões imutáveis, somente autor/perfil permitido), sem exclusão física.
