# Troca de nome: AivexaClínica → WSCLINIC

A partir desta versão o sistema se chama **WSCLINIC** e usa a nova logo. Esta página diz o que mudou
e o que fazer num servidor que já estava instalado com o nome antigo (homologação ou produção).

## O que mudou

| Item | Antes | Agora |
|---|---|---|
| Nome exibido (telas, e-mails, PDFs, 2FA) | AivexaClínica | WSCLINIC |
| Logo, ícones, favicon e ícone do app (PWA) | logo Aivexa | logo WSCLINIC (fundo claro na tela de login) |
| Arquivo de configuração | `config/aivexa.php` | `config/wsclinic.php` |
| Comandos | `php artisan aivexa:*` | `php artisan wsclinic:*` (os nomes antigos **continuam funcionando**) |
| Modelos de WhatsApp (padrão) | `aivexa_agendamento`, `aivexa_lembrete`… | `wsclinic_agendamento`, `wsclinic_lembrete`… |
| Arquivos de backup | `aivexa-db-…`, `aivexa-arquivos-…` | `wsclinic-db-…`, `wsclinic-arquivos-…` (os antigos continuam listados e baixáveis) |
| Arquivos de exemplo da VPS | `deploy/vps/*-aivexa*` | `deploy/vps/*-wsclinic*` |
| Usuários de demonstração | `@demo.aivexa.local` | `@demo.wsclinic.local` |

Nada no banco de dados muda: não há migration nova, a trilha de auditoria e os backups antigos continuam válidos.

## Servidor já instalado — o que fazer

1. **Atualize o `.env`** (a verificação de prontidão avisa se esquecer):

   ```
   APP_NAME=WSCLINIC
   APP_BRAND_NAME="WSCLINIC"
   ```

   Ao trocar o `APP_NAME`, as sessões abertas expiram (todos entram de novo uma vez).

2. Envie o pacote novo como sempre (HostGator: [HOSTGATOR.md](HOSTGATOR.md#atualizações); VPS: `atualizar.sh`)
   e rode `php artisan config:cache`.

3. **Cron:** não precisa mudar. `schedule:run` continua igual; quem chama `aivexa:*` direto continua funcionando.

4. **WhatsApp oficial (Meta):** se a clínica usa os nomes padrão dos modelos, cadastre/aprove na Meta os
   modelos com os nomes novos (`wsclinic_agendamento`, `wsclinic_lembrete`, `wsclinic_cancelamento`,
   `wsclinic_remarcacao`, `wsclinic_ausencia`, `wsclinic_portal`) **ou**, em *Configurações → WhatsApp*,
   preencha os nomes dos modelos já aprovados (`aivexa_…`) para continuar usando-os.

5. **VPS instalada em `/var/www/aivexa`:** o `atualizar.sh` novo detecta a pasta antiga e continua usando-a
   (não precisa renomear pastas, usuário, Nginx nem Supervisor). As variáveis `AIVEXA_BASE` e
   `AIVEXA_KEEP_RELEASES` também continuam aceitas.

6. Confira em *Plataforma → Prontidão* (ou `php artisan wsclinic:preflight`).

## Verificação feita nesta versão

- 219 testes automatizados (PHPUnit) em **MariaDB** e em **PostgreSQL**;
- 6 cenários ponta a ponta no navegador (recepção, médico, financeiro, WhatsApp + IA, portal, gestão);
- teste de carga de referência;
- instalação do zero, `DemoSeeder`, todas as rotinas do cron, backup criptografado + restauração;
- navegação automática por todas as telas com os 7 perfis de demonstração (nenhum erro 500);
- atualização de uma VPS instalada com o nome antigo para esta versão.
