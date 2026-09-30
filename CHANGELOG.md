# Changelog

Formato: [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/) · versionamento semântico.

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
- Imagem Docker não validada em build neste ambiente (daemon indisponível); validar no primeiro uso.
- Recuperação de senha por e-mail ("esqueci minha senha") — entra com a configuração de e-mail transacional.
- Fonte Inter carregada do Google Fonts; considerar hospedar localmente.
