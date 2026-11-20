# Relatório — 1.1.7: controle de gastos por entidade · WS Finanças em Dia

**Data:** 20/11/2026 · Feito e testado em ambiente isolado. Homologação e produção **não** foram alteradas.
Prioridade: **simplicidade, controle de gastos e economia para o cliente**.

## 1. O que mudou para o cliente

| Área | Como funciona |
|---|---|
| **Lançar pelo WhatsApp** | `100 almoço`, `250 combustível`, `1500 aluguel`, `Recebi 3500 de um cliente`, `Paguei 180 de energia` viram lançamentos com categoria, valor, data e entidade. Em caso de dúvida (valor duplo, receita/despesa, data, repetição) o assistente pergunta antes. |
| **CPF + várias empresas** | Cada entidade tem os próprios lançamentos, categorias, centros de custo, metas e relatórios — nada se mistura. No WhatsApp: com mais de uma entidade autorizada, pergunta qual; se a empresa tiver centros de custo, identifica pelo nome (`250 combustível veículos`) ou pergunta (com "0 - Sem centro de custo" quando permitido). |
| **Categorias** | Padrão de pessoa física (moradia, saúde, educação…) ou de empresa (fornecedores, folha, impostos, aluguel comercial…) + categorias próprias por entidade (*Empresas e contas*). |
| **Funcionários** | Com o próprio número de WhatsApp (ligado a um usuário ou avulso) ou pelo painel. O administrador define empresas autorizadas, centros de custo permitidos, lançar/consultar, **aprovação** (todas as despesas ou só acima de um valor). O responsável fica registrado em cada lançamento. |
| **Aprovação** | Despesa que precisa de aprovação nasce **pendente**: não entra em saldo, consultas, metas, relatórios ou lembretes. Administrador e gestor aprovam em *Aprovações* (painel, com contador no menu) ou no WhatsApp: `PENDENTES`, `APROVAR 1`, `REJEITAR 1`, `APROVAR TODOS`. Rejeitada fica registrada (auditoria), fora dos totais. |
| **Metas de gasto** | Mensal e semanal por entidade, total e por categoria. Ao lançar: "⚠️ Meta mensal: 90% usada" / "🚨 Meta estourada". |
| **Resumo (painel simplificado)** | Primeira tela do menu: por entidade e período (este mês, esta semana, mês/semana passada) — receitas, despesas, saldo, em aberto, comparação com o período anterior, metas, categorias, centros de custo, gastos que se repetem e recomendações. |
| **Relatórios automáticos** | Semanal (segunda, semana anterior) e mensal (dia 1º, mês anterior), **um por entidade**, por e-mail e/ou WhatsApp. Escolhidos **no cadastro** pelo administrador e alteráveis em *Relatórios automáticos*. No WhatsApp: `relatório`, `relatório mensal`, `relatório desta semana empresa X`. |
| **Recomendações** | Regras sobre os números reais: aumento/queda de gastos, categoria que mais subiu, concentração, metas, gastos recorrentes, oportunidade de corte ("cerca de", "estimativa" — nunca economia garantida). Reescrita opcional pela IA (`WSF_RELATORIOS_IA=true`) só é aceita se não trouxer **nenhum número** que não esteja nos dados. |
| **Planos por faixa** | Pessoal (só CPF), até 3, 4–8, 9–15, 16–25, 26–50 empresas e Personalizado (acima de 50). O CPF do titular está incluído em todos. Criados **inativos e com preço zero** — o proprietário define preços/limites e ativa. Acima do limite: mensagem com o plano indicado; a troca só acontece com a caixa "Autorizo a mudança e o novo valor". Plano personalizado: pedido que vai para *Proprietário → Planos*. Integração Asaas inalterada. |
| **Importação de extrato** | Opcional: OFX, CSV, Excel (.xlsx) e PDF com texto (PDF escaneado não é lido — mensagem clara). Prévia com categoria sugerida, escolha das linhas, marcação de "já importada" (mesma transação, inclusive reenvio do arquivo) e "parecida com lançamento existente" (ex.: já lançado pelo WhatsApp). A migração do sistema antigo continua igual. |

## 2. Arquivos

