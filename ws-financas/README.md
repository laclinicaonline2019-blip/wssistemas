<p align="center"><img src="public/assets/img/logo.webp" width="360" alt="WS Finanças em Dia — Seu dinheiro em dia."></p>

# WS Finanças em Dia — plataforma SaaS

Gestão financeira por assinatura para pessoas físicas e empresas: o **painel WS Finanças** (mesmo visual e funções do sistema original) agora com banco de dados, várias empresas por cliente, usuários com permissões, **assistente no WhatsApp oficial**, cobrança recorrente pelo **Asaas** e painel comercial do proprietário.

**Stack:** PHP 8.3 · Laravel 13 · MySQL/MariaDB · JavaScript/CSS próprios (sem build) · roda na **HostGator compartilhada** (sem SSH, sem processos permanentes) e migra para VPS só trocando o `.env`.

## Documentação
| | |
|---|---|
| [ARQUITETURA.md](docs/ARQUITETURA.md) | análise do sistema atual, arquitetura, recursos preservados e novos, planos, **serviços externos e custos** |
| [HOSTGATOR.md](docs/HOSTGATOR.md) | compatibilidade do plano, instalação passo a passo, cron, homologação, atualização, migração para VPS |
| [INTEGRACOES.md](docs/INTEGRACOES.md) | WhatsApp Business (Cloud API), Asaas, IA |
| [MIGRACAO.md](docs/MIGRACAO.md) | importar os dados do armazenamento local do sistema antigo |
| [SEGURANCA.md](docs/SEGURANCA.md) · [BACKUP.md](docs/BACKUP.md) | controles de segurança, LGPD, backups e restauração |
| [HOMOLOGACAO.md](docs/HOMOLOGACAO.md) | roteiro de aceite antes de comercializar |

## Desenvolvimento local
```bash
composer install
cp .env.example .env    # ajuste DB_* (ou DB_CONNECTION=sqlite)
php artisan key:generate
php artisan wsf:install --name="Dono" --email=dono@exemplo.com
php artisan db:seed --class=DemoSeeder   # opcional: cliente@demo.wsfinancas.local / Demo@12345
php artisan serve
```

## Testes
```bash
php artisan test                         # SQLite em memória
DB_CONNECTION=mysql DB_DATABASE=wsf_test DB_USERNAME=... DB_PASSWORD=... php artisan test   # MySQL/MariaDB
vendor/bin/pint --test
```
42 testes cobrem: interpretação das mensagens (incluindo os exemplos do briefing), isolamento entre clientes e empresas (inclusive no banco), contador com empresas autorizadas, papéis, WhatsApp (assinatura do webhook, número não autorizado, confirmação, duplicidade, reentrega, desfazer/corrigir, plano e suspensão), cobrança (teste → atraso → suspensão → webhook Asaas → reativação, idempotência, baixa manual, limites, cancelamento + eliminação LGPD), recuperação de senha por e-mail, 2FA obrigatório, cabeçalhos/XSS, importação local sem duplicar, backup criptografado com restauração verificada, auditoria contra adulteração, relatórios CSV/PDF e carregamento de todas as telas.

## Pacote de instalação
```bash
deploy/build-zip.sh 1.0.0    # → dist/ws-financas-1.0.0.zip
```
O GitHub Actions (`.github/workflows/ws-financas.yml`) roda os testes em SQLite e MariaDB e publica o ZIP como artefato.

## Licença
Proprietária — todos os direitos reservados.
