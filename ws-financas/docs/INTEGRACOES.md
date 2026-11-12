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
6. Teste: autorize seu celular no painel e mande `ajuda`.

**Regras da Meta respeitadas:** respostas em texto livre só dentro da janela de 24 h aberta pela mensagem do cliente; todo webhook tem a assinatura `X-Hub-Signature-256` conferida; mensagens marcadas como lidas.
**Lembretes de contas** (mensagem iniciada pela plataforma) só com **modelo aprovado** e para números com "Aceita lembretes" marcado. Crie no WhatsApp Manager um modelo de categoria *Utilidade*, idioma pt_BR, por exemplo:
> `Olá! Você tem {{1}} conta(s) vencendo hoje, total de {{2}}. Responda "contas hoje" para ver a lista.`
e informe o nome em `WHATSAPP_REMINDER_TEMPLATE`. Sem modelo, nenhum lembrete é enviado.

**Segurança:** número não autorizado recebe só uma mensagem de orientação; números ligados a um usuário herdam o papel dele (contador só consulta as empresas autorizadas); a mesma mensagem reentregue pela Meta é processada uma única vez (`wamid` único); conta suspensa só consulta.

## Asaas (assinaturas)

1. Crie a conta em asaas.com (e uma no **sandbox**: sandbox.asaas.com).
2. *Integrações → Chave de API* → `ASAAS_API_KEY`. Em produção `ASAAS_SANDBOX=false`.
3. *Integrações → Webhooks* → nova URL `https://app.seudominio.com.br/webhooks/asaas`, **token de autenticação** = `ASAAS_WEBHOOK_TOKEN` (gerado pelo instalador), eventos de **Cobranças**, versão v3.
4. `BILLING_MODE=asaas`.

Fluxo: o cliente escolhe o plano em *Assinatura* → o sistema cria o cliente e a **assinatura recorrente** (mensal ou anual, forma "a escolher": Pix, boleto ou cartão) → cada pagamento chega por webhook, é **reconsultado na API** (o corpo do webhook nunca é usado como verdade) e libera o acesso na hora. Eventos repetidos são ignorados (id único). A cada hora o sistema também sincroniza as cobranças, cobrindo webhooks perdidos.

Régua: teste grátis → cobrança no fim do teste → vencida = *em atraso* (aviso, acesso normal) → após `WSF_CARENCIA_DIAS` = *suspensa* (somente consulta e exportação; dados preservados) → pagou = *ativa*. Cancelamento vale no fim do período. O proprietário pode prorrogar teste, trocar plano, suspender, reativar, cancelar e dar baixa manual — tudo vai para o histórico.

## IA (Claude) — opcional

`ANTHROPIC_API_KEY` + `WSF_IA=auto`. Usada **só** quando o interpretador por regras não entende a mensagem (e na "Análise do Claude" do fechamento). Recebe apenas o texto da mensagem e os nomes das empresas; o resultado sempre passa por confirmação do usuário. Modelo padrão `claude-opus-5-5` com esforço baixo e fallback de recusa no servidor; para reduzir custo use `WSF_AI_MODEL=claude-haiku-5-5`. O uso por cliente é contado e limitado pelo plano. `WSF_IA=off` desliga.
