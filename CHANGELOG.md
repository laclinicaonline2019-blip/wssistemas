# Changelog

Formato: [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/) · versionamento semântico.

## [0.13.0] — 2026-10-02 — Fase 12: recepcionista virtual (IA)

### Adicionado
- **Recepcionista virtual no WhatsApp**: responde mensagens livres (as respostas 1/2/3 e botões continuam
  automáticos). Informa especialidades, médicos, unidades e valores; busca **horários livres reais**; identifica o
  paciente pelo telefone ou CPF + nascimento (3 tentativas) ou cadastra paciente novo; lista e cancela consultas
  (no prazo); **agenda em duas etapas** — resumo com valor e confirmação numa mensagem nova do paciente — pela
  mesma regra da recepção (sem encaixe, sem dupla marcação, idempotente, canal "IA"); link de pagamento opcional.
- **Provedores por clínica**: **Claude (Anthropic)** — padrão, SDK oficial PHP, modelo `claude-opus-5-5`, *prompt
  caching*, esforço configurável; **ChatGPT (OpenAI)** — *function calling*, modelo informado pela clínica;
  **MOCK** identificado. Chave própria criptografada (ou a da plataforma pelo `.env`), teste de conexão.
- **Regras fixas** aplicadas pelo sistema: sem diagnóstico/prescrição/interpretação de exames; nada inventado;
  emergência → orientação SAMU 192 (e CVV 188) e equipe avisada sem chamar o modelo; "ATENDENTE" → equipe;
  consentimento "atendimento por IA" revogado → equipe; limites por conversa/hora e por clínica/dia; erro ou
  recusa do modelo → mensagem padrão e equipe avisada; primeira resposta sempre identificada como assistente virtual.
- **Handoff**: conversa "com a equipe" com motivo e aviso no sino; **Assumir (pausar IA)** e **Devolver à IA** na
  conversa; responder manualmente pausa a IA.
- **Registro**: cada chamada (provedor, modelo, tokens, cache, tempo, erro) e cada ação da IA (CPF mascarado);
  painel de uso de 30 dias e últimas ações em *Atendimento IA* (`ia.configurar`).
- Resposta gerada depois do 200 ao webhook (sem worker; compatível com HostGator), uma por vez por conversa.
- Demo com IA em modo MOCK.

### Alterado
- `PatientService::create`, `recordConsent` e `PaymentService::createCharge` aceitam ator nulo (ações automáticas).

## [0.12.0] — 2026-10-02 — Fase 11: WhatsApp, lembretes e notificações

### Adicionado
- **WhatsApp oficial (Cloud API da Meta)** por clínica: Phone Number ID, token (criptografado), App Secret,
  versão da Graph API, modelos aprovados por finalidade; webhook com verificação (`hub.verify_token`) e
  **assinatura `X-Hub-Signature-256` validada**; modo **MOCK** identificado (nada é enviado).
- **Mensagens automáticas**: agendamento, **lembretes** (24 h e 2 h por padrão ou horários personalizados),
  cancelamento, remarcação e falta (oferece remarcar). Lembrete com botões **Confirmar / Cancelar / Remarcar**
  (payload com o agendamento) e respostas de texto "1/2/3". Quem marcou em cima da hora não recebe o lembrete
  de 24 h. Cada aviso tem chave única — nunca duplica.
- **Resposta do paciente**: confirma a presença; cancela respeitando o prazo da clínica (fora do prazo, avisa a
  equipe); "remarcar" e mensagens livres viram aviso para a recepção. Botão de outro paciente é ignorado.
- **Consentimento (LGPD)**: por padrão só envia pelo WhatsApp/e-mail a quem consentiu; sem consentimento a
  mensagem fica registrada como "não enviada" com o motivo. **E-mail** como alternativa ao WhatsApp.
- **Caixa de saída com fila**: envio pela fila (cron da HostGator), novas tentativas com espera crescente em
  falhas temporárias, falha definitiva avisa a equipe; status enviada/entregue/lida/falhou pelo webhook
  (fora de ordem não regride); eventos repetidos ignorados (ID único da Meta).
- **Conversas do WhatsApp** para a recepção (`ia.conversas`): lista, conversa, resposta em texto livre só na
  **janela de 24 h**, encerrar; simulação de resposta no modo MOCK.
- **Central de notificações** da equipe (sino no topo) filtrada por unidade e permissão.
- Link do portal do paciente pelo WhatsApp oficial (modelo aprovado).
- Comando `aivexa:messaging:run` (a cada 5 min). Demo com canal MOCK.

### Segurança
- Encaixe nunca é aceito de canais automáticos (portal/WhatsApp/IA): exige usuário com `agenda.encaixe`.

