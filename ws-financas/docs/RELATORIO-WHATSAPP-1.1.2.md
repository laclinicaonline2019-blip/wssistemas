# Relatório — Módulo WhatsApp/IA · WS Finanças em Dia 1.1.2

**Data:** 17/11/2026 · **Ambiente:** desenvolvimento isolado (Percona 5.7.44-48, MariaDB 10.11, MySQL 8.0, SQLite; Apache + PHP 8.3).
**Produção e homologação não foram alteradas.** Nenhuma credencial real foi usada ou exposta.

## 1. Respostas diretas (estrutura atual)

| # | Pergunta | Resposta |
|---|---|---|
| 1 | API/provedor de WhatsApp | **WhatsApp Business Platform — Cloud API oficial da Meta** (Graph API `v21.0`), sem intermediário. Um número oficial do WS Finanças atende todos os clientes; cada cliente é identificado pelo celular cadastrado. |
| 2 | Integração implementada? | **Sim, no código** (desde a 1.0.0): webhook com verificação (`hub.verify_token`) e assinatura `X-Hub-Signature-256`, envio de respostas, marcação de lida, idempotência por `wamid`, permissões, limites de plano. **Nunca foi testada com a Meta real**: homologação e testes rodam em `WHATSAPP_MODE=mock`. **Não declaro a integração operacional** até o teste com número real (seção 6). |
| 3 | A IA está funcionando? | **Código pronto e testado com a resposta da API simulada; não verificado contra a API real** (não há `ANTHROPIC_API_KEY` nos ambientes de teste). Por projeto, as mensagens comuns (todas as do seu pedido) são entendidas **por regras, sem IA e sem custo**; a IA entra quando as regras não entendem e para classificar descrições desconhecidas. |
| 4 | Registra despesas e receitas automaticamente? | **Sim** — comprovado ponta a ponta (webhook assinado → lançamento → resposta) no modo simulado. |
| 5 | Os lançamentos aparecem no painel? | **Sim** — mesma tabela de lançamentos; comprovado nas telas do painel, relatórios, gráficos e saldo. |
| 6 | Consultas pelo WhatsApp funcionam? | **Antes: parcialmente** (só contas a pagar/receber e resumo; "quanto gastei/recebi", saldo e categorias não funcionavam). **Agora: sim**, com os números calculados no banco e conferidos contra SQL direto. |
| 7 | Configuração externa pendente? | **Sim** — conta Meta (Business verificado, app, número, token permanente, App Secret, webhook) e chave da Anthropic. Lista completa na seção 5. |
| 8 | O que falta para os clientes? | Instalar a 1.1.2 em homologação (aguardando sua autorização), configurar Meta + Anthropic, executar o teste real da seção 6 e, aprovado, liberar em produção. |

## 2. O que já existia (auditoria do código — não foi refeito)

* Webhook oficial (verificação + assinatura HMAC do App Secret), resposta 200 imediata e processamento após a resposta.
* Identificação pelo telefone (hash HMAC; telefone criptografado no banco; tolerância ao 9º dígito), 1 número = 1 cliente.
* Assistente compartilhado com a caixa "Lançar por mensagem" do painel: despesas, receitas, contas a pagar com vencimento, fixas (12 meses), parceladas, "paguei a luz" (marca conta em aberto), escolha de empresa, DESFAZER e CORRIGIR PARA.
* Confirmação quando faltava descrição, valor acima de R$ 100 mil, possível repetição e todo resultado vindo da IA.
* Idempotência (`wamid` único), permissões por papel/usuário vinculado, conta suspensa só consulta, limite mensal de mensagens e de chamadas de IA por plano, contagem de tokens, mensagens gravadas criptografadas, auditoria `lancamento.criar` com canal `whatsapp`.

## 3. O que foi implementado na 1.1.2

