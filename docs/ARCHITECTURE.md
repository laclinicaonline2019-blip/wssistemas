# Arquitetura — AivexaClínica

Plataforma SaaS multiempresa de gestão clínica. Este documento responde aos 15 itens do
"primeiro passo" da especificação e registra as decisões técnicas (ADRs resumidos).

> **Ambiente de produção-alvo: HostGator Plano Turbo (hospedagem compartilhada/cPanel).**
> Por isso a produção usa MySQL/MariaDB, cache/sessão/filas no banco, fila processada por cron e
> instalador web. O mesmo código roda em VPS com PostgreSQL/Redis/workers quando a operação crescer.
> Guia: [HOSTGATOR.md](HOSTGATOR.md).

---

## 1. Arquitetura geral

**Monólito modular** (um deploy, módulos com fronteiras claras) — o melhor equilíbrio entre
segurança, custo e velocidade para um produto que ainda vai crescer. Cada módulo pode ser
extraído para um serviço no futuro sem reescrita, porque a comunicação entre eles já passa
por serviços/eventos e não por acesso direto a tabelas de outros módulos.

```
                     ┌───────────────────────────────────────────────────────────┐
  Navegador (web)    │  Laravel 13 / PHP 8.4                                     │
  PWA / celular ───► │  Middleware: RequestId → Headers → Auth → ResolveTenant   │
  API (Bearer) ────► │             → 2FA/senha → permission:<chave> → Bindings   │
  Webhooks ───────►  │                                                           │
                     │  app/Core      Tenancy · Access(RBAC) · Audit · Security  │
                     │  app/Modules   Platform · Organization · Identity · Audit │
                     │                Printing · (Patients · Scheduling · …)     │
                     │  Integrações   Payment/WhatsApp/AI/Storage via interfaces │
                     └──────┬──────────────┬───────────────┬─────────────────────┘
                            │              │               │
                     MySQL/MariaDB     Cache/sessão/    Storage privado
                     (HostGator) ou    filas no banco   (local/S3, URLs
                     PostgreSQL (VPS)  (Redis no VPS)   temporárias)
                            ▲
                     Cron do cPanel (1/min) → schedule:run → fila (WhatsApp, IA, OCR, PDF,
                     webhooks, conciliação), lembretes, cobranças, verificação da auditoria
```

### Estrutura de pastas

```
app/
  Core/                 # infraestrutura transversal (sem regra de negócio de módulo)
    Tenancy/            # TenantContext, BelongsToCompany, CompanyScope, exceções
    Access/             # PermissionRegistry (catálogo), PermissionService (RBAC)
    Audit/              # AuditLogger (append-only), trait Auditable
    Security/           # LoginService, TwoFactorService, PasswordRules
    Health/             # HealthChecker
    Validation/         # Cnpj (e futuramente Cpf, CNS…)
  Modules/<Módulo>/
    Models/  Services/  Http/{Controllers/Api,Controllers/Web,Requests,Resources}
  Http/Middleware/      # ResolveTenant, EnsurePermission, EnsureSuperAdmin, SecurityHeaders…
  Console/Commands/     # aivexa:install, aivexa:permissions:sync
config/permissions.php  # catálogo único de permissões + perfis padrão
database/migrations     # schema PostgreSQL (constraints, FKs compostas, triggers)
resources/views         # Blade (server-side) — telas e layouts de impressão
public/assets           # CSS/JS próprios, sem build obrigatório
tests/{Unit,Feature}    # PHPUnit contra MySQL/MariaDB e PostgreSQL reais
docs/                   # esta documentação
```

Controllers são finos: validam (FormRequest), chamam um Service e devolvem Resource/View.
Regras de negócio e de autorização fina ficam nos Services (reutilizados por web e API).

## 2. Stack tecnológica definitiva

