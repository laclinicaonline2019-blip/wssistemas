# Instalação na HostGator — Plano Turbo (hospedagem compartilhada / cPanel)

Este é o ambiente de **produção-alvo** do AivexaClínica. O sistema foi adaptado para as
restrições de hospedagem compartilhada:

| Restrição da hospedagem compartilhada | Como o sistema lida |
|---|---|
| Só MySQL/MariaDB (sem PostgreSQL) | Migrations portáveis (MySQL 5.7.8+ / MariaDB 10.3+); CI testa em MariaDB |
| Sem Redis | Cache, sessões e filas no próprio banco |
| Sem processos permanentes (workers) | Fila processada pelo **cron** a cada minuto (`schedule:run`) |
| Sem privilégio SUPER no MySQL (triggers bloqueados) | Auditoria protegida por **cadeia criptográfica HMAC** + verificação diária |
| Sem Docker | Pacote `.zip` pronto (com `vendor/`) para enviar pelo Gerenciador de Arquivos |
| SSH/Composer podem não estar disponíveis | **Instalador web** em `/instalar` |
| `public_html` como raiz do domínio | Três opções de publicação (abaixo) |

> Confira no seu painel os recursos exatos do plano (versões de PHP/MySQL, SSH, limites de cron).
> Os passos abaixo usam apenas recursos padrão do cPanel.

---

## 1. Preparar o PHP

cPanel → **Selecionar versão do PHP** (ou *MultiPHP Manager*):

- Versão **8.3** (ou superior).
- Extensões: `pdo_mysql`, `mbstring`, `openssl`, `sodium`, `fileinfo`, `tokenizer`, `ctype`,
  `curl`, `intl`, `gd`, `zip`.
- Opções (*MultiPHP INI Editor*): `memory_limit = 256M`, `upload_max_filesize = 20M`,
  `post_max_size = 25M`, `max_execution_time = 120` (a instalação roda as migrations).

## 2. Criar o banco de dados

cPanel → **Bancos de dados MySQL**:

1. Crie o banco (ex.: `conta_aivexa`) e um usuário (ex.: `conta_aivexa`) com senha forte.
2. Adicione o usuário ao banco com **TODOS OS PRIVILÉGIOS**.
3. Anote os nomes completos (o cPanel acrescenta o prefixo da conta).

## 3. Gerar e enviar o pacote

No seu computador (ou baixe o artefato **aivexa-hostgator** gerado pelo GitHub Actions):

```bash
deploy/hostgator/build-release.sh      # gera dist/aivexa-<versão>.zip (~20 MB, já com vendor/)
```

cPanel → **Gerenciador de Arquivos**:

1. Crie a pasta `/home/SUA_CONTA/aivexa` (**fora** de `public_html`).
2. Envie o `.zip` para ela e use **Extrair**.
3. Copie `.env.example` para `.env` e edite:

```ini
APP_ENV=production
APP_STAGE=production
APP_DEBUG=false
APP_URL=https://clinica.seudominio.com.br
APP_KEY=                      # deixe vazio: o instalador gera

DB_CONNECTION=mysql
DB_HOST=localhost
DB_DATABASE=conta_aivexa
DB_USERNAME=conta_aivexa
DB_PASSWORD=********

SESSION_SECURE_COOKIE=true
SECURITY_HSTS=true
FORCE_HTTPS=true

MAIL_MAILER=smtp
MAIL_SCHEME=smtps
MAIL_HOST=mail.seudominio.com.br
MAIL_PORT=465
MAIL_USERNAME=nao-responda@seudominio.com.br
MAIL_PASSWORD=********
MAIL_FROM_ADDRESS=nao-responda@seudominio.com.br

INSTALL_TOKEN=uma-frase-longa-e-aleatoria-com-32-caracteres-ou-mais
```

4. Permissões: pastas `storage/` e `bootstrap/cache/` com **755** (ou 775); `.env` com **600**.

## 4. Publicar a pasta `public/`

Escolha **uma** opção (em ordem de preferência):

**A) Subdomínio/domínio apontando para `aivexa/public`** (recomendado)
cPanel → **Domínios** → criar `clinica.seudominio.com.br` com *Raiz do documento* =
`aivexa/public`. Nada do código fica exposto.

**B) Link simbólico (se houver SSH)** — para usar o domínio principal:

```bash
mv ~/public_html ~/public_html.bak
ln -s ~/aivexa/public ~/public_html
```

**C) Último recurso: tudo dentro de `public_html`**
Extraia o pacote em `public_html/` e copie `deploy/hostgator/public_html.htaccess` para
`public_html/.htaccess`. Ele desvia todas as requisições para `public/` e bloqueia `.env`,
`vendor/`, `storage/` etc. Teste em seguida: `https://seudominio/.env` deve retornar 403/404.