## [0.11.0] — 2026-10-02 — Fase 10: portal do paciente

### Adicionado
- **Portal do paciente** em `/portal/{clínica}` (pensado para celular), com **login próprio** (guard `patient`,
  separado da equipe): CPF ou e-mail + senha forte, rate limit, bloqueio temporário por tentativas, mensagens
  neutras, sessão revalidada a cada acesso (bloqueio pela clínica derruba a sessão na hora), sem "lembrar-me".
- **Liberação pela clínica** (permissão `paciente.portal`): link de ativação de uso único (72 h) — enviado por
  e-mail, WhatsApp ou copiado; o token só existe no link (no banco, SHA-256). Link de nova senha para conta ativa;
  bloquear/desbloquear acesso. "Esqueci a senha" pelo próprio paciente: CPF + data de nascimento → link de 60 min
  para o e-mail cadastrado (resposta sempre neutra). Aceite dos termos na ativação.
- O paciente vê: **próximas consultas** (confirmar presença; cancelar até N horas antes), **histórico de
  atendimentos** (data, médico, unidade — o conteúdo do prontuário não é exibido), **agendamento online** com os
  horários realmente livres (antecedência mínima e janela máxima configuráveis; nunca encaixe; proteção contra
  clique duplo), **documentos** emitidos (receitas, atestados, pedidos de exames, relatórios — PDF), **exames e
  arquivos** liberados um a um pela clínica, **pagamentos** (contas, recibos, link de pagamento online pendente),
  **médicos** e **meus dados** (com troca de senha).
- Toda ação do portal é auditada com ator **paciente** (nunca atribuída a um usuário da clínica logado no mesmo
  navegador). Anonimização LGPD bloqueia o acesso.
- Configurações da clínica: portal ativo, agendamento online, antecedência, janela e prazo de cancelamento.
- E-mail transacional (SMTP) para ativação/redefinição — sem dados de saúde no corpo.

## [0.10.0] — 2026-10-02 — Fase 9: convênios e faturamento TISS

### Adicionado
- **Convênios (operadoras)**: registro ANS, CNPJ, código do prestador na operadora, versão TISS, prazo de
  pagamento, limite de guias por lote, portal; **planos**; **médicos credenciados** (nenhum marcado = todos);
  desativação sem exclusão.
- **Procedimentos** (TUSS — tabela 22 — ou tabela própria), com importação de CSV (UTF-8 ou ISO-8859-1) e
  vínculo do procedimento ao tipo de atendimento do médico.
- **Tabelas de valores** por convênio e por plano, com vigência (sem sobreposição; a do plano tem prioridade),
  exigência de **autorização prévia** e **coparticipação** (percentual ou valor fixo) por procedimento.
- **Carteirinha ligada ao convênio/plano cadastrados** no cadastro do paciente; carteirinha usada em guia
  não é apagada (fica inativa) e, na anonimização LGPD, o número é mascarado.
- **Agenda**: agendamento por convênio valida convênio ativo, médico credenciado e validade da carteirinha
  **na data da consulta**.
- **Guias** (consulta e SP/SADT): criadas automaticamente na **chegada** do paciente (uma por agendamento),
  com o procedimento e o valor da tabela vigente; guia avulsa; itens, autorização, dados TISS (tipo de
  consulta/atendimento, acidente, caráter, CBO, indicação clínica); conferência de pendências antes de
  "pronta para faturar"; espelho A4 para assinatura do beneficiário; guia faturada é **imutável**.
- **Autorizações prévias**: solicitação, resposta da operadora (senha, nº da guia, validade) ou negativa;
  vínculo automático na guia; marcadas como utilizadas ao faturar.
- **Atendimento misto**: coparticipação vira cobrança **particular** do paciente; itens não cobertos podem
  ser cobrados do paciente pela guia.
- **Lotes de faturamento TISS 4.01.00**: montagem por convênio/unidade/tipo (máx. 100 guias), **XML
  ENVIO_LOTE_GUIAS gerado e validado contra os schemas oficiais da ANS** (lote com XML inválido não fecha),
  hash MD5 do epílogo, download em ISO-8859-1, protocolo de envio, cancelamento (guias voltam a ficar prontas).
- **Conta a receber do convênio** criada no fechamento do lote (vencimento pelo prazo do convênio).
- **Retorno da operadora**: valor pago por guia; a diferença é **glosa** (código TISS/motivo obrigatório);
  pagamento lançado fora do caixa; **repasse médico calculado guia a guia** (regra por convênio).
- **Glosas**: recurso (justificativa), aceite (baixa do saldo sem movimentar dinheiro) e conclusão do recurso
  (valor recuperado recebido; o restante é baixado).
- **Repasse por convênio**: regra de split específica de um convênio (prioridade: tipo de atendimento >
  convênio > pagador > geral).
