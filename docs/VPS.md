# Instalação em VPS (Ubuntu 24.04)

Use este manual quando a clínica sair da hospedagem compartilhada ou já começar numa VPS
(Hostinger VPS, Contabo, DigitalOcean, AWS Lightsail, Magalu Cloud, HostGator VPS etc.).
O sistema é o **mesmo pacote `.zip`** da HostGator; muda só a forma de rodar.

| | HostGator (compartilhada) | VPS |
|---|---|---|
| Fila (WhatsApp, e-mails, IA) | pelo cron, a cada minuto | **sempre ligada** (Supervisor), respostas na hora |
| Cache e sessões | no banco | **Redis** (mais rápido) |
| Antivírus dos anexos | verificações próprias | **ClamAV** de verdade |
| Banco | MySQL/MariaDB | MariaDB/MySQL **ou** PostgreSQL |
| Atualização | enviar .zip pelo cPanel | `atualizar.sh` (com backup antes e volta com 1 comando) |

Arquivos prontos usados abaixo: `deploy/vps/` (dentro do pacote).

## Requisitos da VPS

- **Ubuntu Server 24.04 LTS** (o PHP 8.3 já vem no sistema).
- Mínimo: **2 vCPU, 4 GB de RAM, 40 GB SSD**. Até ~10 clínicas pequenas: 4 vCPU / 8 GB.
  ClamAV sozinho usa ~1,5 GB de RAM; com menos de 4 GB, deixe-o desligado.
- Servidor no Brasil (LGPD: prefira data center em São Paulo).
- Domínio apontado para o IP da VPS (registro **A** `app.suaclinica.com.br → IP`).

> Todos os comandos abaixo são executados como **root** (ou com `sudo`), via SSH:
> `ssh root@IP_DA_VPS`. Troque `app.suaclinica.com.br` pelo seu domínio em todos os passos.

---

## 1. Atualizar o sistema e proteger o acesso

```bash
apt update && apt -y upgrade
timedatectl set-timezone America/Sao_Paulo

# Firewall: só SSH, HTTP e HTTPS
apt -y install ufw fail2ban unattended-upgrades
ufw allow OpenSSH && ufw allow 80/tcp && ufw allow 443/tcp && ufw --force enable
systemctl enable --now fail2ban
dpkg-reconfigure -f noninteractive unattended-upgrades   # atualizações de segurança automáticas
```

Recomendado: entrar por **chave SSH** e desligar login por senha
(`PasswordAuthentication no` em `/etc/ssh/sshd_config`, depois `systemctl restart ssh`) — teste a chave antes.

## 2. Instalar PHP 8.3, Nginx, banco, Redis e utilitários

```bash
apt -y install nginx \
  php8.3-fpm php8.3-cli php8.3-mysql php8.3-pgsql php8.3-mbstring php8.3-xml php8.3-curl \
  php8.3-intl php8.3-gd php8.3-zip php8.3-bcmath php8.3-redis php8.3-opcache \
  mariadb-server redis-server supervisor unzip certbot python3-certbot-nginx
```

PostgreSQL em vez de MariaDB (opcional): troque `mariadb-server` por `postgresql` e use o passo 3B.

## 3. Criar o banco

**3A — MariaDB (padrão):**

```bash
mysql_secure_installation          # responda Y para tudo; não precisa definir senha do root (usa socket)
mysql -e "CREATE DATABASE aivexa CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
          CREATE USER 'aivexa'@'localhost' IDENTIFIED BY 'TROQUE-POR-SENHA-FORTE';
          GRANT ALL PRIVILEGES ON aivexa.* TO 'aivexa'@'localhost'; FLUSH PRIVILEGES;"
```

**3B — PostgreSQL:**

```bash
sudo -u postgres psql -c "CREATE USER aivexa WITH PASSWORD 'TROQUE-POR-SENHA-FORTE';"
sudo -u postgres psql -c "CREATE DATABASE aivexa OWNER aivexa ENCODING 'UTF8';"
```

Gere senhas fortes com `openssl rand -base64 24`. O banco escuta só em `localhost` (padrão) — não abra a porta.

## 4. Usuário do sistema e pastas

```bash
adduser --system --group --home /var/www/aivexa --shell /bin/bash aivexa
usermod -aG aivexa www-data                 # o Nginx/PHP-FPM lê os arquivos
mkdir -p /var/www/aivexa/{releases,shared}
chown -R aivexa:aivexa /var/www/aivexa
```

PHP-FPM rodando como o usuário `aivexa` (assim os arquivos gravados pelo site e pelas rotinas têm o mesmo dono):

```bash
sed -i 's/^user = www-data/user = aivexa/; s/^group = www-data/group = aivexa/' /etc/php/8.3/fpm/pool.d/www.conf
```

## 5. Enviar o pacote e criar a configuração (.env)

No **seu computador**, envie o pacote para a VPS:

```bash
scp aivexa-XXXX.zip root@IP_DA_VPS:/tmp/
```

