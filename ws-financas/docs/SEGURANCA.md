# Segurança e LGPD

| Controle | Implementação |
|---|---|
| Senhas | Argon2id (bcrypt se indisponível), mínimo 10 caracteres com maiúsculas/minúsculas/números/símbolos, checagem opcional em vazamentos (HIBP) |
| Força bruta | limite por e-mail+IP, bloqueio progressivo após 5 falhas, mensagens neutras, custo de hash igual para e-mail inexistente |
| Recuperação | link de uso único enviado ao **e-mail cadastrado**, válido por 60 min, resposta neutra (não revela se o e-mail existe) |
| 2FA | TOTP + 8 códigos de recuperação; **obrigatório** para o proprietário e para administradores de clientes; proteção contra reutilização do código |
| Sessão | cookie HttpOnly, SameSite=Lax, Secure em HTTPS, sessão criptografada, ID regenerado no login |
| CSRF | token em todo formulário e chamada da API do painel; webhooks usam assinatura/token próprios |
| SQL Injection | somente Eloquent/Query Builder com parâmetros; nenhum SQL montado com entrada do usuário |
| XSS | Blade escapa toda saída; o JS do painel escapa tudo com `esc()`; **CSP** `script-src 'self'` (nenhum script inline) |
| Clickjacking/MIME | `X-Frame-Options: DENY`, `frame-ancestors 'none'`, `nosniff`, HSTS em HTTPS |
| Isolamento | contexto do cliente no servidor, escopo que falha fechado, FKs compostas no banco, 404 para ids de outro cliente, testes automatizados de isolamento |
| Permissões | papéis verificados no servidor (middleware `ability:` + serviços); o navegador só esconde botões |
| Criptografia em repouso | CPF/CNPJ, telefones, segredos de 2FA e texto das mensagens do WhatsApp criptografados (AES-256, `APP_KEY`); telefone buscado por HMAC |
| Credenciais de terceiros | só no `.env` fora da pasta pública; nunca registradas em log (auditoria mascara campos sensíveis) |
| Auditoria | append-only também no banco (triggers), cadeia HMAC (`AUDIT_HMAC_KEY`; formato v2 canônico RFC 8785), verificação diária em lotes com relatório assinado, âncora diária por e-mail — ver `AUDITORIA.md` |
| Planilhas | exportação CSV neutraliza fórmulas (`=`, `+`, `-`, `@`) |
| Backups | ver `BACKUP.md` |

**Importante:** guarde `APP_KEY` e `BACKUP_PASSWORD` em local seguro fora do servidor. Sem a `APP_KEY` os dados criptografados não podem ser lidos; trocar a `AUDIT_HMAC_KEY` depois do uso invalida a verificação da cadeia antiga e, com o v2 ativo, faz a gravação da auditoria ser recusada até a chave original voltar.

## LGPD
* **Base legal:** execução de contrato (serviço), obrigação legal (cobrança), legítimo interesse (segurança).
* **Portabilidade:** *Privacidade → Baixar meus dados* (JSON completo do cliente).
* **Retenção:** cancelado → dados disponíveis por `WSF_RETENCAO_APOS_CANCELAMENTO` dias (padrão 90) → eliminação definitiva automática (`wsf:retention:run`); usuários que pertencem a outros clientes (ex.: contador) são mantidos; faturas permanecem por obrigação fiscal; a trilha de auditoria é mantida (prova de integridade — telefones, documentos e segredos já são mascarados na gravação).
* **Minimização:** texto das mensagens do WhatsApp anonimizado após 180 dias; o proprietário não vê lançamentos dos clientes; a IA recebe só o texto da mensagem.
* **Operadores:** hospedagem, Meta, Asaas, Anthropic (opcional). Liste-os no contrato.
* Modelos de política de privacidade e termos em `/privacidade` e `/termos` — revise com um advogado.

## Antes de vender
Rode um pentest externo, revise os termos, ative HTTPS + `SESSION_SECURE_COOKIE=true`, confira `php artisan wsf:preflight` sem pendências e faça um ensaio de restauração.
