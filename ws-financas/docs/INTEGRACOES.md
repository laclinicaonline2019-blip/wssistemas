# Integrações: WhatsApp oficial, Asaas e IA

Todas as credenciais ficam **somente no `.env`** (fora da pasta pública, permissão 600) — nunca no banco, no código ou no navegador.
Em homologação use os modos simulados (`WHATSAPP_MODE=mock`, `BILLING_MODE=mock`) ou o sandbox de cada serviço.

## WhatsApp Business Platform (Cloud API — oficial da Meta)

**Modelo:** um número oficial do WS Finanças atende todos os clientes. O cliente é identificado pelo **telefone autorizado** que enviou a mensagem (cadastrado em *Painel → WhatsApp*). Cada número pertence a um único cliente.

1. Crie um app em developers.facebook.com (tipo *Business*) e adicione o produto **WhatsApp**; vincule a um **Business Manager verificado**.
2. Registre o número da plataforma (não pode estar em uso no app WhatsApp comum).
3. Crie um **usuário do sistema** com permissão `whatsapp_business_messaging` e gere um **token permanente**.
4. No `.env`:
   ```
   WHATSAPP_MODE=cloud
   WHATSAPP_PHONE_NUMBER_ID=   # ID do número (não é o telefone)
   WHATSAPP_ACCESS_TOKEN=      # token permanente do usuário do sistema
   WHATSAPP_APP_SECRET=        # Configurações do app → Básico → Chave secreta
   WHATSAPP_VERIFY_TOKEN=      # gerado pelo instalador
   WHATSAPP_DISPLAY_NUMBER="(11) 9xxxx-xxxx"
   ```
5. Webhook do app → URL `https://app.seudominio.com.br/webhooks/whatsapp`, token de verificação = `WHATSAPP_VERIFY_TOKEN`; assine o campo **messages**.
6. Teste: cadastre seu celular em *Painel → WhatsApp*, envie do próprio celular o **código de validação** mostrado na tela e depois mande `ajuda`.

**Validação do número (1.1.2):** cadastrar um número não basta — ele só lança e consulta depois que o **próprio celular** envia o código de 6 dígitos exibido ao administrador (válido 24 h, 5 tentativas, "gerar novo código" no painel; só o hash fica no banco). Isso prova a posse do número e evita que um erro de digitação entregue dados financeiros a um estranho. Números cadastrados antes da 1.1.2 continuam validados.

**O que o cliente faz pelo WhatsApp (mesmos dados do painel):**
* Lançar: `100 de combustível`, `gastei 50 no almoço`, `recebi 3.000 de salário`, `paguei 120 de energia`, `gastei 80 de gasolina ontem`, `conta de 1.200 para pagar dia 20`, `aluguel 1.100 dia 5 fixa`, `geladeira 3.000 em 10x`, `paguei internet` (marca a conta em aberto). Resposta: tipo, categoria, valor, data e empresa; `DESFAZER` e `CORRIGIR PARA 90` valem para o último lançamento.
* Consultar (somas feitas no banco, nunca pela IA): `quanto gastei hoje?`, `quanto gastei de combustível este mês?`, `qual foi minha despesa total?`, `quanto recebi este mês?`, `qual meu saldo?`, `despesas por categoria`, `contas vencidas?`, `quanto tenho para pagar amanhã?`, `resumo do mês` — com hoje/ontem/semana/semana passada/mês passado/nome do mês/este ano.
* Dúvida = pergunta antes de lançar: dois valores na mesma mensagem, receita ou despesa sem verbo ("500 de salário"), data futura em pagamento ou "dia 20" que ainda não chegou, sem descrição, possível repetição, valor acima de R$ 100 mil e tudo que veio da IA.

Tudo aparece no painel na hora (o painel aberto se atualiza ao voltar para a aba e a cada minuto) e nos relatórios, gráficos e saldos, que leem a mesma tabela de lançamentos.