| Camada | Escolha | Justificativa |
|---|---|---|
| Linguagem | **PHP 8.4** (mín. 8.3) | requisito; tipagem moderna, enums, readonly |
| Framework | **Laravel 13** | maduro, seguro por padrão (CSRF, hashing, validação, filas, scheduler, migrations), enorme ecossistema e mão de obra no Brasil |
| Banco | **MySQL 5.7.8+ / MariaDB 10.3+** (HostGator) · PostgreSQL 13+ (VPS) | a HostGator compartilhada oferece MySQL/MariaDB. As garantias de integridade foram mantidas de forma portável: FKs compostas, **colunas geradas + índices únicos** (no lugar de índices parciais), CHECKs, transações e `SELECT … FOR UPDATE`. O CI testa nos dois bancos |
| Cache/filas/sessão | **banco de dados** (HostGator) · Redis (VPS) | sem processos permanentes na hospedagem compartilhada: a fila é processada pelo cron a cada minuto |
| Front-end | **Blade + JavaScript puro + CSS próprio** | sem etapa de build, compatível com CSP estrita (`script-src 'self'`), rápido em máquinas modestas de recepção; componentes interativos maiores (agenda, fila) poderão usar módulos ES sem mudar a arquitetura |
| API | REST `/api/v1` + **Sanctum** (tokens Bearer com expiração) | apps, integrações e portal do paciente |
| 2FA | TOTP RFC 6238 (`pragmarx/google2fa`) + códigos de recuperação | |
| Impressão | CSS `@page` (A4 e térmica 58/80 mm); PDF server-side (Fase 6) | ver §16 |
| Infra | **cPanel/Apache (HostGator)** com pacote `.zip` e instalador web; Docker (php-fpm + nginx + MariaDB) para desenvolvimento; GitHub Actions | |
| PHP | dependências travadas para **PHP 8.3** (`config.platform`) | compatível com a versão disponível no cPanel |

## 3. Diagrama dos módulos

```
Plataforma (Super Admin) ── Planos SaaS ── Assinaturas/limites
        │
     Empresa ─┬─ Filiais ─┬─ Salas
              │           ├─ Caixa ─── Conferência
              │           └─ Fila/Senhas ── Painel de chamadas
              ├─ Usuários ── Perfis ── Permissões (RBAC por filial)
              ├─ Médicos ── Especialidades ── Grade/Agenda ── Agendamentos
              ├─ Pacientes ── Documentos/Exames ── Consentimentos (LGPD)
              │        └── Prontuário (versões imutáveis) ── CID ── Medicamentos
              │                 └── Receitas / Atestados / Solicitações → Impressão/PDF
              ├─ Convênios ── Planos ── Tabelas ── Autorizações
              ├─ Financeiro ── Receber/Pagar ── Pagamentos ── Split/Repasses ── Conciliação
              ├─ Comunicação ── WhatsApp ── Notificações ── Lembretes
              ├─ IA ── Recepcionista (agendamento) ── Assistente clínico (revisão humana)
              └─ Auditoria (transversal, append-only)
```

## 4. Modelo de dados

Ver [DATABASE.md](DATABASE.md) — inclui as tabelas já criadas e o modelo alvo de todas as fases.

## 5. Estratégia multi-tenant

**Banco compartilhado, esquema compartilhado, coluna `company_id`** em toda tabela de clínica —
escala para milhares de clínicas com um único schema e migrations simples.

Defesa em profundidade, em camadas:

1. **Contexto derivado do backend.** `ResolveTenant` monta o `TenantContext` a partir do usuário
   autenticado. O frontend só *sugere* a filial (header `X-Branch-Id` / sessão) e ela é validada
   contra os vínculos do usuário.
2. **Escopo global que falha fechado.** Models com `BelongsToCompany` recebem
   `WHERE company_id = ?` automaticamente; **sem contexto a consulta lança exceção**
   (`TenantContextMissing`) em vez de retornar dados de todas as clínicas.
3. **Escrita protegida.** `company_id` é preenchido pelo contexto, valores divergentes geram
   `CrossTenantViolation`, e o campo é imutável.
4. **Route model binding depois do tenant.** `ResolveTenant` roda antes de `SubstituteBindings`,
   então `/branches/{id}` de outra empresa resulta em **404** (não revela existência).
5. **Integridade no banco.** FKs compostas `(company_id, x_id) → x(company_id, id)` impedem,
   mesmo por SQL direto, vincular perfil/filial/usuário de empresas diferentes.
6. **Jobs/webhooks** executam com `TenantContext::runFor($companyId, …)`; rotinas globais com
   `runAsSystem()` (uso restrito e explícito).
