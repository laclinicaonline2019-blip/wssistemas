# Troca de nome: WSCLINIC → WS Facilit Saúde

A partir da versão 1.2.0 o sistema se chama **WS Facilit Saúde** (marca registrada própria) e usa a nova
logo. O identificador técnico (comandos, arquivo de configuração, backups, pastas) é `wsfacilitsaude`.
Esta página diz o que mudou e o que fazer num servidor que já estava instalado com um nome antigo
(**WSCLINIC** ou, antes dele, **AivexaClínica**).

## O que mudou

| Item | Antes | Agora |
|---|---|---|
| Nome exibido (telas, e-mails, PDFs, 2FA) | WSCLINIC | WS Facilit Saúde |
| Logo, ícones, favicon e ícone do app (PWA) | logo WSCLINIC | logo WS Facilit Saúde |
| Arquivo de configuração | `config/wsclinic.php` | `config/wsfacilitsaude.php` |
| Comandos | `php artisan wsclinic:*` | `php artisan wsfacilitsaude:*` (os nomes `wsclinic:*` e `aivexa:*` **continuam funcionando**) |
| Modelos de WhatsApp (padrão) | `wsclinic_agendamento`, `wsclinic_lembrete`… | `wsfacilitsaude_agendamento`, `wsfacilitsaude_lembrete`… |
| Arquivos de backup | `wsclinic-db-…`, `wsclinic-arquivos-…` | `wsfacilitsaude-db-…`, `wsfacilitsaude-arquivos-…` (os antigos continuam listados e baixáveis) |
| Arquivos de exemplo da VPS | `deploy/vps/*-wsclinic*` | `deploy/vps/*-wsfacilitsaude*` |
| Pasta padrão na VPS | `/var/www/wsclinic` | `/var/www/wsfacilitsaude` |
| Variáveis do `atualizar.sh` | `WSCLINIC_BASE`, `WSCLINIC_KEEP_RELEASES` | `WSFACILITSAUDE_BASE`, `WSFACILITSAUDE_KEEP_RELEASES` (as antigas continuam aceitas) |
| `User-Agent` nas chamadas ao Asaas | `WSCLINIC` | `WSFacilitSaude` |
| Usuários de demonstração | `@demo.wsclinic.local` | `@demo.wsfacilitsaude.local` |

Nada no banco de dados muda: não há migration nova, a trilha de auditoria e os backups antigos continuam válidos.

## Servidor já instalado — o que fazer

1. **Atualize o `.env`** (a verificação de prontidão avisa se esquecer). As aspas são obrigatórias por causa
   dos espaços:

   ```
   APP_NAME="WS Facilit Saúde"
   APP_BRAND_NAME="WS Facilit Saúde"
   ```

   Ao trocar o `APP_NAME`, as sessões abertas expiram (todos entram de novo uma vez) e o prefixo do cache muda.

2. Envie o pacote novo como sempre (HostGator: [HOSTGATOR.md](HOSTGATOR.md#atualizações); VPS: `atualizar.sh`)
   e rode `php artisan config:cache`.

3. **Cron:** não precisa mudar. `schedule:run` continua igual; quem chama `wsclinic:*` ou `aivexa:*` direto
   continua funcionando.

4. **WhatsApp oficial (Meta):** se a clínica usa os nomes padrão dos modelos, cadastre/aprove na Meta os
   modelos com os nomes novos (`wsfacilitsaude_agendamento`, `wsfacilitsaude_lembrete`,
   `wsfacilitsaude_cancelamento`, `wsfacilitsaude_remarcacao`, `wsfacilitsaude_ausencia`,
   `wsfacilitsaude_portal`) **ou**, em *Configurações → WhatsApp*, preencha os nomes dos modelos já aprovados
   (`wsclinic_…` ou `aivexa_…`) para continuar usando-os.

5. **VPS instalada em `/var/www/wsclinic` (ou `/var/www/aivexa`):** o `atualizar.sh` novo detecta a pasta
   antiga e continua usando-a (não precisa renomear pastas, usuário, Nginx nem Supervisor).

6. Confira em *Plataforma → Prontidão* (ou `php artisan wsfacilitsaude:preflight`).

## Histórico de nomes

| Versão | Nome |
|---|---|
| até 1.0.1 | AivexaClínica |
| 1.1.0 | WSCLINIC |
| 1.2.0 em diante | **WS Facilit Saúde** |
