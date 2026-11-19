# Relatório — E-mail (convite e recuperação de senha) · WS Finanças em Dia 1.1.4

**Data:** 19/11/2026 · Trabalho feito só em cópia isolada (Percona 5.7.44-48, Apache, HTTPS local, servidor SMTPS local). Homologação e produção **não** foram alteradas.

## 1. Causa do erro 500 (comprovada)

1. **O e-mail é enviado na hora, dentro da própria requisição** (não usa fila nem cron). Na 1.1.3, **qualquer falha de SMTP virava exceção não tratada → HTTP 500**, em três lugares: *Esqueci minha senha*, *novo cliente* (painel do proprietário) e *novo usuário* (painel do cliente). Reproduzido na 1.1.3 com SMTP real: `POST /esqueci-senha` → **500**.
2. **No cadastro de cliente**, o cliente é **criado** antes do envio; quando o SMTP falha, a tela dá 500 e o convite se perde — exatamente "cadastramos o cliente e o convite não chegou".
3. **A falha de SMTP em si** fica registrada em `storage/logs/laravel-AAAA-MM-DD.log` (classe `TransportException`). Causa mais provável na homologação (a confirmar no log): **senha lida errada do `.env`**. Teste de leitura do `.env` (mesma biblioteca do Laravel):

| Linha no `.env` (senha de exemplo com espaço, `#` e `$`) | Valor lido |
|---|---|
| `MAIL_PASSWORD=S3nh@ #Secreta$Titan` (sem aspas) | **`S3nh@`** — o resto vira comentário → autenticação recusada (535) |
| `MAIL_PASSWORD="S3nh@ #Secreta$Titan"` | correto |
| `MAIL_PASSWORD='S3nh@ #Secreta$Titan'` | correto (recomendado) |

   Outras causas possíveis, todas identificadas pela dica do painel: porta de saída bloqueada (tempo esgotado), host/porta errados, certificado, remetente diferente do usuário.
4. **Tempo limite**: o SMTP não tinha limite próprio (padrão do PHP 60 s), acima do `max_execution_time` comum da hospedagem (30 s) — um bloqueio silencioso de porta também terminaria em 500.

**Compatibilidade**: `MAIL_MAILER=smtp`, `MAIL_SCHEME=smtps` com porta 465 é o formato correto do Laravel 13.34 (Symfony Mailer). **Sem dependência de fila ou cron** para convites e recuperação de senha. `bootstrap/cache/config.php` ausente = `.env` lido a cada requisição (correto).

## 2. Correções (1.1.4)

| Correção | Efeito |
|---|---|
| Envio protegido (`MailDelivery`) | falha de SMTP não gera 500; a tela segue (resposta neutra no "Esqueci minha senha"; aviso claro no cadastro de cliente/usuário); erro no log **sem a senha** + dica prática |
| Convite que falhou | cliente criado normalmente + aviso; botão **reenviar convite** em *Clientes → cliente* (substitui o link anterior, sem esperar 60 s; só para usuários daquele cliente; auditado) |
| Pedido de senha após falha | o link da tentativa que falhou é descartado — o próximo pedido sai na hora |
| *Sistema e backups → E-mail* | configuração conferida **sem mostrar a senha** (tamanho, caractere especial que exige aspas, remetente = usuário, porta × esquema), **Enviar e-mail de teste** e último resultado com a dica |
| `MAIL_TIMEOUT` (padrão 15 s) | o envio nunca ultrapassa o limite do PHP |
| Prontidão | linha E-mail mostra o resultado do último envio |

Sem migração, sem mudança no banco, na auditoria, no WhatsApp ou no Asaas.

## 3. Testes

* **Automatizados:** 116 por banco — SQLite 112 aprovados/4 pulados; MariaDB 10.11, MySQL 8.0 e **Percona 5.7** 115 aprovados/1 pulado (os pulados dependem de recursos de outro banco). Novos (`MailDeliveryTest`, 7): convite enviado; "esqueci a senha" com SMTP fora do ar sem 500 e sem a senha no log; cliente criado sem SMTP + reenviar convite (e recusa para e-mail de outro cliente); novo usuário sem 500; pedido logo após falha é enviado; painel com teste de e-mail sem revelar a senha; dicas por tipo de erro e senha mascarada.
* **SMTPS real (TLS + AUTH, imitando o Titan 465):** senha com espaço, `#` e `$` → enviado; "esqueci a senha" → e-mail com link; convite → recebido; senha cortada → 535 com a dica de aspas; certificado não confiável → dica de SSL/TLS; remetente diferente → dica de `MAIL_FROM_ADDRESS`.
* **Ponta a ponta (Apache + Percona 5.7, instância na 1.1.3, atualizada pelo painel para a 1.1.4):** na 1.1.3 com a senha sem aspas → **HTTP 500** (log: *Failed to authenticate*); atualização 1.1.3 → 1.1.4 concluída; na 1.1.4 com a mesma senha → **302** (sem 500), painel mostra *Senha: 5 caracteres* + dica; com aspas simples → e-mail de teste, recuperação de senha e convite **chegaram**; link do convite abre o formulário de nova senha; auditoria íntegra. Log: `e2e-1.1.4-email.log`.

## 4. O que fazer na homologação (após sua autorização)

1. **Antes de instalar** (só leitura): abrir `storage/logs/laravel-AAAA-MM-DD.log` do dia do erro e procurar `TransportException` — a frase seguinte diz a causa (*Failed to authenticate* = senha/usuário; *timed out* = porta; *Connection could not be established* = host/porta).
2. **Instalar a 1.1.4**: enviar `ws-financas-1.1.4.zip` para `storage/app/updates/pacotes` (conferir o SHA-256) → *Atualizações → Analisar → Iniciar*. Origem aceita: 1.1.3 (instalada), 1.1.2 ou 1.1.1. Sem migração.
3. **`.env`** (alteração de credencial — você mesmo, com cópia do arquivo antes): `MAIL_PASSWORD='senha'` **entre aspas simples** (se a senha tiver aspas simples, use aspas duplas); `MAIL_FROM_ADDRESS` igual a `MAIL_USERNAME`; opcional `MAIL_TIMEOUT=15`.
4. *Sistema e backups → E-mail*: todas as linhas OK (Senha com o número de caracteres esperado) → **Enviar e-mail de teste** → conferir a caixa de entrada (e o spam).
5. *Clientes → cliente de teste → reenviar convite* → o e-mail chega → o link abre "Criar nova senha".
6. *Esqueci minha senha* com o e-mail do cliente → e-mail chega.
7. Se o teste falhar com *tempo esgotado*: trocar para `MAIL_PORT=587` e `MAIL_SCHEME=smtp` e repetir; persistindo, pedir à HostGator a liberação da saída SMTP para `smtp.titan.email`.