7. **Evolução opcional (somente VPS/PostgreSQL):** Row Level Security como barreira adicional.

Exceção documentada: o escopo de `users` não falha fechado sem contexto, pois a autenticação
precisa localizar o usuário antes de existir tenant (ver `TenantUserScope`).

## 6. Estratégia de autenticação

- **Web:** sessão (cookie `HttpOnly`, `SameSite=Lax`, `Secure` em produção, sessão criptografada,
  expiração por inatividade de 60 min, regeneração do ID no login).
- **API:** Sanctum — tokens Bearer com **expiração** (padrão 12 h; `public/.htaccess` repassa o header `Authorization` no Apache), revogação no logout, na troca
  de senha, no bloqueio do usuário e na suspensão da clínica.
- **Senhas:** Argon2id quando o PHP do servidor suporta, senão bcrypt (detecção automática; rehash no login); política mínima (10+, maiúsc./minúsc./números/símbolos); opção de
  verificação em base de senhas vazadas (HIBP k-anonymity); troca obrigatória no 1º acesso.
- **2FA TOTP** com proteção contra replay e 8 códigos de recuperação de uso único (armazenados
  como HMAC); obrigatório para Super Admin e configurável por clínica para todos.
- **Anti força bruta:** rate limit por e-mail+IP, bloqueio progressivo da conta, mensagens neutras
  (sem enumeração de usuários) e custo de hash equalizado para e-mails inexistentes.
- **Portal do paciente (Fase 10):** guard separado (`patient`) e tabela própria de contas — o
  paciente nunca compartilha a tabela `users` da equipe.
- **Futuro:** OAuth2 (Laravel Passport) para integrações de terceiros e SSO para redes.

## 7. Estratégia de permissões (RBAC)

- Catálogo único em `config/permissions.php` (chaves granulares como `agenda.criar`,
  `receita.emitir`), sincronizado por `php artisan aivexa:permissions:sync`.
- Perfis por empresa, criados a partir de templates (admin da empresa, admin da filial, médico,
  recepção, financeiro, enfermagem). O perfil de administrador é **protegido**.
- **Vínculo com escopo:** `user_role_assignments(user, role, branch_id|NULL)` — NULL = empresa toda.
  A mesma pessoa pode ser médica na filial A e gestora na filial B.
- Verificação sempre no backend: middleware `permission:chave` + `Gate::before` + checagens nos
  Services (ex.: editar *esta* filial).
- **Anti-escalonamento:** só se concede perfil cujas permissões o ator possui naquela filial;
  ninguém altera os próprios perfis; gestor de filial só gerencia usuários exclusivamente da sua
  filial; a empresa mantém ao menos um administrador ativo.
- Super Admin tem apenas permissões de plataforma (**não** acessa dados clínicos).
- Toda negação gera evento `access.denied` na auditoria (inclusive dentro de transações desfeitas).

## 8. Estratégia de segurança

Resumo (detalhes em [SECURITY.md](SECURITY.md)): HTTPS/HSTS, CSP estrita sem scripts inline,
`X-Frame-Options: DENY`, CSRF, escape automático (XSS), queries parametrizadas (Eloquent/Query
Builder — nenhum SQL concatenado com entrada), validação server-side em FormRequests, uploads em
storage privado com validação de tipo real e antivírus (ClamAV) nas fases de documentos, segredos
só via ambiente, logs com `X-Request-Id`, auditoria imutável, backups criptografados, menor privilégio.

## 9. Estratégia de pagamentos

```
PaymentProviderInterface
 ├─ AsaasProvider        (PIX, cartão, boleto, split nativo, webhooks)
 ├─ CieloProvider        (cartão/PIX via API Cielo 3.0)
 └─ MockPaymentProvider  (MOCK, identificado na interface e nos registros)
```

- Provedor escolhido por clínica; credenciais por clínica **criptografadas** no banco
  (`integration_credentials`), nunca no código.
- Estados da cobrança só mudam por **webhook autenticado** (token/assinatura) **+ consulta de
  confirmação na API do gateway**. "O paciente disse que pagou" nunca confirma nada.
- **Idempotência:** chave única por cobrança (`idempotency_key`) e por evento de webhook
  (`provider_event_id` UNIQUE) — webhook repetido não duplica baixa; clique duplo não duplica cobrança.
