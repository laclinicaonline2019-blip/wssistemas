# Pesquisa Farma — colocar em produção

Roteiro em 4 etapas: **servidor → WhatsApp e IA → homologação (1–2 televendas + 1 farmácia) → abertura**.
Nenhuma etapa pula a anterior: o comando `php artisan pf:verificar` só fica verde quando está tudo certo.

---

## 1. Servidor

Recomendado: **VPS Ubuntu 24.04** (2 vCPU, 4 GB RAM já atende o início), com domínio próprio
(ex.: `app.seudominio.com.br`) e HTTPS.

```bash
sudo apt update && sudo apt install -y nginx mariadb-server php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring \
  php8.3-xml php8.3-curl php8.3-zip php8.3-intl php8.3-gd unzip git certbot python3-certbot-nginx
curl -sS https://getcomposer.org/installer | php && sudo mv composer.phar /usr/local/bin/composer
```

Banco de dados:

```bash
sudo mysql -e "CREATE DATABASE pesquisafarma CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'pesquisafarma'@'localhost' IDENTIFIED BY 'SENHA-FORTE-AQUI';
GRANT ALL ON pesquisafarma.* TO 'pesquisafarma'@'localhost'; FLUSH PRIVILEGES;"
```

Código:

```bash
sudo mkdir -p /var/www && cd /var/www
sudo git clone <repositório> wssistemas && cd wssistemas/pesquisafarma
sudo composer install --no-dev --optimize-autoloader
sudo cp .env.production.example .env      # preencha (veja a etapa 2)
sudo php artisan key:generate
sudo php artisan migrate --force
sudo php artisan pf:admin seu.email@empresa.com.br 'SenhaForte@2026' --nome="Seu Nome"
sudo chown -R www-data:www-data storage bootstrap/cache
sudo php artisan config:cache && sudo php artisan route:cache && sudo php artisan view:cache
```

> **Não rode o `DemoSeeder` em produção** (ele cria farmácias e usuários fictícios).

Nginx (`/etc/nginx/sites-available/pesquisafarma`):

```nginx
server {
    server_name app.seudominio.com.br;
    root /var/www/wssistemas/pesquisafarma/public;
    index index.php;
    client_max_body_size 12M;
    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ { include snippets/fastcgi-php.conf; fastcgi_pass unix:/run/php/php8.3-fpm.sock; }
    location ~ /\.(?!well-known) { deny all; }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/pesquisafarma /etc/nginx/sites-enabled/ && sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d app.seudominio.com.br          # HTTPS gratuito (Let's Encrypt)
```

**Agendador (obrigatório)** — pós-venda, prazos das farmácias e fechamento de comissões:

```bash
echo '* * * * * www-data cd /var/www/wssistemas/pesquisafarma && php artisan schedule:run >> /dev/null 2>&1' | sudo tee /etc/cron.d/pesquisafarma
```

**Backup diário** (banco + arquivos de notas e receitas):

```bash
sudo mkdir -p /var/backups/pesquisafarma
echo '30 2 * * * root mysqldump pesquisafarma | gzip > /var/backups/pesquisafarma/db-$(date +\%F).sql.gz && tar czf /var/backups/pesquisafarma/arquivos-$(date +\%F).tgz -C /var/www/wssistemas/pesquisafarma storage/app && find /var/backups/pesquisafarma -mtime +30 -delete' | sudo tee /etc/cron.d/pesquisafarma-backup
```

Copie os backups para fora do servidor (outro provedor/nuvem) pelo menos uma vez por semana.

---

## 2. WhatsApp e IA reais

### Escolha do WhatsApp

| Provedor | Vantagem | Atenção |
|---|---|---|
| **Z-API** (recomendado para começar) | Rápido de configurar, envia texto livre para farmácias, pós-venda e promoções | Não oficial: risco de bloqueio do número em disparos em massa. Use um número só para o sistema e divulgue promoções só para quem aceitou |
| **Evolution API** | Mesma ideia, auto-hospedada (sem mensalidade do provedor) | Você mantém o servidor da Evolution; ative "webhook base64" |
| **Meta (oficial)** | Oficial, sem risco de bloqueio | Mensagens que **a empresa inicia** (cotação para a farmácia, pós-venda depois de 24 h, promoções) exigem **modelos aprovados pela Meta**. Hoje o sistema envia texto livre: com a Meta, só as respostas dentro de 24 h da última mensagem do cliente funcionam. Usar a Meta exige cadastrar os modelos antes (podemos implementar) |

### Z-API

1. Crie a instância em z-api.io e conecte o número lendo o QR Code.
2. No `.env`: `WHATSAPP_DRIVER=zapi`, `ZAPI_INSTANCE`, `ZAPI_TOKEN`, `ZAPI_CLIENT_TOKEN` (Segurança → Token de segurança da conta).
3. Gere o segredo: `php -r "echo bin2hex(random_bytes(20));"` → `WHATSAPP_WEBHOOK_SECRET`.
4. Na Z-API, em *Webhooks → Ao receber*, coloque `https://app.seudominio.com.br/webhook/whatsapp/SEU-SEGREDO`
   (o endereço exato aparece em **Configurações** no painel).

### Evolution API