## 5. SSL

cPanel → **SSL/TLS Status** → execute o **AutoSSL** para o domínio. Depois, descomente o bloco
"HTTPS obrigatório" em `public/.htaccess`.

## 6. Rodar o instalador web

Acesse `https://clinica.seudominio.com.br/instalar`:

1. Informe o `INSTALL_TOKEN`.
2. Confira a verificação do servidor (PHP, extensões, permissões, banco). O `APP_KEY` é gerado
   automaticamente se o `.env` for gravável.
3. Preencha a primeira clínica (razão social, CNPJ), o **administrador da clínica** e o
   **Super Admin** da plataforma → **Instalar**.
4. Ao terminar, o instalador se desativa sozinho (`storage/app/installed.lock`, rota passa a 404).
   **Apague o valor de `INSTALL_TOKEN` no `.env`.**

Com SSH, alternativa: `php artisan aivexa:install`.

## 7. Cron (obrigatório)

cPanel → **Trabalhos Cron** → *Configuração comum*: **Uma vez por minuto** (`* * * * *`):

```bash
/usr/local/bin/php /home/SUA_CONTA/aivexa/artisan schedule:run >> /dev/null 2>&1
```

> O caminho do PHP pode variar conforme a versão escolhida (ex.: `/opt/cpanel/ea-php83/root/usr/bin/php`).
> Use o mesmo PHP do site. Se o seu plano limitar a frequência do cron, use o menor intervalo permitido —
> lembretes e mensagens serão processados nesse ritmo.

O cron processa a fila (e-mails, WhatsApp, IA, PDFs, webhooks), limpa tokens expirados e verifica
diariamente a integridade da auditoria. O painel do Super Admin → *Saúde do sistema* alerta se a
fila ficar parada (cron não configurado).

## 8. Primeiro acesso

1. Entre como **Super Admin** → configure o **2FA** (obrigatório).
2. Entre como **administrador da clínica** → *Configurações* (dados, impressão, exigir 2FA),
   *Filiais*, *Usuários* e *Perfis*.
3. *Configurações → Impressão → Teste A4 / Teste térmica* para calibrar as impressoras da recepção.

## Painel de chamadas na TV

1. *Fila e senhas → Painel da TV*: salve as configurações para gerar o endereço secreto da unidade.
2. Abra o endereço no navegador da TV/Smart TV/mini-PC, em tela cheia, e toque em **"ativar som"**
   (os navegadores só liberam áudio após uma interação).
3. O painel consulta o servidor a cada 3 segundos (sem websockets — compatível com hospedagem
   compartilhada) e anuncia a senha com sinal sonoro e voz em português.
4. Impressão de senhas: impressora térmica instalada no computador da recepção como impressora
   padrão, papel 80 mm (ou 58 mm em *Configurações → Impressão*), margens "nenhuma".

## Base CID-10 oficial (recomendado após instalar)

O sistema vem com uma **amostra** de CIDs e medicamentos (marcada como "exemplo"). Para a tabela completa:

1. Baixe no site do DATASUS (*CID-10 → arquivos em CSV*) e descompacte.
2. Entre como Super Admin → **Bases clínicas** → envie `CID-10-SUBCATEGORIAS.CSV` (e, se quiser,
   `CID-10-CATEGORIAS.CSV`). O arquivo pode ir como está (ISO-8859-1, separador `;`).
3. Medicamentos: CSV `;` com cabeçalho
   `principio_ativo;nome_comercial;apresentacao;concentracao;fabricante;via;posologia;controle`.

Diagnósticos já registrados guardam uma cópia do texto e não mudam com a importação.

## Impressão de receitas e atestados

- **A4 ou A5** (impressora comum/laser): ao emitir, o documento abre e o diálogo de impressão aparece.
  No navegador, deixe **margens "nenhuma"**, escala 100% e desmarque "cabeçalhos e rodapés".
- **Térmica (58/80 mm)**: receita simples, atestado e exames. Receita de controle especial sai só em
  A4/A5 (2 vias com quadros de comprador/fornecedor).
- **PDF**: botão "PDF" (gerado no servidor, não precisa de extensão extra do PHP além de `dom`,
  `mbstring` e `gd`, já ativas na HostGator).
- O QR Code do rodapé leva a `https://seu-dominio/validar/CÓDIGO` — a farmácia/empresa confere a
  autenticidade sem login. Por isso o `APP_URL` do `.env` precisa ser o domínio real com `https`.

## Anexos (exames, imagens)

