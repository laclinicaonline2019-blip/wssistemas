<p align="center"><img src="public/assets/img/logo-full.webp" width="260" alt="AivexaClínica"></p>

# AivexaClínica

Plataforma **SaaS multiempresa de gestão de clínicas médicas**: matriz e filiais, equipe com
perfis granulares, agenda inteligente, prontuário, receitas e atestados com impressão,
financeiro, pagamentos (ASAAS/Cielo), convênios, portal do paciente e atendimento por IA/WhatsApp,
com segurança, auditoria e LGPD como prioridade.

**Stack:** PHP 8.3+ · Laravel 13 · MySQL/MariaDB (ou PostgreSQL) · JavaScript/CSS próprios (sem build)

**Produção: HostGator Plano Turbo (cPanel)** — pacote `.zip` + instalador web, sem necessidade de
SSH, Docker ou Redis. Guia: [docs/HOSTGATOR.md](docs/HOSTGATOR.md).

## Estado atual — v0.13.0 (Fases 1 a 12 concluídas, pronto para HostGator)

- ✅ Arquitetura modular, banco com integridade multi-tenant, migrations
- ✅ Login web e API (tokens com expiração), **2FA**, rate limit, bloqueio de conta, Argon2id
- ✅ Multi-tenant que falha fechado + FKs compostas no banco
- ✅ RBAC granular por filial com proteção contra escalonamento de privilégio
- ✅ Super Admin: clínicas, planos e limites, suspensão, saúde do sistema
- ✅ Filiais, usuários, perfis de acesso, configurações, **auditoria imutável** (web + API)
- ✅ **Pacientes** (prontuário nº, CPF, responsáveis, convênios, CEP, duplicidade, consentimentos, exportação/anonimização LGPD), **médicos** (CRM/RQE, especialidades, unidades) e **especialidades**
- ✅ Busca global (paciente, CPF, telefone, prontuário, médico) respeitando permissões
- ✅ **Agenda inteligente** (grades, limites por período/dia, encaixes, feriados, bloqueios, valores) **sem dupla marcação** (testado com concorrência real)
- ✅ **Fila e senhas** com impressão térmica e **painel de chamadas para TV** com voz
- ✅ **Prontuário eletrônico** com salvamento automático, **versões imutáveis com hash encadeado**, adendos justificados, CID-10 (importação DATASUS), triagem com classificação de risco, alergias e base de medicamentos com controle (Portaria 344/98)
- ✅ **Receitas** (simples e controle especial em 2 vias, separadas pela Portaria 344/98), **atestados**, **solicitações de exames** e relatórios, com **impressão A4/A5/térmica e PDF**, código de verificação e **QR Code de validação pública**; anexos do paciente em área privada
- ✅ **Financeiro**: contas a receber (geradas na chegada do paciente) e a pagar (parcelas), **caixa por operador com fechamento cego e conferência**, livro imutável com estornos, recibo com valor por extenso, fluxo de caixa e exportação CSV
- ✅ **Pagamentos online** ASAAS e Cielo (SANDBOX/produção) + MOCK identificado: links com PIX/QR Code, webhooks autenticados e idempotentes com **confirmação por consulta à API**, sincronização periódica, tarifas, estornos e **split/repasse médico** (nativo ASAAS, **split Cielo online e na maquininha**, ou interno)
- ✅ **Convênios**: operadoras, planos, credenciamento, procedimentos TUSS, tabelas de valores com vigência, autorizações prévias, guias de consulta e SP/SADT geradas na chegada, atendimento misto (coparticipação), **lotes XML TISS 4.01.00 validados no schema oficial da ANS**, retorno com glosas, recurso e repasse por convênio
- ✅ **Portal do paciente** (celular): login próprio e seguro, consultas, agendamento e cancelamento online, histórico, documentos em PDF, exames liberados pela clínica, pagamentos e recibos
- ✅ **WhatsApp oficial** (Cloud API da Meta) e e-mail: confirmação de agendamento, lembretes 24 h/2 h/personalizados com botões confirmar/cancelar/remarcar, avisos de cancelamento, remarcação e falta, com consentimento LGPD; conversas para a recepção e central de notificações
- ✅ **Recepcionista virtual (IA) no WhatsApp** — **Claude** (padrão, SDK oficial da Anthropic) ou **ChatGPT** (OpenAI), por clínica, com chave própria criptografada; especialidades, médicos, valores, horários livres reais, identificação/cadastro do paciente, **agendamento em duas etapas**, cancelamento e link de pagamento; sem diagnóstico ou prescrição, emergência → SAMU 192, "ATENDENTE" e handoff para a equipe, registro de chamadas/tokens e ações; modo MOCK
- ✅ Layouts de impressão A4 e térmica (58/80 mm) com página de teste
- ✅ Compatível com hospedagem compartilhada: MySQL/MariaDB, filas via cron, instalador web `/instalar`, auditoria com cadeia HMAC
- ✅ Instalador (web e CLI), dados demo fictícios, Docker (dev), CI em MariaDB + PostgreSQL, 193 testes

Próxima fase: **Fase 13** — IA com áudio, imagem e OCR.
Roadmap completo em [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md#15-roadmap-de-desenvolvimento).

## Início rápido

**Produção (HostGator):** siga [docs/HOSTGATOR.md](docs/HOSTGATOR.md).

**Desenvolvimento (Docker):**

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
| [HOSTGATOR.md](docs/HOSTGATOR.md) | **instalação em produção na HostGator (cPanel)** |
| [INSTALL.md](docs/INSTALL.md) · [DEPLOY.md](docs/DEPLOY.md) | instalação de desenvolvimento, ambientes, backup |
| [TESTING.md](docs/TESTING.md) | estratégia e cobertura de testes |
| [INTEGRATIONS.md](docs/INTEGRATIONS.md) · [AI.md](docs/AI.md) | pagamentos, WhatsApp, IA |
| [LGPD.md](docs/LGPD.md) | privacidade e proteção de dados |
| [CHANGELOG.md](CHANGELOG.md) | histórico e definição de pronto |

## Testes

```bash
php artisan test        # MySQL/MariaDB por padrão (ver .env.testing); PostgreSQL também suportado
vendor/bin/pint --test
```

## Licença

Proprietária — todos os direitos reservados.