`WHATSAPP_DRIVER=evolution`, `EVOLUTION_URL`, `EVOLUTION_INSTANCE`, `EVOLUTION_API_KEY`; webhook igual ao da Z-API,
evento `MESSAGES_UPSERT`, com **Webhook Base64** ligado.

### Meta (oficial)

`WHATSAPP_DRIVER=meta`, `META_PHONE_NUMBER_ID`, `META_ACCESS_TOKEN` (token permanente de usuário do sistema),
`META_APP_SECRET` (o sistema confere a assinatura de cada chamada) e `META_VERIFY_TOKEN`. Webhook com o mesmo
endereço, campo `messages`.

### IA (Claude)

1. Crie a chave em console.anthropic.com (Settings → API keys) e defina um limite mensal de gastos.
2. `ANTHROPIC_API_KEY=...` no `.env` (modelo padrão `claude-opus-5-5`).
3. Ligue em **Configurações → Usar IA**.

### Conferir

```bash
php artisan config:cache
php artisan pf:testar-whatsapp 11999998888       # chega uma mensagem de teste no seu celular?
php artisan pf:verificar --testar-ia             # tudo OK? (só AVISO é aceitável)
```

---

## 3. Homologação — 1 a 2 televendas e 1 farmácia (3 a 5 dias)

**Antes:** cadastre a cidade, os bairros (com coordenadas; importação por CSV em *Cidades e bairros*), **uma farmácia real**
com CNPJ, WhatsApp, % de comissão, ciclo e regras de entrega, os brindes e as regras de pontos. Crie os usuários dos
televendas e o acesso da farmácia. Cada um ativa a **verificação em duas etapas** (menu 🔐 Segurança; obrigatória para
administradores).

Use **clientes de teste da equipe** (celulares de vocês) nos dois primeiros dias. Marque cada item:

| # | Teste | Quem | OK |
|---|---|---|---|
| 1 | Celular novo manda "oi" → robô faz o cadastro (nome, CEP, número, LGPD, promoções) | cliente teste | ☐ |
| 2 | Manda lista por texto → cotação aparece no painel com os itens separados | televendas | ☐ |
| 3 | Manda foto de receita → IA monta os itens; televendas confere | televendas | ☐ |
| 4 | Envia para a farmácia → farmácia recebe no WhatsApp e responde pelo link no celular | farmácia | ☐ |
| 5 | Farmácia responde também pelo painel (com login + 2FA) | farmácia | ☐ |
| 6 | Ranking e mensagem ao cliente com bairro, distância, entrega e endereço | televendas | ☐ |
| 7 | Cliente responde "1" → pedido chega à farmácia e ao cliente | cliente / farmácia | ☐ |
| 8 | Pós-venda chega (ou botão "Enviar pesquisa agora"); cliente responde "S 10" + foto da nota | cliente | ☐ |
| 9 | Nota conferida → comissão no pedido e pontos no cartão; cliente recebe a confirmação | televendas | ☐ |
| 10 | Cliente escreve PONTOS e FARMÁCIAS | cliente | ☐ |
| 11 | Área do cliente: login por código, cartão, troca de brinde | cliente | ☐ |
| 12 | Balcão: farmácia consulta o cartão e registra uma venda sem cotação | farmácia | ☐ |
| 13 | Mesma nota enviada duas vezes é bloqueada | televendas | ☐ |
| 14 | Promoção enviada pela farmácia → aprovada → divulgada só para a cidade | admin | ☐ |
| 15 | Relatórios: por farmácia, por cliente, por cidade, televendas, tempos; exportar Excel | admin | ☐ |
| 16 | Fechamento de comissão gerado e marcado como pago | admin | ☐ |
| 17 | Atividades mostram tudo o que cada televendas fez | admin | ☐ |

No 3º dia, atenda **clientes reais** com essa única farmácia. Anote dúvidas e travas dos televendas e da farmácia.
Critério para abrir: todos os itens OK, **nenhum erro no log** (`storage/logs`), tempos médios aceitáveis em
*Relatórios → Tempos*.

---

## 4. Abertura

1. Desative usuários e dados de teste (ou anonimize os clientes de teste em *Clientes → LGPD*).
2. Cadastre as demais farmácias e bairros; confira com `php artisan pf:verificar`.
3. Divulgue o número do WhatsApp; acompanhe nos primeiros dias **Painel → Entregas com problema**,
   **Compras pela cotação** e **Notas para conferir**.

### Atualizar o sistema depois

```bash
cd /var/www/wssistemas && sudo git pull && cd pesquisafarma
sudo composer install --no-dev --optimize-autoloader && sudo php artisan migrate --force
sudo php artisan config:cache && sudo php artisan route:cache && sudo php artisan view:cache
```

### Segurança já implementada

Verificação em duas etapas (obrigatória para administradores), bloqueio de conta após 5 senhas erradas, código do
cliente invalidado após 5 tentativas, arquivos do WhatsApp verificados pelo conteúdo (HTML/script disfarçado é
descartado; só imagem/PDF abrem no navegador), assinatura da Meta conferida, proteção contra SSRF no download de mídia,
cabeçalhos de segurança (CSP, anti-clickjacking, HSTS), planilhas sem fórmulas maliciosas, cartão com dígito
verificador no balcão, nota fiscal repetida bloqueada, registro de todas as ações, exportação e anonimização LGPD.