| Arquivo | Alteração |
|---|---|
| `database/migrations/2026_11_21_000100_v117_controle_de_gastos.php` | **aditiva e idempotente**: tabelas `cost_centers`, `company_categories`, `budgets`, `report_preferences`, `report_runs`, `plan_change_requests`, `import_batches`; colunas opcionais em `entries`, `memberships`, `whatsapp_contacts`, `plans`. Sem tocar na auditoria. `down()` sem perda (seção 6). |
| `app/Core/Access/ApprovalPolicy.php`, `app/Core/Finance/EffectiveScope.php` | regras de funcionário; escopo "só lançamentos efetivos" em todas as leituras |
| `app/Services/FinanceService.php` | pendência, centro de custo, responsável, categorias da entidade, aprovar/rejeitar |
| `app/Services/Assistant/*` | centro de custo, aprovação, relatório sob demanda, avisos de meta |
| `app/Services/Reports/PeriodReport.php`, `ReportDispatcher.php`, `routes/console.php` | relatório por entidade, recomendações, envio agendado (idempotente, em lotes, nova tentativa em falha) |
| `app/Services/Import/StatementParser.php`, `StatementImport.php` | leitura OFX/CSV/XLSX/PDF sem bibliotecas novas (XML sem entidades externas) e importação em duas etapas |
| `app/Services/Billing/BillingService.php`, `database/seeders/PlanSeeder.php` | limite conta só CNPJ, plano pessoal, sugestão de plano, planos por faixa |
| Controladores e telas | *Resumo*, *Aprovações*, *Relatórios automáticos*, centros/categorias/metas em *Empresas e contas*, regras em *Usuários* e *WhatsApp*, consentimento e pedido em *Assinatura*, pedidos em *Proprietário → Planos*, prévia em *Importar*, opção no cadastro |
| `app/Services/Update/UpdateService.php` | reversão recusada com despesa pendente; tabelas da 1.1.7 mantidas numa reversão |
| `app/Services/BackupService.php`, `PrivacyService.php` | tabelas novas nos backups, na exportação LGPD e na eliminação |
| `app/Core/Audit/LegacyVerifier.php` | ordens dos campos novos (compatibilidade da verificação v1 no MySQL) |
| `config/wsf.php`, `config/services.php`, `.env.example` | categorias PF/PJ, `WHATSAPP_RELATORIO_TEMPLATE`, `WSF_RELATORIOS_LOTE`, `WSF_RELATORIOS_IA` |
| `tests/Feature/ControleDeGastosTest.php` | 14 testes novos |

**Auditoria:** `lancamento.criar` e `usuario.permissoes` mantêm o formato histórico; o que é novo vai em eventos à parte (`lancamento.detalhes`, `usuario.regras`, `lancamento.aprovar`, `lancamento.rejeitar`, `importacao.extrato`, `meta.salvar`, `centro_custo.*`, `categoria.*`, `relatorios.preferencias`, `plano.pedido`). Assim, os registros continuam verificáveis também pela 1.1.6 depois de uma reversão.

## 3. Testes automatizados

**142 testes por banco** (14 novos em `ControleDeGastosTest`, 296 asserções):

| Banco | Resultado |
|---|---|
| SQLite | 138 aprovados · 4 pulados (dependem de recursos de outro banco) |
| MariaDB 10.11 | 141 aprovados · 1 pulado |
| MySQL 8.0 | 141 aprovados · 1 pulado |
| Percona 5.7.44 (mesma versão da HostGator) | 141 aprovados · 1 pulado |

Novos: as 5 mensagens do dia a dia; CPF + 2 empresas (pergunta da entidade e do centro de custo, centro identificado pelo nome, nada misturado); categorias PF/PJ e próprias; funcionário pelo WhatsApp (empresa e centro permitidos, limite, pendente fora dos totais, aprovação pelo administrador, funcionário não aprova); usuário do painel com aprovação, gestor aprova/rejeita, decisão repetida recusada; metas mensal/semanal/por categoria com aviso; relatório semanal por entidade (comparação, categorias, recomendações) sem reenviar; WhatsApp com janela de 24 h e com modelo, lotes, nova tentativa de envio que falhou, opt-out; recomendações sem número inventado e reescrita da IA descartada quando inventa valor; preferências no cadastro; planos por faixa (CPF não conta, sugestão de plano, troca só com autorização, pedido personalizado, plano pessoal só CPF); importação OFX/CSV/Excel/PDF com prévia, confirmação, sem duplicar e PDF escaneado recusado; isolamento entre clientes; migração idempotente, reversão e reaplicação sem perda; reversão recusada com pendência; auditoria íntegra.

## 4. Ponta a ponta (Apache + Percona 5.7, atualização pelo painel)

Instância 1.1.6 (Apache, HTTPS, Percona 5.7, auditoria em formato v1 com 91 registros), WhatsApp simulado com webhook **assinado**, SMTPS local. Log completo: `e2e-1.1.7.log`.