- CNES da unidade; permissão `convenio.faturar`; telas de Convênios, Procedimentos, Autorizações, Guias e
  Lotes; API `/insurers`, `/procedures`, `/insurance/price`, `/insurance/authorizations`,
  `/insurance/guides`, `/insurance/batches` (+ XML).
- Demo: convênio **fictício** com tabela e procedimentos de exemplo (marcados para conferência na TUSS).

### Alterado
- Recebimento de lote de convênio não pode ser estornado manualmente (`insurance_payment_not_reversible`):
  guias, glosas e repasses dependem do retorno.
- Carteirinhas do paciente são atualizadas pelo id (antes eram recriadas a cada edição).

## [0.9.1] — 2026-10-02 — Fase 8.1: split da Cielo (online e maquininha)

### Adicionado
- **Cielo — API E-commerce com split** (`cielo_api`): venda `SplittedCreditCard` com `SplitPayments`
  (`SubordinateMerchantId` do médico + valor da parte dele); a Cielo liquida cada parte na conta de cada um.
  O cartão é digitado na página `/pagar/{token}`, mas os dados vão do navegador **direto para a Cielo**
  (Silent Order Post): o servidor recebe só o `PaymentToken` temporário. Confirmação pela consulta
  `GET /1/sales/{PaymentId}`; "Post de Notificação" autenticado por token na URL; estorno via `void`
  (desfaz o split). Proteção contra clique duplo (lock por cobrança) e auditoria de aprovação/recusa.
- **Maquininha Cielo com split** (Cielo Smart/Flash com split contratado): no recebimento por cartão,
  a opção "Venda na maquininha Cielo com split" (exige NSU/autorização e médico com ID de subordinado)
  registra a parte do médico como **já liquidada pela Cielo** — não entra no fechamento de repasse.
- Cadastro do **ID de subordinado Cielo** do médico na tela de Repasses (ao lado da carteira ASAAS) e
  origem de cada split (ASAAS, Cielo online, Maquininha Cielo).
- API: `terminal_split` em `POST /receivables/{id}/receive`.

## [0.9.0] — 2026-10-02 — Fase 8: pagamentos online, webhooks e split

### Adicionado
- **Gateways por clínica**: ASAAS (PIX, boleto, cartão na fatura do ASAAS), Cielo (Link de Pagamento) e
  **MOCK** (simulação). Modo **SANDBOX/Produção** sempre visível (selos MOCK/SANDBOX em telas, links e API);
  credenciais **criptografadas** e nunca exibidas de volta; teste de conexão; token de webhook rotacionável.
- **Cobrança online** a partir da conta a receber: link público `/pagar/{token}` com PIX copia-e-cola/QR Code
  e botão para a página segura do gateway (nenhum dado de cartão passa pelo sistema), envio por WhatsApp,
  **chave de idempotência** (clique duplo/reenvio não duplica a cobrança).
- **Confirmação segura**: webhook autenticado (ASAAS: header `asaas-access-token`; Cielo: token na URL;
  comparação em tempo constante) → evento gravado com **ID único** (reenvio não duplica) → **consulta à API do
  gateway** confirma status e valor → baixa no financeiro. "Webhook diz pago, API diz pendente" não dá baixa.
  Valor divergente, pagamento em duplicidade (já pago no balcão) e chargeback vão para **revisão humana**.
- **Sincronização a cada 10 minutos** (cron) cobre webhooks perdidos.
- **Tarifa do gateway** lançada automaticamente como saída (valor bruto − líquido).
- **Estorno pelo gateway**: a baixa reversa só é feita quando o gateway confirma; recebimento online não
  pode ser estornado manualmente (o dinheiro precisa voltar pelo gateway).
- **Split / repasse médico**: regras por médico (percentual ou valor fixo; por tipo de atendimento e
  pagador; vale a mais específica). Cobranças ASAAS com a carteira do médico usam o **split nativo** (o
  dinheiro já cai separado, sobre o valor líquido); dinheiro, maquininha e Cielo geram **repasse interno**.
  **Fechamento do repasse** gera a conta a pagar "Repasse médico"; estorno depois do repasse vira
  devolução descontada no próximo fechamento.
- Telas: Pagamentos online (configuração + últimos avisos dos gateways), Cobranças online, Repasses.
- API: `POST /receivables/{id}/charges` (header `Idempotency-Key`), `/charges/{id}` (consulta, sync,
  cancel, refund); webhook `POST /webhooks/pagamentos/{gateway}`. Demo com gateway MOCK e regras de 60%.

### Corrigido
- Conta com vencimento **hoje** aparecia como "Vencido" (comparação de data em fusos diferentes).