- **Split:** regras (percentual/fixo, por médico/procedimento/convênio) geram `payment_splits`;
  quando o gateway suporta split nativo (ASAAS), ele é usado para que o dinheiro já caia separado.
  Valores sempre em centavos (inteiros), soma das partes = valor líquido (constraint + teste).
- Modos: `mock | sandbox | production` por integração, sempre visíveis na interface.

## 10. Estratégia de IA

Ver [AI.md](AI.md). Pontos-chave: provedor desacoplado (`AiProviderInterface`), **ferramentas
(tool use) em vez de texto livre** — a IA agenda chamando `check_availability`/`book_appointment`
do próprio sistema, que aplica as mesmas regras de agenda, limites e locks dos humanos; ela nunca
"inventa" horário. Conteúdo clínico gerado por IA é sempre rascunho que exige revisão humana.
Handoff para humano, logs completos, limites de consumo por plano.

## 11. Estratégia de WhatsApp

WhatsApp Business **Cloud API oficial (Meta)**. `MessagingChannelInterface` com adapters
`WhatsAppCloudChannel`, `MockChannel` (e SMS/e-mail). Webhook de entrada valida
`X-Hub-Signature-256` com o App Secret, enfileira o processamento (resposta 200 imediata) e é
idempotente pelo `message_id`. Envio proativo apenas com templates aprovados e respeitando a janela
de 24 h e o opt-in do paciente. Detalhes em [INTEGRATIONS.md](INTEGRATIONS.md).

## 12. Estratégia de armazenamento

`StorageInterface` sobre o Flysystem do Laravel: disco `local` (privado, fora de `public/`) em
dev e **S3 compatível** (AWS S3, MinIO, Wasabi) com criptografia server-side em produção.
Documentos clínicos: nunca em disco público; download apenas via controller autorizado
(permissão + tenant + auditoria `document.accessed`) ou **URL temporária assinada** (≤ 5 min).
Nome físico aleatório (ULID), hash SHA-256 para integridade, validação de MIME real e antivírus.

## 13. Estratégia de auditoria

`audit_logs` **append-only** com duas proteções: (1) **cadeia criptográfica HMAC-SHA256 por
empresa** — cada registro assina seu conteúdo e o hash do anterior, então qualquer alteração,
exclusão ou inserção fora da aplicação é detectada por `aivexa:audit:verify` (executado diariamente
pelo cron); (2) **trigger** que bloqueia UPDATE/DELETE no banco quando o servidor permite (VPS;
em hospedagem compartilhada o MySQL costuma negar triggers sem privilégio SUPER). Registra
usuário, empresa, filial, data/hora, IP, user-agent, request-id, ação, registro, valores antes/
depois e resultado (`success|failure|denied`). Segredos são mascarados. Eventos de models via
trait `Auditable`; eventos de segurança explícitos. Negações sobrevivem a rollbacks. Painel com
filtros e exportação CSV (também auditada). Documentos clínicos emitidos terão ainda
versionamento imutável com hash encadeado (Fase 5/6).

## 14. Estratégia de testes

Ver [TESTING.md](TESTING.md). PHPUnit contra **MySQL/MariaDB e PostgreSQL reais** (constraints, cadeia de auditoria e locks
fazem parte do que se testa), testes específicos para isolamento de tenant, escalonamento de
privilégio, autenticação/2FA, auditoria e segurança. CI no GitHub Actions com Pint + `composer
audit` + testes. Concorrência (agenda, cobranças) com testes multi-processo a partir da Fase 4.

## 15. Roadmap de desenvolvimento