| Área | Implementação |
|---|---|
| "100 de combustível" | Valor + descrição sem verbo = **despesa paga com a data do dia**; resposta no formato pedido: ✅ Despesa registrada com sucesso! · 📝 descrição · ⛽ Categoria · 💰 Valor · 📅 Data (dinâmica) · 🏢 Empresa (se houver mais de uma) · "Seu controle financeiro já foi atualizado." |
| Categorias | Novas **Combustível** (gasolina, etanol, álcool, diesel, GNV, posto, abasteci) e **Refeições** (almoço, jantar, marmita, refeição…); emoji por categoria; limites sugeridos e dicas no painel. |
| Classificação por IA | Quando as regras resultam em "Outros", a IA escolhe **uma categoria da lista** (resposta fora da lista é descartada); respeita o limite do plano. |
| Consultas | Novas: **quanto gastei** (por período e por categoria ou termo: "de combustível", "com uber", "no ifood"), **quanto recebi** (com "ainda a receber"), **saldo** (recebido − pago, previsão igual ao quadro do painel e saldo das contas bancárias), **despesas por categoria** (mesma agregação do relatório). Períodos: hoje, ontem, anteontem, esta semana, semana passada, este mês, mês passado, nome do mês, este ano. Sem dados → "📭 Nenhuma…" (nunca inventa). |
| IA nas consultas | O esquema da IA passou a cobrir as consultas, mas ela **só extrai a pergunta** (tipo, categoria, período); os valores vêm sempre do banco — um valor devolvido pela IA é ignorado (testado). |
| Ambiguidades | Pergunta antes de lançar: **dois valores** ("gastei 50 e 30") → escolher; **receita × despesa** sem verbo ("500 de salário") → 1/2; **"dia 20" que ainda não chegou** em gasto/recebimento → entende mês passado e confirma; **data futura** em pagamento → confirma; data com mais de 60 dias → confirma; "gastei no mercado" sem valor → pede o valor. |
| Repetição | Aviso de "parece repetido" ficou preciso: mesma descrição + valor em 24 h, ou mesmo valor sem descrição em 10 min (antes, qualquer valor igual em 10 min era tratado como repetido). |
| **Validação do número** | Cadastro no painel gera **código de 6 dígitos** (24 h, 5 tentativas, só o hash no banco, "gerar novo código"); o número só lança/consulta depois que **o próprio celular** envia o código; auditoria `whatsapp.validar` / `whatsapp.novo_codigo`. Números já cadastrados continuam validados (migração aditiva). |
| Abuso e custo | Limite por número (padrão 30 mensagens/5 min; excedente registrado e ignorado, sem resposta e sem custo); número desconhecido recebe no máximo 1 orientação por dia. |
| Data da mensagem | Usa o horário de envio informado pela Meta (reentrega atrasada não muda o dia do lançamento). |
| Painel ao vivo | O painel aberto recarrega sozinho ao voltar para a aba e a cada 60 s (nunca durante edição). |
| Correção encontrada | Cadastro de número dava erro 500 quando a requisição não trazia "empresa padrão"/"usuário" — corrigido. |

Arquivos: `MessageInterpreter`, `Assistant`, `AiInterpreter`, `CategoryGuesser`, `InboundProcessor`, `WhatsAppClient`, `WhatsappContact`, `WhatsappController`, `settings/whatsapp.blade.php`, `app.js`, `config/wsf.php` e `config/services.php`, rota `whatsapp.code`, migração `2026_11_17_000100_whatsapp_contact_verification` (**só adiciona colunas**; não toca na auditoria). Os mecanismos de auditoria validados na 1.1.1 não foram alterados.

## 4. Testes e resultados

### 4.1 Automatizados — 99 testes por banco

| Banco | Resultado |
|---|---|
| SQLite | 95 aprovados, 4 pulados (dependem de triggers/MySQL — cobertos nas linhas abaixo) |
| MariaDB 10.11.14 | 98 aprovados, 1 pulado (instalador contra MySQL, executado à parte) |
| MySQL 8.0.46 | 98 aprovados, 1 pulado (idem) |
| **Percona 5.7.44-48** | 98 aprovados, 1 pulado (idem) |
| Percona 5.7 com banco `latin1_swedish_ci` | 98 aprovados, 1 pulado (idem) |

Novos (**WhatsAppIntegrationTest, 17 testes**): "100 de combustível" (lançamento, categoria, data, formato exato, data dinâmica); as 6 mensagens de exemplo; lançamentos aparecem em `/app/api/mes`, relatório e gráfico de fluxo; as 6 consultas com valores exatos, incluindo lançamentos feitos no painel; sem dados não inventa; duplicidade (reentrega da Meta, repetição pelo cliente, confirmação); isolamento entre dois clientes; ambiguidades (dois valores, receita × despesa, "dia 20", data futura, sem valor, sem descrição); mensagem entregue com atraso; validação do número (código, hash, tentativas, bloqueio, novo código, expiração em 24 h); webhook sem assinatura válida → 401; limite por número e número desconhecido; número só-consulta não lança; auditoria `whatsapp` e cadeia íntegra; IA interpreta e **sempre confirma**, contagem de chamadas e tokens; IA só extrai a pergunta (valor da IA ignorado); IA classifica categoria, descarta fora da lista, trata recusa e respeita o limite do plano. Testes anteriores do WhatsApp mantidos (texto da resposta e número validado ajustados).

### 4.2 Integração ponta a ponta (Percona 5.7, Apache, HTTPS local, webhook assinado)

Base: a instalação de teste 1.1.1 com auditoria v2 ativa (47 registros). Arquivos: `e2e-1.1.2-whatsapp.log` e `e2e-1.1.2-conversa.txt`.