Limite padrão de 10 MB por arquivo (`UPLOAD_MAX_KB`). No cPanel → *Select PHP Version → Options*,
ajuste `upload_max_filesize` e `post_max_size` para pelo menos esse valor. Os arquivos ficam em
`storage/app/private` (fora do `public_html`) — inclua essa pasta no backup.

## Pagamentos online (ASAAS / Cielo)

1. *Administração → Pagamentos online → Adicionar gateway*. Comece em **SANDBOX** (ASAAS).
2. Copie a **URL de webhook** (e, no ASAAS, o **token**) e cadastre no painel do gateway.
   ASAAS: *Integrações → Webhooks* (eventos de cobrança). Cielo: *URL de Notificação* e *URL de Mudança de Status*.
3. O site precisa estar em **HTTPS** com o `APP_URL` correto — os gateways só enviam avisos para HTTPS.
4. O cron (item 7) também roda a sincronização de cobranças a cada 10 minutos.
5. Faça um pagamento de teste e confira em *Últimos avisos recebidos* e na conta a receber.
6. **Split Cielo online** (`Cielo — API E-commerce com split`): informe MerchantId/MerchantKey e
   ClientId/ClientSecret, cadastre a URL de notificação mostrada na tela e, em *Repasses*, o **ID de
   subordinado Cielo** de cada médico. Não precisa de nada instalado no servidor (o cartão é tokenizado
   no navegador do paciente).

## Convênios e faturamento TISS

1. *Convênios → Procedimentos (TUSS)*: importe a planilha TUSS da ANS (salve como CSV `codigo;descricao`)
   ou cadastre os procedimentos usados. Os exemplos da demonstração estão marcados "confira na TUSS".
2. *Convênios e tabelas*: cadastre a operadora com o **registro ANS** e o **código do prestador** que a
   operadora deu à clínica; crie a tabela de valores contratada; marque os médicos credenciados.
3. *Filiais*: informe o **CNES** de cada unidade. *Médicos → Agenda*: vincule o procedimento TUSS ao tipo de
   atendimento (ex.: Consulta → 10101012).
4. No cadastro do paciente, escolha o convênio cadastrado na carteirinha.
5. Fluxo: chegada gera a guia → conferir → "pronta" → *Lotes* → fechar (XML validado) → baixar e enviar no
   portal da operadora → registrar protocolo → registrar o retorno e tratar glosas.
6. Nada disso precisa de recurso extra no servidor: a validação usa a extensão `libxml`/`dom` do PHP,
   presente na HostGator.

## Portal do paciente

1. Endereço para os pacientes: `https://SEU_DOMINIO/portal/<identificador-da-clinica>` (aparece em
   *Configurações → Portal do paciente*, com as regras de agendamento/cancelamento online).
2. Configure o **SMTP** no `.env` (seção de e-mail acima, conta de e-mail do cPanel) para enviar os links de
   ativação e "esqueci a senha". Sem SMTP, a recepção copia o link ou envia pelo WhatsApp na ficha do paciente.
3. Na ficha do paciente: *Portal do paciente → Gerar link de ativação*. Exames anexados só aparecem no portal
   depois de "Liberar no portal".

## Atualizações

1. Gere o novo pacote (ou baixe o artefato do CI).
2. Faça **backup** (seção abaixo).
3. Envie e extraia por cima da pasta `aivexa/` (preserve `.env` e `storage/`).
4. Rode as migrations: via SSH `php artisan migrate --force && php artisan aivexa:permissions:sync --roles`,
   ou crie temporariamente um cron único com esse comando.
5. Limpe os caches: `php artisan optimize:clear` (ou apague `bootstrap/cache/*.php`).

## Backup

- cPanel → **Backup** / **JetBackup** (se disponível): banco + arquivos diários.
- Recomendado também: backup externo do banco (`mysqldump` via cron) criptografado e enviado para
  fora da hospedagem, e **teste de restauração mensal** em outro ambiente.
- Guarde o `.env` (principalmente `APP_KEY`) em cofre separado: sem ele, os segredos de 2FA e
  a verificação da cadeia de auditoria não podem ser recuperados.

## Limites da hospedagem compartilhada — quando migrar para VPS

A hospedagem compartilhada atende bem o início da operação (uma ou poucas clínicas). Considere um
**VPS** (o sistema já suporta PostgreSQL, Redis e workers dedicados sem mudanças de código) quando:

- houver muitas clínicas/usuários simultâneos ou lentidão em horários de pico;
- o atendimento por IA/WhatsApp exigir respostas em segundos (a fila por cron tem até ~1 min de atraso);
- for necessário processamento pesado (OCR, áudio, relatórios grandes);
- houver exigência de isolamento/controle de infraestrutura maior (LGPD, auditorias externas).
