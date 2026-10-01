# Changelog

Formato: [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/) · versionamento semântico.

## [0.6.0] — 2026-10-01 — Fase 5: prontuário eletrônico, CID e medicamentos

### Adicionado
- **Área do médico ("Meu dia")**: pacientes do dia em ordem de prioridade (em atendimento → chegou →
  agendado), chamar o paciente para o consultório (painel da TV), iniciar/continuar atendimentos,
  rascunhos em aberto; atendimento **avulso** (sem agendamento) pela ficha do paciente.
- **Prontuário eletrônico**: queixa principal, anamnese, antecedentes, medicamentos em uso, sinais vitais
  (pré-preenchidos pela triagem), exame físico, avaliação, **diagnósticos CID-10** (busca sem acento, por
  código ou descrição, favoritos e mais usados, um principal), conduta, exames solicitados, orientações,
  retorno.
- **Salvamento automático** do rascunho (a cada ~8 s e ao clicar em "Salvar"), com controle de revisão:
  outra aba/dispositivo não sobrescreve o que já foi salvo; aviso ao sair com alterações pendentes.
- **Finalização imutável**: o registro vira uma versão assinada por **hash HMAC encadeado**; nada é
  editado ou apagado depois (bloqueio na aplicação e, quando o banco permite, por trigger). Correções são
  **adendos** com justificativa, data/hora e autor — o original permanece visível. Selo
  "Integridade verificada" na tela; adulteração direta no banco é detectada.
- Diagnósticos guardam **cópia do código e do texto** da CID: atualizar a base nunca altera registros antigos.
- Somente o **médico responsável** edita, finaliza ou registra adendo; recepção não vê dados clínicos;
  enfermagem visualiza. Cada acesso ao prontuário é auditado **sem conteúdo clínico** na trilha.
- **Triagem** (enfermagem): fila de pacientes que chegaram, sinais vitais (PA, FC, FR, Tax, SpO₂, peso,
  altura, IMC, glicemia, dor), classificação de risco por cores; registro imutável.
- **Alergias** do paciente com alerta em destaque no atendimento; inativação mantém o histórico;
  duplicidade bloqueada.
- **Bases clínicas**: CID-10 global com importação do CSV oficial do DATASUS (tela do Super Admin ou
  `php artisan aivexa:catalog:import cid arquivo.csv`); medicamentos globais (somente leitura para as
  clínicas) + cadastro próprio de cada clínica, com **tipo de controle da Portaria 344/98** (A1–C5,
  antimicrobianos) para as receitas da Fase 6. Amostra inicial marcada como "exemplo".
- Ficha do paciente: linha do tempo de atendimentos e alergias (somente para quem tem acesso ao prontuário).
- API REST: `/encounters` (iniciar, rascunho, finalizar, adendos, histórico), `/triages`, alergias, `/cid`,
  `/medications`. 132 testes (MariaDB e PostgreSQL).

### Corrigido
- Triagem aceita vírgula decimal ("36,5") nos campos de temperatura e peso.

## [0.5.0] — 2026-09-30 — Fase 4: agenda inteligente, fila e painel de chamadas

### Adicionado
- **Agenda:** grade semanal por médico/unidade (duração do horário, limite de pacientes do período,
  encaixes, sala, especialidade do período, vigência), limite diário do médico, tipos de atendimento com
  valor particular, feriados, bloqueios (médico ou unidade, com lista de agendamentos afetados), salas.
- **Motor de disponibilidade** único para recepção, API e (futuramente) IA: só oferece horários realmente
  livres; "próximos horários" por médico ou especialidade.
- **Agendamento sem dupla marcação:** transação com lock do médico + índice único no banco; testado com
  processos paralelos reais. Protocolo, idempotência, particular/convênio (carteirinha válida), remarcação
  com o mesmo protocolo, cancelamento com motivo, confirmação, falta, histórico.
- **Fila e senhas:** senha na chegada (tipo sugerido: 60+ → prioridade, retorno, convênio), senhas avulsas,
  numeração por unidade/dia/tipo, chamar próxima (prioridades primeiro), rechamar, pular, iniciar,
  finalizar (atualiza o agendamento), encaminhar para outra sala; **impressão automática na térmica**.
- **Painel da TV:** endereço secreto por unidade (rotacionável), nome reduzido ("Maria S.") ou só a
  senha, sinal sonoro e voz em português, histórico de chamadas, funciona com hospedagem compartilhada.
- Dashboard com consultas do dia, fila, cancelamentos, faltas e próximos atendimentos.
- API REST: disponibilidade, agendamentos, configuração da agenda e fila. 113 testes (MariaDB e PostgreSQL).

### Corrigido
- Envio duplicado bloqueado no formulário descartava o valor do botão clicado (ex.: tipo de senha).
- Colunas JSON auditadas agora têm segredos aninhados mascarados (ex.: token do painel).

## [0.4.0] — 2026-09-30 — Fase 3: pacientes, médicos e especialidades

### Adicionado
- **Pacientes:** nº de prontuário sequencial por empresa (sem colisão em concorrência), nome civil e
  social, CPF (validado, único por empresa, opcional), RG, CNS, sexo/identidade de gênero, mãe,
  contatos, endereço com **busca de CEP** (ViaCEP pelo servidor), responsáveis/contatos de emergência
  (responsável legal obrigatório para menores), convênios (carteirinha, validade, principal),
  observações administrativas, unidade de cadastro.
- **Detecção de duplicidade** (nome + nascimento ou telefone) com confirmação explícita.
- **Busca** por nome sem acento, CPF, telefone/WhatsApp ou nº de prontuário; **busca global** na barra
  superior (pacientes, médicos, usuários — conforme permissões).
- **LGPD:** consentimentos por finalidade com histórico imutável, exportação dos dados do titular,
  anonimização irreversível (sem copiar PII para a auditoria), registro de acesso ao cadastro, CPF
  mascarado em listas, proibição de exclusão física; histórico de alterações na ficha do paciente.
- **Médicos:** CRM/UF (único por empresa), CPF, contato, apresentação, especialidades com RQE,
  unidades de atendimento, vínculo com a conta de acesso; limite `max_doctors` do plano; gestor de
  filial restrito às suas unidades.
- **Especialidades:** 17 padrões criados para cada clínica (`aivexa:permissions:sync --roles` aplica às
  existentes), gestão com código CBO opcional.
- Dashboard com pacientes ativos, novos no mês e médicos ativos; demo com 3 médicos e 25 pacientes fictícios.
- API REST: `/specialties`, `/doctors`, `/patients` (+ consents, export, anonymize). 91 testes (MariaDB e PostgreSQL).

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
