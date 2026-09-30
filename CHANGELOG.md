# Changelog

Formato: [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/) · versionamento semântico.

## [0.3.0] — 2026-09-30 — Produção na HostGator (Plano Turbo, hospedagem compartilhada)

### Alterado
- Banco de produção passa a ser **MySQL 5.7.8+/MariaDB 10.3+**; migrations portáveis (colunas geradas
  + índices únicos no lugar de índices parciais, `DATETIME` UTC, JSON). PostgreSQL continua suportado.
- Dependências travadas para **PHP 8.3** (`config.platform`).
- Cache, sessões e filas no banco; fila processada pelo **cron** (`schedule:run` a cada minuto).
- Hash de senha automático: argon2id quando disponível, senão bcrypt.
- Docker de desenvolvimento usa MariaDB com as mesmas restrições da hospedagem (binlog, sem SUPER).

### Adicionado
- **Instalador web** `/instalar` (sem SSH): token obrigatório, gera `APP_KEY`, verifica servidor e banco,
  cria clínica, administrador e Super Admin, e se autodesativa.
- **Cadeia criptográfica HMAC** na auditoria (`aivexa:audit:verify`, verificação diária) — protege a
  trilha mesmo quando o MySQL compartilhado não permite triggers.
- `docs/HOSTGATOR.md`, `.htaccess` endurecido, `deploy/hostgator/` (pacote `.zip` com vendor e
  `.htaccess` para o caso `public_html`), artefato de release no CI.
- Saúde do sistema: estado da proteção da auditoria e alerta de fila parada (cron ausente).
- CI em matriz MariaDB 10.6 + PostgreSQL 16 com PHP 8.3. 71 testes.

## [0.2.0] — 2026-09-30 — Fases 1 e 2

### Adicionado
- Arquitetura modular (Laravel 13, PHP 8.4, PostgreSQL 16) e documentação completa em `docs/`.
- Multi-tenant com `TenantContext`, escopo que falha fechado, bloqueio de escrita entre empresas e FKs compostas no banco.
- Autenticação web (sessão) e API (tokens Sanctum com expiração), 2FA TOTP com anti-replay e códigos de recuperação, rate limit, bloqueio progressivo, troca obrigatória de senha, Argon2id.
- RBAC granular com escopo por filial, catálogo único de permissões, perfis padrão, proteção contra escalonamento de privilégio e manutenção de ao menos um administrador.
- Empresas (Super Admin): provisionamento, planos SaaS com limites, suspensão com revogação de acesso, métricas e saúde do sistema.
- Filiais, usuários, perfis, configurações da clínica, auditoria (web + API REST v1 + OpenAPI).
- Auditoria append-only (trigger) com antes/depois, IP, request-id e preservação de negações em rollback.
- Headers de segurança e CSP estrita; interface responsiva com tema claro/escuro e identidade AivexaClínica.
- Infraestrutura de impressão: layouts A4 e térmica 58/80 mm com página de teste.
- Instalador `aivexa:install`, `aivexa:permissions:sync`, seeders (planos, demo fictícia).
- Docker Compose (app, nginx, postgres, redis, fila, scheduler, mailpit) e CI (Pint, composer audit, testes).
- 66 testes automatizados contra PostgreSQL.

### Status da definição de pronto — Fases 1 e 2

| Item | Backend | DB | API | Frontend | Permissões | Auditoria | Testes | Docs | Erros | Segurança |
|---|---|---|---|---|---|---|---|---|---|---|
| Autenticação/2FA | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Multi-tenant | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Empresas/planos | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Filiais | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Usuários/perfis | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Auditoria | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |

### Pendências conhecidas
- Imagem Docker (apenas desenvolvimento) não validada em build neste ambiente (daemon indisponível).
- Recuperação de senha por e-mail ("esqueci minha senha") — entra com a configuração de e-mail transacional.
- Fonte Inter carregada do Google Fonts; considerar hospedar localmente.