## [0.8.0] — 2026-10-01 — Fase 7: financeiro, caixa e conferência

### Adicionado
- **Contas a receber**: consultas particulares geram a cobrança automaticamente na **chegada** do
  paciente (uma única vez); cancelamento/falta do agendamento cancela a cobrança não paga. Lançamentos
  manuais, recebimento parcial, desconto (somente com permissão), **troco calculado**, parcelas e NSU do
  cartão, data retroativa (até 60 dias) para lançamentos fora do caixa.
- **Contas a pagar** com fornecedor, categoria, nº do documento e **parcelamento mensal** (os centavos
  da divisão vão para a última parcela).
- **Caixa por operador**: abertura com fundo de troco, sangria e suprimento (sem deixar a gaveta
  negativa), **fechamento cego** por forma de pagamento e **conferência por outra pessoa** (justificativa
  obrigatória quando há diferença). Um único caixa aberto por operador (garantido no banco). Dinheiro só
  entra ou sai por um caixa aberto.
- **Livro financeiro imutável**: nenhuma movimentação é editada ou apagada (aplicação + trigger no banco
  quando permitido); correção por **estorno** vinculado, uma única vez, com motivo — o saldo da conta e
  do caixa voltam automaticamente.
- **Recibo** (térmica, A5 ou A4) com valor por extenso; relatório de fechamento de caixa para impressão.
- **Visão geral**: entradas/saídas/saldo do período, por forma de pagamento, por categoria e por dia;
  contas vencidas; caixas a conferir; **exportação CSV** (abre direto no Excel, protegida contra
  injeção de fórmulas). Indicadores financeiros no dashboard (permissão `dashboard.financeiro`).
- Plano de contas inicial em toda clínica (receitas e despesas), editável.
- Agenda mostra a situação da cobrança com botão "Receber".
- API: `/receivables`, `/payables`, `/transactions/{id}/reverse`, `/cash-sessions`, `/finance/summary`
  (valores em centavos). 152 testes (MariaDB e PostgreSQL).

### Corrigido
- Seletores marcados como "largura automática" ocupavam a linha inteira (afetava filtros de várias telas).

## [0.7.0] — 2026-10-01 — Fase 6: receitas, atestados, exames, documentos e impressão

### Adicionado
- **Receitas** com busca na base de medicamentos (posologia e via sugeridas) ou item digitado, separadas
  automaticamente conforme a **Portaria SVS/MS 344/98**:
  - venda livre → receita simples;
  - antimicrobianos (RDC 471/2021) e listas C1/C4/C5 → **receituário de controle especial em 2 vias**
    (identificação do emitente, endereço do paciente, quadros de comprador e fornecedor, validade de
    10 ou 30 dias, quantidade obrigatória);
  - listas A, B, C2 e C3 → exigem a **Notificação de Receita oficial** (talão): o sistema não imprime
    receita para elas, apenas registra o número da notificação.
- **Atestados** de afastamento (dias por extenso, período) e de comparecimento (horários); **CID somente
  com autorização expressa do paciente** (Res. CFM 1.658/2002); data de início limitada (sem retroativo).
- **Solicitação de exames** (lista com exames comuns em um clique, indicação clínica, CID, urgente) e
  **relatório / declaração / encaminhamento** em texto livre.
- **Impressão em A4, A5 e impressora térmica (58/80 mm) e PDF** (dompdf, funciona na HostGator).
  Cabeçalho com clínica, unidade, CNPJ, médico, CRM, especialidade e RQE; data por extenso; linha de
  assinatura; impressão automática ao abrir; "imprimir todos" para documentos emitidos juntos.
- **Documento emitido é imutável**: número sequencial, selo HMAC do conteúdo e **código de verificação
  com QR Code**. Página pública `/validar/{código}` mostra autenticidade, situação (válido/cancelado) e
  dados mínimos (iniciais do paciente); detecta adulteração. Correção = **cancelamento com motivo**
  (somente o médico emitente) + nova emissão.
- Reimpressões contadas e auditadas ("Reimpressão — via nº N"); recepção pode reimprimir receitas e
  atestados, mas não emitir nem cancelar; exames/relatórios só para quem tem acesso clínico.
- **Arquivos do paciente** (resultados de exames, imagens, documentos): PDF/JPG/PNG/WEBP com tipo
  detectado pelo conteúdo, área privada por clínica, acesso auditado, arquivar sem apagar, limite de
  armazenamento do plano.
- Documentos na tela do atendimento (abre em nova aba sem perder o rascunho), na ficha do paciente e no
  menu **Documentos**. Arquitetura para assinatura digital ICP-Brasil (sem simulação).
- API: `/documents` (emitir, listar, consultar, cancelar, PDF). 143 testes (MariaDB e PostgreSQL).

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
