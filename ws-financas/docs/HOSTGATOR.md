# Instalação na HostGator (cPanel)

## 1. Compatibilidade — confira no seu plano

| Requisito | Onde ver no cPanel | Necessário |
|---|---|---|
| PHP **8.3** ou superior | *Selecionar versão do PHP* / *MultiPHP Manager* | sim |
| Extensões `pdo_mysql`, `openssl`, `mbstring`, `fileinfo`, `gd`, `zip`, `intl`, `sodium` | *Selecionar versão do PHP → Extensões* | sim (`gd/zip/intl` recomendadas) |
| MySQL/MariaDB | *Bancos de dados MySQL* | sim |
| **Tarefas Cron** (a cada minuto) | *Trabalhos Cron* | sim — sem cron não há cobrança automática, backup nem retenção |
| HTTPS (AutoSSL) | *SSL/TLS Status* | sim — exigido pela Meta e pelo Asaas para webhooks |
| Domínio/subdomínio com raiz apontando para `/public` | *Domínios* / *Subdomínios* | recomendado |
| `memory_limit` ≥ 256M, `max_execution_time` ≥ 60 | *MultiPHP INI Editor* | recomendado |
| SSH | — | **não** é necessário |

Webhooks funcionam em hospedagem compartilhada: são requisições HTTPS normais. O sistema **não usa processos permanentes**. Planos muito antigos sem PHP 8.3 ou com cron limitado a 15 min não servem — o "Turbo"/"Negócios" atuais atendem.

Depois de instalar, confira: **Proprietário → Sistema e backups**.

## 2. Banco de dados

cPanel → *Bancos de dados MySQL*: crie o banco (`conta_wsfin`), o usuário (`conta_wsfin`) com senha forte e dê **todos os privilégios** do usuário no banco. Para o ambiente de testes, repita com `conta_wsfin_hml`.

## 3. Enviar os arquivos e instalar

Passo a passo completo, só com Gerenciador de Arquivos e navegador (sem terminal), em **[INSTALACAO-CPANEL.md](INSTALACAO-CPANEL.md)**.
Resumo: extraia o ZIP **dentro** da pasta do sistema, aponte a raiz do domínio para `/public`, copie `.env.example` para `.env`
(sem preencher nada) e abra `https://seu-dominio/instalar`. O instalador pede um código gravado em
`storage/app/instalador/CODIGO-DE-INSTALACAO.txt`, gera a `APP_KEY`, valida o MySQL, cria as tabelas e o administrador e se bloqueia.

**Opção B (se não puder mudar a raiz do domínio):** coloque a pasta em `public_html/app` e substitua o `.htaccess` da raiz
do projeto pelo conteúdo de `public/.htaccess.raiz-exemplo` — ele bloqueia `.env`, `storage`, `vendor` etc. e serve só `/public`.

## 5. Cron (obrigatório)

cPanel → *Trabalhos Cron* → "Uma vez por minuto":

```bash
/usr/local/bin/ea-php83 /home/conta/ws-financas/artisan schedule:run >> /dev/null 2>&1
```

(O caminho do PHP 8.3 pode ser `/opt/cpanel/ea-php83/root/usr/bin/php`; confira em *MultiPHP Manager*.) Rotinas: sinal de vida (1 min), fila (1 min), cobrança (1 h), lembretes (08:05), retenção LGPD (03:20), backup (02:10, com teste de restauração semanal), auditoria (05:30).

## 6. Completar o `.env`

Edite `/home/conta/ws-financas/.env` (fica fora da pasta pública):

* **E-mail (recuperação de senha):** crie a conta `nao-responda@seudominio.com.br` em *Contas de e-mail* e preencha `MAIL_HOST`, `MAIL_USERNAME`, `MAIL_PASSWORD` (porta 465, `MAIL_SCHEME=smtps`). O link de "Esqueci minha senha" é enviado ao **e-mail cadastrado** do usuário e usa o endereço de `APP_URL` — confira se está com `https://`.
* **WhatsApp e Asaas:** ver `docs/INTEGRACOES.md`.
* **IA (opcional):** `ANTHROPIC_API_KEY`.
* Depois de editar: *Proprietário → Sistema* mostra o que falta.

## 7. Ambiente de testes (homologação) separado

Crie `hml.seudominio.com.br` → `/home/conta/ws-financas-hml/public`, com **outro banco** e outro `.env` (`APP_STAGE=homologation`, `BILLING_MODE=mock` ou `ASAAS_SANDBOX=true`, `WHATSAPP_MODE=mock` ou um número de teste da Meta). Uma faixa amarela identifica o ambiente. Roteiro de aceite: `docs/HOMOLOGACAO.md`.

## 8. Atualizar

Pelo painel: **Proprietário → Atualizações** (backup automático, conferência SHA-256 de cada arquivo, troca instantânea das pastas,
reversão automática em caso de falha e botão para reverter depois). Detalhes e limitações em [INSTALACAO-CPANEL.md](INSTALACAO-CPANEL.md#5-atualizar-pelo-painel-sem-ssh).

## 9. Migração futura para VPS/nuvem

1. `php artisan wsf:backup` na HostGator e copie o `.wsfbak` + `.env`.
2. No VPS (Ubuntu + Nginx + PHP-FPM 8.3 + MySQL/MariaDB + Redis): extraia o mesmo pacote, ajuste o `.env` (`CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`, `SESSION_DRIVER=redis`), `php artisan migrate --force` e `php artisan wsf:backup:restore arquivo.wsfbak --force` (mesma `BACKUP_PASSWORD`, mesma `APP_KEY` — ela descriptografa documentos e telefones).
3. Worker permanente opcional com Supervisor: `php artisan queue:work`.
4. Aponte o DNS e atualize as URLs de webhook na Meta e no Asaas.