**Diagnóstico do webhook (sem terminal):** *Proprietário → Sistema e backups → WhatsApp — diagnóstico* mostra a configuração conferida (App Secret com 32 caracteres, sem espaços; Phone Number ID numérico; token presente — os valores nunca aparecem), os **últimos eventos do webhook** e as últimas mensagens gravadas. Os mesmos eventos ficam em `storage/logs/whatsapp-AAAA-MM-DD.log` (Gerenciador de Arquivos), uma linha por etapa:

| Etapa | Significa |
|---|---|
| `recebido` | a chamada da Meta chegou ao Laravel (se não aparece: URL diferente, barra no final, `http`, bloqueio do servidor, ou nenhuma mensagem foi enviada **para** o número da plataforma) |
| `assinatura_recusada` + `motivo` | `segredo_vazio`, `sem_cabecalho`, `formato_invalido` ou `nao_confere` (App Secret de outro app, outro valor colado, espaços) — HTTP 401 |
| `assinatura_ok` → `conteudo` | quantas mensagens e status vieram no corpo; `sem_mensagens` = corpo sem `entry/changes/value.messages` |
| `mensagem` + `resultado` | `processed`, `unknown_number` (número não cadastrado), `verification`, `plan_blocked`, `limit`, `rate_limited`, `duplicada` |
| `erro_banco` / `erro_processamento` | falha ao gravar/processar, com SQLSTATE e local do código (sem dados) |
| `resposta` | `aceita` pela Meta ou `falhou` com `http`, `codigo` e título do erro (ex.: 190 token vencido, 131030 número fora da lista de teste) |
| `status_meta` | entregue / lida / falhou (com código), informado depois pela Meta |

Nada de texto de mensagem, telefone, `wamid` (contém o telefone), token ou App Secret é registrado. Nível: `WHATSAPP_LOG_LEVEL` (padrão `info`); mantém 14 dias.

**Regras da Meta respeitadas:** respostas em texto livre só dentro da janela de 24 h aberta pela mensagem do cliente; todo webhook tem a assinatura `X-Hub-Signature-256` conferida; mensagens marcadas como lidas.
**Lembretes de contas** (mensagem iniciada pela plataforma) só com **modelo aprovado** e para números com "Aceita lembretes" marcado. Crie no WhatsApp Manager um modelo de categoria *Utilidade*, idioma pt_BR, por exemplo:
> `Olá! Você tem {{1}} conta(s) vencendo hoje, total de {{2}}. Responda "contas hoje" para ver a lista.`
e informe o nome em `WHATSAPP_REMINDER_TEMPLATE`. Sem modelo, nenhum lembrete é enviado.

**Segurança:** assinatura `X-Hub-Signature-256` obrigatória (sem `WHATSAPP_APP_SECRET` o webhook recusa tudo em produção); número não autorizado recebe no máximo uma orientação por dia e nunca lança; limite de mensagens por número (`WHATSAPP_LIMITE_5MIN`, padrão 30 a cada 5 min — o excedente é registrado e ignorado) além do limite mensal do plano; a data do lançamento segue o horário de envio da mensagem (reentregas atrasadas não mudam o dia); todo lançamento grava auditoria com canal `whatsapp`; números ligados a um usuário herdam o papel dele (contador só consulta as empresas autorizadas); a mesma mensagem reentregue pela Meta é processada uma única vez (`wamid` único); conta suspensa só consulta.

**1.1.7 — controle de gastos por entidade:**
* Com mais de uma entidade (CPF + empresas), o assistente pergunta qual é (só as autorizadas para o número) e, se a empresa tiver **centros de custo**, pergunta o centro — ou identifica pelo nome na mensagem (`250 combustível veículos`). Nunca mistura entidades.
* **Funcionário com o próprio número:** vinculado a um usuário (vale a regra do usuário) ou avulso, com empresas, centros de custo, permissão de lançar e **aprovação** (todas as despesas ou só acima de um valor). Despesa pendente responde "⏳ enviada para aprovação" e não entra em saldo, consultas, metas ou relatórios até ser aprovada. Administrador/gestor: `PENDENTES`, `APROVAR 1`, `REJEITAR 1`, `APROVAR TODOS`.
* **Metas** mensais/semanais (total e por categoria): o lançamento avisa "⚠️ Meta mensal: 90% usada" ou "🚨 Meta estourada".
* `relatório` / `relatório mensal` / `relatório deste mês empresa X`: o mesmo texto dos envios automáticos.