| Etapa | Resultado |
|---|---|
| Atualização 1.1.1 → 1.1.2 pelo painel | concluída; migração 134 ms; **47/47 impressões da auditoria idênticas**; 6 triggers; número antigo continua validado |
| 7 mensagens de exemplo | 7 lançamentos com tipo, valor, categoria e data corretos ("ontem" → 16/11); respostas no formato pedido |
| Ambiguidades / repetição | dois valores → escolhido R$ 30; "500 de salário" → perguntou; repetição de "100 de combustível" → perguntou; "não" → nada lançado |
| 6 consultas × SQL direto | gasto hoje **1.050,00 = 1.050,00**; combustível no mês **180,00 = 180,00**; despesa total **3.816,90 = 3.816,90**; recebido **3.836,50 = 3.836,50**; saldo e categorias coerentes com o painel |
| Número novo | cadastrado no painel → "aguardando validação" → mensagem antes do código não lança → código errado recusado → código certo valida → lançamento normal |
| Número desconhecido | 1 orientação; segunda mensagem sem resposta; nada lançado |
| Painel | lançamentos do WhatsApp na tela do mês e no relatório (Combustível R$ 180, Refeições R$ 85) |
| Auditoria | 13 registros canal `whatsapp` (todos v2) + `whatsapp.validar`; **cadeia íntegra (67 registros)** |

## 5. Pendências externas e configuração

**Meta (WhatsApp Cloud API) — obrigatório:**
1. Business Manager **verificado** e app do tipo Business com o produto WhatsApp.
2. Número exclusivo da plataforma (fora do app WhatsApp comum) registrado no app.
3. Usuário do sistema com `whatsapp_business_messaging` e **token permanente**.
4. No `.env` da homologação (nunca no código):
   `WHATSAPP_MODE=cloud`, `WHATSAPP_PHONE_NUMBER_ID`, `WHATSAPP_ACCESS_TOKEN`, `WHATSAPP_APP_SECRET`, `WHATSAPP_VERIFY_TOKEN` (já gerado pelo instalador), `WHATSAPP_DISPLAY_NUMBER`.
5. Webhook: `https://teste.wsfinancasemdia.com.br/webhooks/whatsapp`, token = `WHATSAPP_VERIFY_TOKEN`, assinar o campo **messages**.
6. Opcional: modelo aprovado (categoria Utilidade, pt_BR) para lembretes → `WHATSAPP_REMINDER_TEMPLATE`.

**Anthropic (IA) — recomendado:** `ANTHROPIC_API_KEY` no `.env`; `WSF_IA=auto`. Modelo padrão `claude-opus-5-5` com esforço baixo. As chamadas e os tokens ficam em *Proprietário → Uso WhatsApp/IA*, e o limite mensal é por plano (Profissional 300, Empresarial 1.500 chamadas). Sem a chave, tudo funciona por regras; só deixam de existir a interpretação de frases fora do padrão e a classificação de descrições desconhecidas.

**Opcional:** `WHATSAPP_LIMITE_5MIN` (padrão 30).

## 6. Teste real necessário antes de liberar aos clientes (não executado)

Com a 1.1.2 em homologação e as credenciais acima:
1. *Painel → WhatsApp*: cadastrar o seu celular e enviar o código pelo WhatsApp real → "Número validado".
2. Enviar `100 de combustível` e as 6 mensagens de exemplo; conferir respostas e painel.
3. Enviar as 6 perguntas; conferir os valores com o painel.
4. Enviar uma frase fora do padrão (ex.: "torrei 89,90 num tênis") → a IA pede confirmação; conferir em *Uso WhatsApp/IA* a contagem.
5. Conferir no painel da Meta que o webhook está entregando sem erros, e em *Sistema → Auditoria* que a cadeia continua íntegra.

Só depois desse roteiro a integração pode ser declarada operacional.

## 7. Pacote para homologação

| | |
|---|---|
| Arquivo | `ws-financas-1.1.2.zip` |
| SHA-256 | ver `ws-financas-1.1.2.zip.sha256` |
| Origem | **1.1.1** (`min_from`) — pela tela *Atualizações*, como a 1.1.1 |
| Banco | 1 migração aditiva (`whatsapp_contacts`: 4 colunas) |
| Reversão | pelo painel (código); a auditoria e as colunas novas são mantidas |

**Aguardando sua autorização expressa para instalar em homologação.**

## 8. Limitações conhecidas

* Só mensagens de **texto** (áudio, foto e comprovante recebem orientação) — leitura de áudio/comprovante seria uma próxima etapa.
* "Gasto" nas consultas = despesas com data no período, pagas ou não (a resposta separa pago × em aberto); "recebido" = receitas com data até hoje.
* A interpretação por regras cobre os formatos comuns; frases muito livres dependem da IA (com a chave configurada).
* Lembretes ativos dependem de modelo aprovado pela Meta.
