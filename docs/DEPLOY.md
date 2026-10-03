# Deploy e ambientes

> **Produção atual: HostGator Plano Turbo (cPanel).** Passo a passo completo em
> [HOSTGATOR.md](HOSTGATOR.md). Em VPS (servidor próprio), siga [VPS.md](VPS.md); as seções abaixo
> descrevem a topologia para escalar além de uma VPS.

| Ambiente | `APP_ENV` | `APP_STAGE` | Integrações | Dados |
|---|---|---|---|---|
| Desenvolvimento | local | development | **mock** | DemoSeeder (fictícios) |
| Testes automatizados | testing | testing | mock | gerados pelos testes |
| Homologação | staging | homologation | **sandbox** dos gateways | fictícios/anonimizados — nunca cópia de produção sem anonimização |
| Produção | production | production | production | reais |

O estágio aparece na interface sempre que não for produção.

## VPS (evolução) — topologia recomendada

- 2+ instâncias da aplicação (imagem do `Dockerfile`) atrás de load balancer com TLS.
- MySQL/MariaDB ou PostgreSQL gerenciado (backups PITR, criptografia em repouso), dados no Brasil.
- Redis gerenciado (cache, sessão, filas).
- Workers: `php artisan queue:work --tries=3` (processo supervisionado) e um único `schedule:run` por minuto (`onOneServer`).
- Armazenamento S3 privado com SSE e bloqueio de acesso público.

## Pipeline de release

```bash
composer install --no-dev --optimize-autoloader
php artisan migrate --force          # usuário de banco com permissão de DDL, separado do da aplicação
php artisan aivexa:permissions:sync --roles
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan queue:restart
```

## Backup e restauração

- **Banco:** PITR do serviço gerenciado + `pg_dump -Fc` diário criptografado (`age`/KMS) em bucket
  de outra conta/região; retenção configurável (ex.: 35 dias diários, 12 mensais).
- **Arquivos:** versionamento do bucket + replicação entre regiões.
- **Teste de restauração mensal** em ambiente isolado, registrando data, duração e resultado.
- HostGator/cPanel: `php artisan aivexa:backup` (agendado) gera dump criptografado do banco e dos anexos; painel
  Super Admin → Backups; restauração em [PRODUCAO.md](PRODUCAO.md#6-backup-e-restauração).
- Prontuários: retenção mínima legal de 20 anos (Lei 13.787/2018) — ver LGPD.md.

## Monitoramento

- `/up` (liveness) e `/api/v1/platform/health` (readiness detalhada, autenticada).
- Logs estruturados com `request_id`; recomendado enviar para Sentry/Datadog/Grafana Loki.
- Alertas: falhas de login em massa, jobs com falha, webhooks de pagamento com erro, fila parada.