**Relatórios automáticos (custo e consentimento da Meta):** saem segunda-feira (semana anterior) e dia 1º (mês anterior), um por entidade, só para quem optou (cadastro ou *Relatórios automáticos*). Se a pessoa conversou com o assistente nas últimas 24 h, vai o texto completo (sem modelo). Fora da janela, a Meta exige **modelo aprovado** (`WHATSAPP_RELATORIO_TEMPLATE`, categoria *Utility*, cobrado por conversa). Sugestão de modelo (pt_BR, 4 variáveis):
`Seu relatório {{1}} de {{2}} está pronto: despesas {{3}}, saldo {{4}}. Responda RELATÓRIO para ver o detalhe e as sugestões de economia.`
Sem o modelo configurado, o envio por WhatsApp fora da janela é registrado como "ignorado" (o e-mail, se marcado, segue). Cada envio é registrado uma única vez por entidade + período + canal (o cron pode repetir sem duplicar).

## Asaas (assinaturas)

1. Crie a conta em asaas.com (e uma no **sandbox**: sandbox.asaas.com).
2. *Integrações → Chave de API* → `ASAAS_API_KEY`. Em produção `ASAAS_SANDBOX=false`.
3. *Integrações → Webhooks* → nova URL `https://app.seudominio.com.br/webhooks/asaas`, **token de autenticação** = `ASAAS_WEBHOOK_TOKEN` (gerado pelo instalador), eventos de **Cobranças**, versão v3.
4. `BILLING_MODE=asaas`.

Fluxo: o cliente escolhe o plano em *Assinatura* → o sistema cria o cliente e a **assinatura recorrente** (mensal ou anual, forma "a escolher": Pix, boleto ou cartão) → cada pagamento chega por webhook, é **reconsultado na API** (o corpo do webhook nunca é usado como verdade) e libera o acesso na hora. Eventos repetidos são ignorados (id único). A cada hora o sistema também sincroniza as cobranças, cobrindo webhooks perdidos.

Régua: teste grátis → cobrança no fim do teste → vencida = *em atraso* (aviso, acesso normal) → após `WSF_CARENCIA_DIAS` = *suspensa* (somente consulta e exportação; dados preservados) → pagou = *ativa*. Cancelamento vale no fim do período. O proprietário pode prorrogar teste, trocar plano, suspender, reativar, cancelar e dar baixa manual — tudo vai para o histórico.

## IA (Claude) — opcional

`ANTHROPIC_API_KEY` + `WSF_IA=auto`. As mensagens comuns são entendidas por regras (sem custo, sem IA). A IA entra **só** quando: (1) as regras não entendem a mensagem — ela apenas extrai os campos (tipo, valor, data, categoria, período da consulta) em JSON com esquema fechado; lançamentos vindos da IA **sempre pedem confirmação** e, nas consultas, os números são calculados no banco (a IA nunca informa valores); (2) a descrição não se encaixa em nenhuma categoria — a IA escolhe uma da lista (fora da lista é descartada); (3) a "Análise do Claude" do fechamento. Recebe apenas o texto da mensagem e os nomes das empresas. Modelo padrão `claude-opus-5-5` com esforço baixo e fallback de recusa no servidor; para reduzir custo use `WSF_AI_MODEL=claude-haiku-5-5`. Chamadas e tokens (entrada/saída) são contados por cliente e por mês (`usage_counters`) e limitados pelo plano (`ai_calls_month`); atingido o limite, o assistente segue só com as regras. `WSF_IA=off` desliga.
