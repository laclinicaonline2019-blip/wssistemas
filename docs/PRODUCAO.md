# Produção (Fase 20) — runbook de entrada no ar

Pré-requisito: homologação assinada ([HOMOLOGACAO.md](HOMOLOGACAO.md)). Instalação base: [HOSTGATOR.md](HOSTGATOR.md).

## 1. Domínio, SSL e Cloudflare

1. Domínio definitivo (ex.: `app.suaclinica.com.br`) apontado para a HostGator.
2. cPanel → **SSL/TLS Status** → AutoSSL ativo (certificado válido) **antes** de ligar HSTS.
3. Recomendado: Cloudflare na frente (proxy laranja), SSL **Full (strict)**, regras de WAF de
   [SECURITY.md](SECURITY.md#cloudflare-waf--recomendado-na-frente-do-site), e `TRUSTED_PROXIES=cloudflare`.
4. E-mail: registros SPF, DKIM e DMARC do domínio configurados no cPanel → *Deliverability*.

## 2. `.env` de produção

```dotenv
APP_ENV=production
APP_STAGE=production
APP_DEBUG=false
APP_URL=https://app.suaclinica.com.br
APP_KEY=base64:…                  # gerada uma única vez; cópia no cofre (sem ela, dados criptografados se perdem)
LOG_LEVEL=warning

SESSION_SECURE_COOKIE=true
SECURITY_HSTS=true
FORCE_HTTPS=true
TRUSTED_PROXIES=cloudflare        # só se usar Cloudflare
SUPER_ADMIN_REQUIRES_2FA=true
PASSWORD_BREACH_CHECK=true

MAIL_MAILER=smtp                  # conta de e-mail do cPanel (SSL 465)
MAIL_FROM_ADDRESS=nao-responda@suaclinica.com.br

PLATFORM_BILLING_PROVIDER=asaas   # cobrança das assinaturas
PLATFORM_ASAAS_SANDBOX=false
PLATFORM_ASAAS_API_KEY=$aact_prod_…
PLATFORM_ASAAS_WEBHOOK_TOKEN=<aleatório longo>

BACKUP_PASSWORD=<senha forte, guardada em cofre fora do servidor>
INSTALL_TOKEN=                    # vazio depois da instalação
```

Depois de editar: `php artisan config:cache && php artisan route:cache && php artisan view:cache`.

Permissões: `.env` com `chmod 600`; `storage/` e `bootstrap/cache/` graváveis; nada do projeto dentro de
`public_html` além da pasta `public/`.

## 3. Rotinas (cron)

Uma linha só, **a cada minuto**:

```bash
/usr/local/bin/php /home/SUA_CONTA/aivexa/artisan schedule:run >> /dev/null 2>&1
```

Ela executa (ver `routes/console.php`):

| Quando | Rotina |
|---|---|
| a cada minuto | fila (e-mails, WhatsApp, IA, PDFs, webhooks) e sinal de vida do cron |
| a cada 5 min | lembretes e reenvio de mensagens |
| a cada 10 min | sincronização de cobranças (cobre webhooks perdidos) |
| 02:30 diário | **backup do banco** (`aivexa:backup`) |
| 03:10 diário | verificação da cadeia de auditoria |
| 03:15 domingo | **backup dos anexos** (`aivexa:backup --files`) |
| de hora em hora | régua das assinaturas (renovação, cobrança, bloqueio) |
| 04:10 / 06:20 diário | retenção LGPD / sincronização bancária (Pluggy) |

## 4. Integrações: de SANDBOX para PRODUÇÃO

Cada clínica troca o modo na própria tela (o sistema registra quem trocou na auditoria):

| Integração | O que trocar | Webhook |
|---|---|---|
| ASAAS / Cielo da clínica | Configurações → Pagamentos → modo **Produção** + chaves de produção | URL mostrada na tela do gateway |
| WhatsApp oficial | Canal com número verificado no Meta Business, token permanente | `/webhooks/whatsapp/{canal}` |
| WhatsApp não oficial | Instância de produção (Z-API/Evolution); aceite de risco já registrado | URL com token mostrada no canal |
| IA | Chave de produção do provedor, **limite de gasto** definido no painel dele | — |
| Pluggy | Credenciais de produção | — |

A plataforma: `PLATFORM_ASAAS_SANDBOX=false` e webhook `https://app…/webhooks/assinaturas/asaas` no ASAAS.

> Em produção (`APP_ENV=production`) a **simulação de pagamento** do gateway MOCK é bloqueada (a menos que
> `PAYMENTS_ALLOW_MOCK_IN_PRODUCTION=true`, que não deve ser usado). Desative o gateway MOCK de cada clínica e o
> preflight acusa integrações ainda em MOCK/sandbox.

## 5. Checklist do dia da virada

1. [ ] Backup do banco de homologação (se for reaproveitar cadastros) — ou banco novo vazio.
2. [ ] `php artisan migrate --force` (o pacote já vem com `vendor/`).
3. [ ] `php artisan aivexa:preflight --strict` → **0 erros e 0 avisos** (ou avisos aceitos por escrito).
4. [ ] Super Admin → **Prontidão (produção)** confirma cron com sinal recente.
5. [ ] Super Admin → **Backups** → *Gerar backup agora* → baixar e abrir (descriptografar) no seu computador.
6. [ ] Login de cada perfil; 2FA dos administradores ativo.
7. [ ] Uma cobrança real de valor mínimo paga e estornada; uma mensagem real de WhatsApp enviada e recebida.
8. [ ] Remover dados de demonstração (não rodar `DemoSeeder` em produção).
9. [ ] Monitor externo (UptimeRobot ou similar) em `https://app…/up` a cada 5 minutos.

## 6. Backup e restauração

- Automático: banco diário (mantém `BACKUP_KEEP`=14) e anexos semanais (`BACKUP_KEEP_FILES`=4) em
  `storage/app/backups`, criptografados (AES-256-GCM, chave derivada da `BACKUP_PASSWORD`).
- **Cópia fora do servidor é obrigatória**: baixe semanalmente pelo painel *Backups* (download auditado) ou use
  o backup do cPanel (*JetBackup*) como segunda camada.
- Restaurar:

```bash
php artisan aivexa:backup --decrypt=storage/app/backups/aivexa-db-AAAAMMDD-HHMMSS.sql.gz.enc --to=/tmp/r.sql.gz
gunzip /tmp/r.sql.gz
mysql -u USUARIO -p BANCO_NOVO < /tmp/r.sql        # ou phpMyAdmin → Importar
# anexos: descriptografe o .zip.enc da mesma forma e extraia em storage/app/
```

Depois: apontar `DB_DATABASE` para o banco restaurado, `php artisan config:cache`, conferir login e
`php artisan aivexa:audit:verify`. Sem a `APP_KEY` original os campos criptografados (CPF, prontuário etc.)
**não podem ser lidos** — guarde a `APP_KEY` junto com a `BACKUP_PASSWORD` no cofre.

## 7. Atualização de versão (deploy)

1. Backup manual pelo painel.
2. `php artisan down --secret=<token>` (manutenção, você acessa por `/<token>`).
3. Enviar o novo `.zip` e extrair por cima (sem apagar `storage/` nem `.env`).
4. `php artisan migrate --force && php artisan optimize:clear && php artisan config:cache && php artisan route:cache && php artisan view:cache`.
5. `php artisan aivexa:preflight` e `php artisan up`.

## 8. Reversão (rollback)

- Falha **antes** de migrar: reextrair o `.zip` da versão anterior.
- Falha **depois** de migrar: `php artisan down`, restaurar o backup do passo 1 (seção 6) e o `.zip` anterior,
  `php artisan up`. As migrations não apagam dados clínicos; mesmo assim, restaure o banco em vez de
  `migrate:rollback` em produção.

## 9. Operação contínua

| Frequência | Tarefa |
|---|---|
| diária (automática) | Prontidão e saúde no painel; alertas de fila parada |
| semanal | Baixar backup para fora do servidor; ver Central de segurança de cada clínica |
| mensal | Ensaio de restauração em banco vazio; `composer audit` no próximo pacote; revisar acessos de usuários |
| anual | Pentest externo ([PENTEST.md](PENTEST.md)); revisão LGPD; troca da `BACKUP_PASSWORD` (backups antigos continuam com a senha antiga) |

Incidentes: siga [SECURITY.md](SECURITY.md#resposta-a-incidentes).
