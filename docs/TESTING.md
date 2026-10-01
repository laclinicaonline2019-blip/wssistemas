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

## Cobertura atual (143 testes; 1–2 ignorados conforme o banco)

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
| `Patients/PatientTest` | nº de prontuário sequencial por empresa, normalização, CPF válido/único por empresa, **duplicidade com confirmação**, **responsável obrigatório para menor**, busca sem acento/CPF/telefone/prontuário, CPF mascarado em listas, acesso auditado, **isolamento entre clínicas**, permissões por perfil, consentimentos imutáveis com histórico, **exportação e anonimização LGPD** (sem PII na trilha), proibição de exclusão física, telas web, CEP via servidor |
| `Doctors/DoctorTest` | especialidades padrão, médico com especialidades/RQE/unidades/usuário, CRM único por empresa, vínculos de outra empresa rejeitados, limite do plano, **gestor de filial restrito às suas unidades**, permissões de especialidades, telas web, busca global respeitando permissões |
| `Scheduling/BookingTest` | disponibilidade real, **mesmo horário não pode ser marcado duas vezes** (serviço e banco), duração que ocupa vários horários, **limite do período** e próximo horário, limite diário, **encaixes** (permissão e limite), feriado, bloqueio, horário passado/fora da grade, conflito do paciente, médico fora da unidade, regras de convênio, cancelamento libera horário, remarcação mantém protocolo, **idempotência**, grades sobrepostas entre unidades, bloqueio lista afetados, isolamento entre clínicas e filiais |
| `Scheduling/ConcurrentBookingTest` | **concorrência real com processos paralelos** (8 × mesmo horário → 1 sucesso; 8 horários × limite 2 → 2 sucessos). Verificado que o teste falha sem o lock |
| `Scheduling/QueueTest` | senha na chegada com tipo sugerido (60+ → prioridade), numeração por dia/tipo, chamar próxima priorizando, rechamar, iniciar/finalizar atualizando o agendamento, **painel com nome reduzido e token** (rotação invalida; token nunca vai à auditoria), isolamento, telas web e impressão |
| `Clinical/EncounterTest` | pré-preenchimento pela triagem, **autosave com revisão (aba antiga não sobrescreve)**, finalização exige conteúdo mínimo, **versão finalizada imutável** (aplicação e trigger), **adendo mantém o original**, **cadeia de hash detecta adulteração no banco**, somente o médico autor, diagnósticos com cópia do texto e um principal, recepção sem acesso, acesso auditado sem conteúdo clínico, isolamento entre clínicas, atendimento avulso |
| `Clinical/ClinicalCatalogTest` | importação da CID-10 no formato DATASUS (ISO-8859-1), busca sem acento/por código, favoritos primeiro, base global de medicamentos somente leitura e cadastros isolados por clínica, importação CSV de medicamentos, triagem imutável/validada, alergia duplicada bloqueada |
| `Clinical/ClinicalWebTest` | fluxo web completo do médico (iniciar → autosave → finalizar → adendo), telas de triagem/medicamentos/plataforma, dados clínicos ocultos para a recepção |
| `Documents/DocumentTest` | **receita separada pela Portaria 344/98** (simples, controle especial com validade, registro de notificação), quantidade/notificação obrigatórias, **documento imutável e adulteração detectada** (inclusive na validação pública), texto do atestado com dias por extenso, **CID só com autorização**, data retroativa bloqueada, permissões por perfil (recepção reimprime mas não emite), **cancelamento só pelo emitente**, contagem de vias, A4/A5/térmica/PDF, térmica proibida para controle especial, validação pública com iniciais, isolamento entre clínicas e atendimento de outro médico |
| `Documents/DocumentWebTest` | emissão web dos 4 tipos (linhas em branco ignoradas, exames por linha), telas, cancelamento, menu por permissão, **anexos privados com tipo detectado pelo conteúdo**, download auditado, arquivar sem apagar, **limite de armazenamento do plano**, isolamento |
| `Unit/*` | CNPJ, mascaramento da auditoria |

## Próximos testes obrigatórios (por fase)

- Pagamentos: confirmação falsa (webhook sem assinatura/valor divergente), **duplicação de cobrança** (idempotência), webhook repetido.
