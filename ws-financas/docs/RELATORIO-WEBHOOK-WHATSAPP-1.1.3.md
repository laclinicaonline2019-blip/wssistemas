# Relatório — Webhook do WhatsApp na homologação · WS Finanças em Dia 1.1.3

**Data:** 17/11/2026 · Trabalho feito só em cópia isolada (Percona 5.7.44-48, Apache, HTTPS local). Homologação e produção **não** foram alteradas.

## 1. Diagnóstico

### Comprovado (por teste, no código exato da 1.1.1 instalada)
1. **Com a assinatura certa, a 1.1.1 grava toda mensagem.** O exemplo do botão "Testar" da Meta gerou `in: unknown_number` + `out: sent` em `whatsapp_messages`. Logo, **tabela sem linhas novas = a chamada não chegou ao Laravel com assinatura válida**.
2. **Assinatura recusada não grava nada** (HTTP 401) — e os avisos "assinatura inválida" já vistos no log provam que chamadas da Meta chegaram e foram **recusadas**.
3. **URL com barra no final** (`/webhooks/whatsapp/`) recebe **301 do Apache**: a Meta não segue redirecionamento em POST e nada chega ao Laravel — **sem log algum**.
4. **Sucesso não deixa rastro no log** (`LOG_LEVEL=warning`): impossível confirmar o recebimento sem consultar o banco.
5. **Falha silenciosa no código:** qualquer erro de banco ao gravar a mensagem era tratado como "duplicada" e descartado sem registro.
6. **Eventos de status da Meta** (entregue/lida/falhou) eram ignorados — falhas de entrega não ficavam registradas.

### Hipóteses para a homologação (a confirmar com o novo diagnóstico)
* **A (mais provável):** App Secret diferente do app que envia o webhook (ou valor com espaço/aspas) → 401.
* **B:** a mensagem de teste foi **enviada pela Meta para o celular** — esse sentido **não passa pelo webhook**. O webhook só recebe quando alguém envia **para** o número da plataforma.
* **C:** URL cadastrada com diferença (barra final, `www`, `http`).
* **D:** número próprio sem a conta WhatsApp Business inscrita no app (`subscribed_apps`).

### Sem problema (verificado)
Rota fora do CSRF; assinatura HMAC-SHA256 correta (corpo bruto, comparação em tempo constante); configuração lida do `.env` a cada requisição (não há cache de configuração); processamento após o 200 funciona na HostGator (PHP-FPM, LiteSpeed ou CGI); campo `v27.0` usa o mesmo formato.

## 2. Correções na 1.1.3

A 1.1.3 contém **exatamente** a 1.1.2 já instalada na homologação mais as correções abaixo (numeração nova porque o atualizador só aceita versão maior que a instalada).

| Correção | Efeito |
|---|---|
| Diagnóstico seguro em `storage/logs/whatsapp-AAAA-MM-DD.log` | registra recebido · assinatura (ok ou motivo) · conteúdo · mensagem (resultado) · resposta · status da Meta · erros — **sem** texto, telefone, `wamid`, token ou App Secret |
| Painel *Sistema e backups → WhatsApp — diagnóstico* | confere a configuração sem mostrar valores (App Secret com 32 caracteres e sem espaços, Phone Number ID numérico, token presente, modo) + últimos eventos + últimas mensagens gravadas |
| Erro de banco não é mais silencioso | só a duplicidade real é ignorada; outros erros vão para o diagnóstico (SQLSTATE e local) e para o log |
| Status da Meta processados | a resposta passa a `delivered`/`read`/`failed`; falhas com código (ex.: 131047) no diagnóstico e no log |
| Resposta da Meta registrada | aceita ou falhou, com HTTP, código e título do erro (ex.: 190 token vencido) |
| Processamento continua se a Meta encerrar a conexão | `ignore_user_abort` + limite de tempo próprio |

Nenhuma mudança na validação de assinatura (continua obrigatória), na auditoria, no Asaas ou em migrações.

## 3. Testes

* **Automatizados:** 109 testes por banco — SQLite 105 aprovados/4 pulados; MariaDB 10.11, MySQL 8.0 e **Percona 5.7.44-48** 108 aprovados/1 pulado (os pulados dependem de recursos de outro banco). Novos: `WhatsAppWebhookDiagnosticsTest` (10) — etapas registradas sem conteúdo privado; quatro motivos de assinatura recusada; erro de banco visível (antes silencioso); duplicidade; corpo sem mensagens; status da Meta (entregue, lida, fora de ordem, falha 131047); envio recusado (190) sem vazar token; painel sem revelar chaves; configuração com espaço/valor errado apontada.
* **Ponta a ponta (Apache + Percona 5.7):** instalação limpa 1.1.1 → exemplo da Meta gravado → atualização pelo painel para a 1.1.2 antiga → atualização para a 1.1.3 → barra final **301** (nada no diagnóstico) · segredo errado **401** `nao_confere` · sem cabeçalho **401** `sem_cabecalho` · exemplo repetido `duplicada` · exemplo novo `unknown_number` · "100 reais de combustível" **processed** → despesa R$ 100, Combustível, paga · status `delivered` gravado · modo cloud sem acesso à Meta → `resposta falhou` registrada · diagnóstico sem texto, telefone, segredo ou token · auditoria íntegra. Log completo: `e2e-1.1.2-diagnostico-webhook.log`.

## 4. O que instalar e configurar (após sua autorização)

1. **Atualização:** enviar o `ws-financas-1.1.3.zip` (SHA-256 em `ws-financas-1.1.3.zip.sha256`) para `storage/app/updates/pacotes` → *Atualizações → Analisar → Iniciar*. Origem aceita: 1.1.2 (instalada) ou 1.1.1. Sem migração nova.
2. **Abrir** *Proprietário → Sistema e backups → WhatsApp — diagnóstico* e conferir a configuração (todas as linhas "OK").
3. **Na Meta:** URL exatamente `https://teste.wsfinancasemdia.com.br/webhooks/whatsapp`; campo `messages` assinado; clicar **Testar** → no painel deve aparecer `recebido` → `assinatura_ok` → `mensagem … unknown_number`.
   * Se aparecer `assinatura_recusada nao_confere`: recopiar **Configurações do app → Básico → Chave secreta do app** (do mesmo app) para `WHATSAPP_APP_SECRET`, sem espaços — **alteração de credencial: só com sua autorização**.
   * Se não aparecer nada: conferir a URL (sem barra final) e, com número próprio, a inscrição da conta WhatsApp Business no app.
4. **Teste real:** do seu celular, **enviar para o número da plataforma** `100 reais de combustível` (o celular deve estar cadastrado e validado no painel do cliente e, no número de teste da Meta, na lista "Para").