Na VPS, extraia só o modelo de configuração e os arquivos de deploy:

```bash
cd /tmp && unzip -q aivexa-XXXX.zip .env.example 'deploy/vps/*' -d /tmp/aivexa-pacote
cp /tmp/aivexa-pacote/.env.example /var/www/aivexa/shared/.env
chown aivexa:aivexa /var/www/aivexa/shared/.env && chmod 600 /var/www/aivexa/shared/.env
nano /var/www/aivexa/shared/.env
```

Gere a chave da aplicação (uma única vez):

```bash
sed -i "s|^APP_KEY=.*|APP_KEY=base64:$(openssl rand -base64 32)|" /var/www/aivexa/shared/.env
```

Valores para a VPS (o resto conforme [PRODUCAO.md](PRODUCAO.md#2-env-de-produção)):

```dotenv
APP_ENV=production
APP_STAGE=production            # homologation no servidor de homologação
APP_DEBUG=false
APP_URL=https://app.suaclinica.com.br
APP_KEY=                        # preenchido pelo comando logo abaixo

DB_CONNECTION=mysql             # pgsql se usou PostgreSQL (DB_PORT=5432)
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=aivexa
DB_USERNAME=aivexa
DB_PASSWORD=TROQUE-POR-SENHA-FORTE

# Diferenças da VPS: Redis para cache, sessão e fila (Supervisor processa a fila)
CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1

SESSION_SECURE_COOKIE=true
SECURITY_HSTS=true
FORCE_HTTPS=true
FILE_SCANNER=clamav             # só se instalar o ClamAV (passo 10); senão deixe basic
CLAMAV_SOCKET=/var/run/clamav/clamd.ctl

MAIL_MAILER=smtp                # SMTP do seu provedor de e-mail
BACKUP_PASSWORD=                # senha forte; guarde no cofre
INSTALL_TOKEN=                  # vazio (na VPS a instalação é pelo terminal)
```

## 6. Configurar PHP, Nginx, fila e cron

```bash
P=/tmp/aivexa-pacote/deploy/vps
cp $P/php-aivexa.ini /etc/php/8.3/fpm/conf.d/99-aivexa.ini
cp $P/php-aivexa.ini /etc/php/8.3/cli/conf.d/99-aivexa.ini
systemctl restart php8.3-fpm

cp $P/nginx-aivexa.conf /etc/nginx/sites-available/aivexa
nano /etc/nginx/sites-available/aivexa                 # troque o server_name pelo seu domínio
ln -s /etc/nginx/sites-available/aivexa /etc/nginx/sites-enabled/aivexa
rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl reload nginx

cp $P/supervisor-aivexa.conf /etc/supervisor/conf.d/aivexa.conf   # ativado no passo 8
cp $P/cron-aivexa /etc/cron.d/aivexa && chmod 644 /etc/cron.d/aivexa
install -m 755 -o aivexa -g aivexa $P/atualizar.sh /var/www/aivexa/atualizar.sh
```

Permissão para o script de atualização recarregar o PHP-FPM sem pedir senha:

```bash
echo 'aivexa ALL=(root) NOPASSWD: /usr/bin/systemctl reload php8.3-fpm' > /etc/sudoers.d/aivexa
chmod 440 /etc/sudoers.d/aivexa && visudo -c
```

## 7. Certificado SSL (HTTPS)

Com o domínio já apontando para a VPS:

```bash
certbot --nginx -d app.suaclinica.com.br --redirect -m seu-email@clinica.com.br --agree-tos -n
```

A renovação é automática (`systemctl list-timers | grep certbot`). Com Cloudflare na frente, use SSL
**Full (strict)** e `TRUSTED_PROXIES=cloudflare` no `.env`.

## 8. Instalar o sistema

```bash
sudo -u aivexa /var/www/aivexa/atualizar.sh /tmp/aivexa-XXXX.zip   # extrai, cria as tabelas e publica
cd /var/www/aivexa/current
sudo -u aivexa php artisan aivexa:install                            # pergunta Super Admin e 1ª clínica
supervisorctl reread && supervisorctl update && supervisorctl status # fila ligada (2 processos RUNNING)
systemctl reload php8.3-fpm
```

> **Guarde a `APP_KEY`** (linha `APP_KEY=` do `.env`) e a `BACKUP_PASSWORD` num cofre fora da VPS.
> Sem a `APP_KEY` os dados criptografados (CPF, prontuário, 2FA) não podem ser lidos nem de um backup.

Abra `https://app.suaclinica.com.br`, entre como Super Admin (o 2FA é obrigatório) e confira
**Prontidão (produção)**. No terminal: `sudo -u aivexa php /var/www/aivexa/current/artisan aivexa:preflight`.

## 9. Atualizar para uma nova versão

Envie o novo pacote e rode **um comando** — ele faz backup do banco, migra, publica sem tirar o site do ar,
recarrega a fila e roda a verificação de prontidão:

```bash
scp aivexa-NOVA.zip root@IP_DA_VPS:/tmp/
sudo -u aivexa /var/www/aivexa/atualizar.sh /tmp/aivexa-NOVA.zip
```

Algo deu errado? Volte para a versão anterior na hora:

```bash
sudo -u aivexa /var/www/aivexa/atualizar.sh --voltar
```

(Se a versão nova mudou o banco, restaure também o backup feito pelo script — [PRODUCAO.md](PRODUCAO.md#6-backup-e-restauração).)

O pacote mais recente é gerado automaticamente pelo GitHub a cada atualização do código:
repositório → **Actions** → última execução verde do **CI** → artefato **aivexa-hostgator**.

## 10. Antivírus ClamAV (recomendado com 4 GB+ de RAM)

```bash
apt -y install clamav-daemon
systemctl stop clamav-freshclam && freshclam && systemctl start clamav-freshclam
systemctl enable --now clamav-daemon
usermod -aG clamav aivexa && systemctl restart php8.3-fpm && supervisorctl restart all
```

No `.env`: `FILE_SCANNER=clamav`, `CLAMAV_SOCKET=/var/run/clamav/clamd.ctl`, `FILE_SCANNER_FAIL_CLOSED=true`
e depois `sudo -u aivexa php /var/www/aivexa/current/artisan config:cache`.
Teste: envie um anexo com o texto EICAR — deve ser recusado.

## 11. Backups fora da VPS

O sistema já gera backup criptografado todo dia (banco) e todo domingo (anexos) em
`/var/www/aivexa/shared/storage/app/backups`. Copie para **outro lugar** — por exemplo, um bucket
S3 compatível (Backblaze B2, Wasabi, AWS) com o `rclone`:

```bash
apt -y install rclone
sudo -u aivexa rclone config                      # crie o destino "remoto" (siga as perguntas)
echo '45 3 * * * aivexa rclone copy /var/www/aivexa/shared/storage/app/backups remoto:aivexa-backups --max-age 48h' > /etc/cron.d/aivexa-backup-remoto
```

Ative também os **snapshots** do painel do provedor da VPS (semanal). Faça o ensaio de restauração
de [HOMOLOGACAO.md](HOMOLOGACAO.md#4-ensaio-de-restauração-obrigatório-antes-de-produção) uma vez por mês.

## 12. Monitoramento e manutenção

| O quê | Como |
|---|---|
| Site no ar | UptimeRobot (grátis) em `https://app…/up` a cada 5 min |
| Fila | `supervisorctl status` — 2 processos `RUNNING`; log em `shared/storage/logs/worker.log` |
| Erros da aplicação | `tail -f /var/www/aivexa/shared/storage/logs/laravel-*.log` |
| Prontidão | Super Admin → Prontidão (avisa se o cron parar) |
| Disco | `df -h` — mantenha 20% livre; backups antigos são apagados automaticamente |
| Segurança do Linux | atualizações automáticas (passo 1); reinicie a VPS quando `/var/run/reboot-required` existir |

## Problemas comuns

| Sintoma | Causa provável | Solução |
|---|---|---|
| Erro 502 Bad Gateway | PHP-FPM parado | `systemctl status php8.3-fpm` → `systemctl restart php8.3-fpm` |
| Erro 500 logo após instalar | `.env` errado ou cache antigo | ver `laravel-*.log`; corrigir `.env` e `php artisan config:cache` |
| WhatsApp/e-mails não saem | fila parada | `supervisorctl status`; `supervisorctl restart all` |
| Lembretes não disparam | cron | `cat /etc/cron.d/aivexa`; Prontidão mostra o último sinal do cron |
| "Permission denied" em storage | dono errado | `chown -R aivexa:aivexa /var/www/aivexa/shared/storage` |
| Mudou o `.env` e nada aconteceu | configuração em cache | `sudo -u aivexa php artisan config:cache` e `supervisorctl restart all` |
| Upload grande recusado | limites | `client_max_body_size` (Nginx) e `upload_max_filesize` (php-aivexa.ini) |

## Migrar da HostGator para a VPS

1. Na HostGator: Super Admin → Backups → **Gerar backup agora** (marque "incluir anexos") e baixe os dois arquivos.
2. Copie o `APP_KEY` do `.env` da HostGator — **a VPS precisa usar a mesma chave**.
3. Na VPS, faça os passos 1–7 e, no passo 8, use no `.env` a `APP_KEY` antiga em vez de gerar uma nova, e pule o `aivexa:install`.
4. Restaure o banco e os anexos conforme [PRODUCAO.md](PRODUCAO.md#6-backup-e-restauração)
   (anexos: extraia o `.zip` em `/var/www/aivexa/shared/storage/app/`).
5. Rode `sudo -u aivexa /var/www/aivexa/atualizar.sh /tmp/aivexa-XXXX.zip` (aplica migrations pendentes) e
   confira login, pacientes e financeiro.
6. Troque o DNS do domínio para o IP da VPS e atualize as URLs de webhook nos painéis (ASAAS, Cielo, Meta) se o domínio mudou.
