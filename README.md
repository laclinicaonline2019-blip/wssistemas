<p align="center"><img src="public/assets/img/logo-full.webp" width="260" alt="AivexaClínica"></p>

# AivexaClínica

Plataforma **SaaS multiempresa de gestão de clínicas médicas**: matriz e filiais, equipe com
perfis granulares, agenda inteligente, prontuário, receitas e atestados com impressão,
financeiro, pagamentos (ASAAS/Cielo), convênios, portal do paciente e atendimento por IA/WhatsApp,
com segurança, auditoria e LGPD como prioridade.

**Stack:** PHP 8.4 · Laravel 13 · PostgreSQL 16 · Redis · JavaScript/CSS próprios (sem build) · Docker

## Estado atual — v0.2.0 (Fases 1 e 2 concluídas)

- ✅ Arquitetura modular, banco com integridade multi-tenant, migrations
- ✅ Login web e API (tokens com expiração), **2FA**, rate limit, bloqueio de conta, Argon2id
- ✅ Multi-tenant que falha fechado + FKs compostas no banco
- ✅ RBAC granular por filial com proteção contra escalonamento de privilégio
- ✅ Super Admin: clínicas, planos e limites, suspensão, saúde do sistema
- ✅ Filiais, usuários, perfis de acesso, configurações, **auditoria imutável** (web + API)
- ✅ Layouts de impressão A4 e térmica (58/80 mm) com página de teste
- ✅ Instalador, dados demo fictícios, Docker, CI, 66 testes

Próxima fase: pacientes, médicos e especialidades → agenda com anti-dupla-marcação → prontuário.
Roadmap completo em [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md#15-roadmap-de-desenvolvimento).

## Início rápido

```bash
cp .env.example .env
docker compose up -d --build
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan aivexa:install
docker compose exec app php artisan db:seed --class=DemoSeeder   # opcional, dados fictícios
```

Abra http://localhost:8080 (demo: `admin@demo.aivexa.local` / `Demo@12345`). Detalhes em [docs/INSTALL.md](docs/INSTALL.md).

## Documentação

| Documento | Conteúdo |
|---|---|
| [ARCHITECTURE.md](docs/ARCHITECTURE.md) | arquitetura, stack, módulos, estratégias (tenant, auth, RBAC, pagamentos, IA, WhatsApp, storage, auditoria, impressão), roadmap |
| [DATABASE.md](docs/DATABASE.md) | tabelas, constraints e modelo alvo |
| [SECURITY.md](docs/SECURITY.md) | controles implementados e checklist de produção |
| [API.md](docs/API.md) · [openapi.yaml](docs/openapi.yaml) | API REST v1 |
| [INSTALL.md](docs/INSTALL.md) · [DEPLOY.md](docs/DEPLOY.md) | instalação, ambientes, backup |
| [TESTING.md](docs/TESTING.md) | estratégia e cobertura de testes |
| [INTEGRATIONS.md](docs/INTEGRATIONS.md) · [AI.md](docs/AI.md) | pagamentos, WhatsApp, IA |
| [LGPD.md](docs/LGPD.md) | privacidade e proteção de dados |
| [CHANGELOG.md](CHANGELOG.md) | histórico e definição de pronto |

## Testes

```bash
php artisan test        # requer PostgreSQL (ver .env.testing)
vendor/bin/pint --test
```

## Licença

Proprietária — todos os direitos reservados.