| Etapa | Resultado |
|---|---|
| Atualização 1.1.6 → 1.1.7 pelo painel | concluída; 7 tabelas e colunas novas; **91 de 91** registros da auditoria idênticos; lançamentos existentes inalterados e efetivos |
| Cadastro | opções de relatório exibidas; escolha do administrador gravada (semanal + mensal, só e-mail) |
| Configuração pelo painel | CPF + 2 empresas, centros de custo, categoria própria, metas, funcionário com aprovação acima de R$ 200, gestora, 2 números validados por código |
| WhatsApp (administradora) | `100 almoço` → pergunta a entidade → pergunta o centro → registrado; `250 combustível veículos` → centro identificado + "🚨 Meta semanal estourada"; `Recebi 3500 de um cliente`, `Paguei 180 de energia`, `1500 aluguel` (no CPF), "0 - Sem centro de custo" |
| WhatsApp (funcionário só na Filial) | R$ 100 efetivo; `Paguei 500 de material` → "⏳ enviada para aprovação"; `pendentes` → recusado; consulta mostra só a Filial |
| Funcionário do painel | R$ 80 efetivo; R$ 3.200 pendente; *Aprovações* → 403 |
| Aprovação | lista no WhatsApp com responsável; gestora aprova o Notebook e rejeita o material no painel; contador no menu; "Nenhuma despesa aguardando aprovação" |
| Resumo | números separados por entidade, metas (110%), categorias, centros de custo, recomendações |
| Relatórios automáticos (segunda 23/11) | 3 e-mails, um por entidade, com receitas, despesas, saldo, comparação, categorias e recomendações; 2ª execução **não reenviou** |
| `relatório` no WhatsApp | relatório da entidade + dica para o período em andamento |
| Importação CSV | prévia com categorias; 3 importados; reenvio do mesmo arquivo → as 3 "já importada", nada duplicado |
| Isolamento | outro cliente vê só os próprios números (zero); prévia de importação e centro de custo do outro cliente → **404**; nada alterado |
| Proprietário → Planos | 7 planos por faixa, inativos |
| Auditoria | íntegra (149 registros) |
| Reversão com despesa pendente | **recusada** com a mensagem explicativa; versão continua 1.1.7 |
| Aprovar e reverter (modo código) | 1.1.6 de volta; dados e tabelas novas mantidos; 18 valores guardados; **backup da 1.1.6 conferido OK**; auditoria íntegra também pela 1.1.6; WhatsApp funcionando |
| Reaplicar 1.1.7 | concluída; centros de custo, regras do funcionário (aprovação acima de R$ 200) e lançamentos com centro **devolvidos**; cópia temporária apagada; auditoria íntegra (168) |

**Encontrado e corrigido durante os testes:** (1) na reversão, as colunas novas impediam a conferência do backup pela 1.1.6 — agora `down()` guarda os valores e remove as colunas; (2) a verificação da auditoria pela 1.1.6 não reconhecia o formato novo de `lancamento.criar` — o formato histórico foi mantido e os dados novos foram para eventos à parte; (3) relatório cujo envio falhou não era tentado de novo — agora é, nas execuções seguintes do dia.

## 5. Instalação na homologação (após sua autorização)

1. **Backup** antes (o atualizador também faz o dele).
2. Enviar `ws-financas-1.1.7.zip` para `storage/app/updates/pacotes` e conferir o SHA-256 (`ws-financas-1.1.7.zip.sha256`).
3. *Proprietário → Atualizações → Analisar → Iniciar* (aceita a partir da 1.1.1). Uma migração aditiva. **Não é preciso editar o `.env`.**
4. *Proprietário → Planos*: conferir os planos por faixa (inativos, preço zero); definir preços e ativar quando decidir vender.
5. Seguir o roteiro em `docs/HOMOLOGACAO.md` → "Atualização 1.1.7".
6. Opcional: para relatório no WhatsApp **fora da janela de 24 h**, criar e aprovar o modelo na Meta (texto sugerido em `docs/INTEGRACOES.md`) e informar `WHATSAPP_RELATORIO_TEMPLATE=` no `.env`. Sem ele, o relatório sai por e-mail e, no WhatsApp, quando a pessoa estiver conversando ou pedir `relatório`.
7. O cron do cPanel já existente (`schedule:run` a cada minuto) passa a enviar os relatórios; nada a mudar.

## 6. Reversão (rollback)

* *Proprietário → Atualizações → Reverter*, modo **código** (recomendado): volta os arquivos da 1.1.6 mantendo os dados.
* **Recusada enquanto houver despesa pendente de aprovação** (a 1.1.6 a trataria como efetiva): aprove ou rejeite em *Aprovações* e repita.
* As tabelas novas (centros de custo, metas, preferências, importações) **ficam no banco** — a 1.1.6 apenas não as usa.
* As colunas novas das tabelas antigas saem (os backups da 1.1.6 são conferidos com o esquema dela); **antes, todo valor é guardado** na tabela `preservado_1_1_7`. Reaplicar a 1.1.7 devolve os valores e apaga a cópia.
* Modo **banco** (repor do backup) faz o mesmo e, além disso, repõe registros ausentes do backup — nunca apaga nem sobrescreve.
* Depois de reverter, reaplicar a 1.1.7 é só enviar o pacote de novo e iniciar.
