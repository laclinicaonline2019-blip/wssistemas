# Instalação

- **Produção (HostGator Plano Turbo / cPanel):** siga [HOSTGATOR.md](HOSTGATOR.md) — pacote `.zip` + instalador web `/instalar`.
- **Desenvolvimento:** opções abaixo.

## Opção A — Docker (recomendado)

```bash
git clone <repo> aivexa && cd aivexa
cp .env.example .env
docker compose up -d --build
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan aivexa:install      # interativo
# dados fictícios para avaliação (NUNCA em produção):
docker compose exec app php artisan db:seed --class=DemoSeeder
```

Acesse http://localhost:8080 · e-mails de teste em http://localhost:8025 (Mailpit).
O Compose usa MariaDB com binlog e usuário sem SUPER — as mesmas restrições da HostGator.

## Opção B — Local

Requisitos: PHP 8.3+ (`pdo_mysql` ou `pdo_pgsql`, `mbstring`, `openssl`, `sodium`, `intl`, `gd`, `zip`),
Composer 2, MySQL 5.7.8+/MariaDB 10.3+ (ou PostgreSQL 13+).

```bash
composer install
cp .env.example .env && php artisan key:generate
# ajuste DB_* no .env
php artisan aivexa:install
php artisan serve --port=8080
php artisan queue:work          # em outro terminal
```

## O instalador (`php artisan aivexa:install`)

1. valida requisitos do PHP · 2. conecta ao banco · 3. executa migrations · 4. sincroniza o
catálogo de permissões e cria os planos padrão · 5. cria o **Super Admin** · 6. cria a primeira
**clínica** (empresa + matriz + perfis padrão + administrador) · 7. verifica diretórios graváveis ·
8–10. testa banco, cache, armazenamento e fila.

É **idempotente** (pode ser executado novamente). Modo não interativo:

```bash
php artisan aivexa:install -n \
  --super-admin-name="Root" --super-admin-email=root@exemplo.com.br --super-admin-password='...' \
  --company-legal-name="Clínica X Ltda" --company-trade-name="Clínica X" --company-document=00000000000000 \
  --admin-name="Gestora" --admin-email=gestora@exemplo.com.br --admin-password='...'
```

Prefira o modo interativo para senhas (não ficam no histórico do shell).

## Usuários de demonstração (DemoSeeder)

Senha de todos: `Demo@12345` — `superadmin@`, `admin@`, `gestor.filial@`, `medica@`, `recepcao@`,
`financeiro@`, `enfermagem@` + `demo.aivexa.local`. Todos os dados são fictícios.
