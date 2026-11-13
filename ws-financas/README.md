<p align="center"><img src="public/assets/img/logo.webp" width="360" alt="WS Finanças em Dia — Seu dinheiro em dia."></p>

# WS Finanças em Dia — plataforma SaaS

Gestão financeira por assinatura para pessoas físicas e empresas: o **painel WS Finanças** (mesmo visual e funções do sistema original) agora com banco de dados, várias empresas por cliente, usuários com permissões, **assistente no WhatsApp oficial**, cobrança recorrente pelo **Asaas** e painel comercial do proprietário.

**Stack:** PHP 8.3 · Laravel 13 · MySQL/MariaDB · JavaScript/CSS próprios (sem build) · roda na **HostGator compartilhada** (sem SSH, sem processos permanentes) e migra para VPS só trocando o `.env`.

## Documentação
| | |
|---|---|
| [ARQUITETURA.md](docs/ARQUITETURA.md) | análise do sistema atual, arquitetura, recursos preservados e novos, planos, **serviços externos e custos** |
| [INSTALACAO-CPANEL.md](docs/INSTALACAO-CPANEL.md) | **instalação e atualização só pelo cPanel e navegador** (sem terminal): instalador web, atualizações pelo painel com backup e reversão, proteções, limitações |
| [HOSTGATOR.md](docs/HOSTGATOR.md) | compatibilidade do plano, instalação passo a passo, cron, homologação, atualização, migração para VPS |
| [INTEGRACOES.md](docs/INTEGRACOES.md) | WhatsApp Business (Cloud API), Asaas, IA |
| [MIGRACAO.md](docs/MIGRACAO.md) | importar os dados do armazenamento local do sistema antigo |
| [SEGURANCA.md](docs/SEGURANCA.md) · [BACKUP.md](docs/BACKUP.md) | controles de segurança, LGPD, backups e restauração |
| [AUDITORIA.md](docs/AUDITORIA.md) | trilha de auditoria: formatos v1/v2, verificação, âncora diária, chave, proteções no banco, recuperação, limitações |
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
Os testes cobrem: instalador web (código, CSRF, APP_KEY, bloqueio, fluxo completo com MySQL), `.env` à prova de injeção, validação e assinatura de pacotes de atualização, interpretação das mensagens (incluindo os exemplos do briefing), isolamento entre clientes e empresas (inclusive no banco), contador com empresas autorizadas, papéis, WhatsApp (assinatura do webhook, número não autorizado, confirmação, duplicidade, reentrega, desfazer/corrigir, plano e suspensão), cobrança (teste → atraso → suspensão → webhook Asaas → reativação, idempotência, baixa manual, limites, cancelamento + eliminação LGPD), recuperação de senha por e-mail, 2FA obrigatório, cabeçalhos/XSS, importação local sem duplicar, backup criptografado com restauração verificada, auditoria (JCS/RFC 8785, compatibilidade v1 dos registros reordenados pelo MySQL, transição v1→v2, adulteração, exclusão, bifurcação, rebaixamento, chave trocada, âncora, triggers, restauração sem perda), relatórios CSV/PDF e carregamento de todas as telas.

## Pacote de instalação e atualização
```bash
deploy/build-zip.sh 1.1.1 1.1.0    # → dist/ws-financas-1.1.1.zip (versão, versão mínima de origem)
WSF_UPDATE_SIGNING_KEY=... deploy/build-zip.sh 1.1.0   # assinado (chaves: php deploy/assinar-pacote.php gerar-chaves)
```
O mesmo ZIP serve para instalar (extrair no cPanel) e para atualizar pelo painel: arquivos na raiz, `vendor/` incluído,
manifesto `wsf-update.json` e `CHECKSUMS.sha256`; nunca contém `.env`, dados de `storage`, testes ou caches.

## Novidades da 1.1.1 (auditoria)
- Corrige o falso alarme "cadeia quebrada" causado pela reordenação de chaves do JSON no MySQL: os registros antigos são conferidos por compatibilidade, sem alterar nada (método registrado no relatório).
- Formato v2 (ativação manual e irreversível): HMAC sobre JSON canônico RFC 8785, com versão, chave e data UTC com microssegundos.
- Gravação serializada no banco (sem bifurcação), triggers append-only/antirrebaixamento, índice único em `prev_hash`.
- Verificação em lotes com status separados e relatório assinado; âncora diária por e-mail; health check da auditoria.
- Restauração de backup e reversão de atualização **sem perda de dados**; retenção LGPD não apaga mais a auditoria.
- Reversão pelo painel limpa os caches compilados da versão que sai; fuso `-03:00` só na conexão da aplicação.
- Detalhes e limitações: [docs/AUDITORIA.md](docs/AUDITORIA.md).

## Novidades da 1.1.0
- Instalador web que funciona **sem APP_KEY e sem terminal** (a 1.0.0 parava com erro 500): código temporário lido no servidor, HTTPS obrigatório, requisitos, geração da APP_KEY e segredos, validação do MySQL (conexão, versão, privilégios), migrações controladas, administrador principal e bloqueio definitivo.
- Atualizações pelo painel do proprietário com backup, conferência SHA-256, assinatura Ed25519 opcional, troca atômica de pastas, reversão automática e manual.
- Proteção por `.htaccess` de todas as pastas internas e arquivos sensíveis; HTTPS forçado; aplicação de migrações pendentes pela tela.
- Correção: `TRUSTED_PROXIES` (Cloudflare/proxy) agora é lido corretamente do `.env`.
O GitHub Actions (`.github/workflows/ws-financas.yml`) roda os testes em SQLite, MariaDB, MySQL 8 e Percona 5.7.44 e publica o ZIP como artefato.

## Licença
Proprietária — todos os direitos reservados.