| Fase | Entrega | Status |
|---|---|---|
| 1 | Arquitetura, banco, autenticação (web/API/2FA), multi-tenant, RBAC, auditoria, segurança base, Docker, CI, instalador | **Concluída** |
| 2 | Empresas, filiais, usuários, perfis/permissões (web + API), Super Admin, planos e limites | **Concluída** |
| 3 | Pacientes (CPF, responsável, convênio, consentimentos, histórico, exportação/anonimização LGPD), médicos, especialidades, busca global | **Concluída** |
| 4 | Agenda (grades, limites por período, encaixes, bloqueios, feriados; anti-dupla-marcação por índice único de horário + lock transacional), fila/senhas, painel de chamadas, salas | **Concluída** |
| 5 | Prontuário (versões imutáveis com hash encadeado, adendos, autosave), triagem, alergias, CID-10 (importação DATASUS), medicamentos (Portaria 344/98) | **Concluída** |
| 6 | Receitas (Portaria 344/98: simples, controle especial 2 vias, notificação), atestados, exames, relatórios, **impressão A4/A5/térmica e PDF**, QR Code de validação, anexos do paciente, arquitetura de assinatura ICP-Brasil | **Concluída** |
| 7 | Financeiro: contas a receber/pagar, caixa por operador (fechamento cego + conferência), livro imutável com estornos, recibos, fluxo de caixa | **Concluída** |
| 8 | ASAAS e Cielo (SANDBOX/produção) + MOCK, links de pagamento com PIX/QR, webhooks autenticados e idempotentes com confirmação por API, sincronização periódica, tarifas, estornos, split nativo e repasse interno | **Concluída** |
| 9 | Convênios: operadoras, planos, tabelas, autorizações, guias, lotes XML TISS 4.01.00 validados, glosas e recursos | **Concluída** |
| 10 | Portal do paciente | **Concluída** |
| 11 | WhatsApp oficial (Cloud API), lembretes, notificações | **Concluída** |
| 12 | IA recepcionista no WhatsApp (Claude padrão, ChatGPT opcional, MOCK; agendamento com tool use em duas etapas, handoff) | **Concluída** |
| 13 | IA com áudio (transcrição), imagem e PDF (OCR estruturado), conferência humana | **Concluída** |
| 14 | Conciliação bancária: OFX, CSV e Open Finance (Pluggy), sugestões, automática sem ambiguidade, múltiplos lançamentos | **Concluída** |
| 15 | Relatórios (tela, PDF, Excel, CSV) e fechamento mensal médico × clínica com confirmação do médico | **Concluída** |
| 16 | SaaS comercial: assinatura recorrente (ASAAS da plataforma/MOCK), upgrade proporcional, downgrade agendado, régua de atraso e bloqueio | **Concluída** |
| 17 ▶ | Segurança avançada (WAF/Cloudflare, antivírus, pentest; RLS se migrar para PostgreSQL) | Próxima |
| 18–20 | Testes completos, homologação, produção | |

### Definição de pronto (por funcionalidade)

BACKEND · DATABASE · API · FRONTEND · PERMISSIONS · AUDIT · TESTS · DOCUMENTATION ·
ERROR HANDLING · SECURITY — só é DONE com todos. Status das Fases 1–2 em [../CHANGELOG.md](../CHANGELOG.md).

## 16. Impressão (receitas, atestados, senhas, recibos)

- **A4:** layouts Blade com `@page { size: A4; margin: 15mm }`, cabeçalho da unidade, rodapé
  configurável, área de assinatura; impressão pelo navegador (qualquer impressora instalada).
- **PDF (Fase 6):** geração server-side em fila (dompdf/Chromium headless) para envio ao paciente
  e arquivamento imutável do documento emitido (hash registrado).
- **Térmica 58/80 mm:** layout próprio para senhas e comprovantes; opção futura de impressão
  silenciosa via QZ Tray (ESC/POS) para totens de recepção.
- Página de teste de impressão já disponível em *Configurações → Impressão*.
- Assinatura digital: arquitetura preparada para certificado ICP-Brasil (A1/A3/nuvem) — o sistema
  **não atribui validade jurídica** a documentos sem a assinatura adequada.

## Decisões registradas (ADR resumido)

1. Monólito modular > microsserviços no início (custo e consistência transacional).
2. **MySQL/MariaDB em produção (HostGator compartilhada)**, PostgreSQL também suportado; integridade mantida com recursos portáveis e testada nos dois bancos.
3. ULID como chave primária (não sequencial → sem enumeração; ordenável).
4. Escopo de tenant que falha fechado.
5. Front-end sem build obrigatório, CSP estrita.
6. Integrações sempre atrás de interfaces com modo MOCK/SANDBOX/PRODUÇÃO explícito.
7. Hospedagem compartilhada: fila via cron, instalador web, auditoria com cadeia HMAC (sem depender de triggers), dependências para PHP 8.3.
