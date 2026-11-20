# Relatório — Senha do e-mail aparecendo no e-mail recebido · WS Finanças em Dia 1.1.5

**Data:** 20/11/2026 · Testes feitos só com credenciais **fictícias**, em ambiente isolado. Homologação e produção **não** foram alteradas.

## 1. Causa

O código **não** insere a senha SMTP em nenhum e-mail. A senha aparece porque está escrita no **nome do remetente**:

* O e-mail sai com o cabeçalho `From: <MAIL_FROM_NAME> <MAIL_FROM_ADDRESS>` — por isso o destinatário vê **"senha" e depois o endereço `nao-responda@…`** no topo da mensagem.
* `MAIL_FROM_NAME` vem do `.env`; por padrão é `"${APP_NAME}"`. Se a senha estiver em `MAIL_FROM_NAME` (ou em `APP_NAME`), ela vai para todo e-mail. `APP_NAME` também aparece no topo e no rodapé do layout dos e-mails; `WSF_BRAND_NAME` vai no assunto.
* Até a 1.1.4 nada impedia isso.

Reproduzido na 1.1.4 com uma senha fictícia em `MAIL_FROM_NAME`: e-mail de recuperação recebido como **`De: <senha fictícia> <nao-responda@wsfinancasemdia.com.br>`**.

### Outros pontos verificados (sem exposição)

| Ponto | Resultado |
|---|---|
| Corpo, assunto e cabeçalhos dos 4 e-mails (recuperação, convite, teste, âncora) com configuração correta | sem credenciais (procuradas em texto, base64, quoted-printable e URL) |
| Erros de aspas no `.env` (aspas sem fechar) | não vazam: o `.env` fica inválido e o sistema não carrega |
| Logs (inclusive erros de autenticação SMTP, com argumentos no rastreamento ligados ou desligados) | sem a senha |
| Painel (Sistema, Prontidão, E-mail, WhatsApp) | mostra só tamanhos e situações, nunca valores |
| Instalador | não exibe a senha do banco e fica bloqueado após a instalação |
| Backups e pacotes de atualização | não incluem o `.env` |

## 2. Correções (1.1.5)

1. **Na inicialização:** se `APP_NAME`, `MAIL_FROM_NAME` ou `WSF_BRAND_NAME` contiverem qualquer credencial do `.env` (senha do e-mail, do banco, `APP_KEY`, chave da auditoria, senha dos backups, tokens/segredos do WhatsApp, Asaas e IA), o sistema usa "WS Finanças em Dia" no lugar.
2. **Antes de cada envio:** a mensagem inteira (assunto, remetente, responder-para, todos os cabeçalhos, texto e HTML — também nas formas codificadas) é conferida; havendo credencial, o envio é **bloqueado** e nada sai.
3. **Avisos sem mostrar o valor:** *Sistema e backups → E-mail* ("CREDENCIAL ENCONTRADA — MAIL_FROM_NAME contém MAIL_PASSWORD") e *Prontidão* ("Credenciais fora dos e-mails"); o log registra só o nome do campo.

Sem migração, sem mudança no banco, na auditoria, no WhatsApp ou no Asaas.

## 3. Testes (credenciais fictícias)

* **Automatizados:** 121 por banco — SQLite 117 aprovados/4 pulados; MariaDB 10.11, MySQL 8.0 e Percona 5.7 120 aprovados/1 pulado (os pulados dependem de recursos de outro banco). Novos (`EmailCredentialSecurityTest`, 5): os 4 e-mails sem nenhuma credencial; senha no nome do remetente → substituída e apontada no painel (sem o valor); senha no `APP_NAME` → fora do layout; e-mails com credencial no assunto, corpo, HTML ou responder-para → bloqueados, log sem o valor; bloqueio no painel com dica.
* **SMTPS real (local):** configuração normal → 4 e-mails sem a senha; senha no remetente sem a proteção de inicialização → 4 envios bloqueados.
* **Ponta a ponta (Apache + Percona, atualização pelo painel):** 1.1.4 com a senha fictícia em `MAIL_FROM_NAME` → `De: <senha> <nao-responda@…>` (**vazou**); atualização 1.1.4 → 1.1.5; mesmo `.env` → `De: WS Finanças em Dia <nao-responda@…>` (**não vazou**); senha fictícia ausente dos logs; `.env` corrigido → remetente correto. Log: `e2e-1.1.5-seguranca-email.log` (com a senha fictícia substituída por `<senha fictícia>`).

## 4. O que fazer na homologação

**Imediato (independe da atualização):**
1. No `.env` (cópia antes): `MAIL_FROM_NAME="WS Finanças em Dia"` e conferir que `APP_NAME` e `WSF_BRAND_NAME` não têm a senha. A senha fica **só** em `MAIL_PASSWORD='…'`.
2. **Trocar a senha** da conta `nao-responda@` no Titan — ela já foi enviada em e-mails — e colocar a nova em `MAIL_PASSWORD` (entre aspas simples).
3. Apagar da caixa de entrada e da lixeira os e-mails de teste que mostraram a senha.

**Atualização (após sua autorização):** enviar `ws-financas-1.1.5.zip` para `storage/app/updates/pacotes` (conferir o SHA-256) → *Atualizações → Analisar → Iniciar* (aceita a partir da 1.1.4, 1.1.3, 1.1.2 ou 1.1.1). Depois: *Sistema e backups → E-mail* — linha "Nomes exibidos no e-mail" **OK** → **Enviar e-mail de teste** → conferir que o remetente aparece como "WS Finanças em Dia".
