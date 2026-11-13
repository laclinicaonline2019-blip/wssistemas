# Instalação e atualização pelo cPanel (sem Terminal, SSH ou PowerShell)

Versão 1.1.0. Tudo é feito pelo **Gerenciador de Arquivos**, pelas telas do cPanel e pelo navegador.

> **Por que a 1.0.0 não instalava sem terminal:** o instalador da 1.0.0 exigia que a `APP_KEY` já existisse no `.env`;
> com ela vazia, o Laravel parava com erro 500 antes de abrir o instalador. A 1.1.0 abre o instalador mesmo sem
> `APP_KEY` e gera a chave pela própria tela.

Ambiente de testes deste projeto:

| Item | Valor |
|---|---|
| Domínio | `https://teste.wsfinancasemdia.com.br` |
| Pasta do sistema | `/home3/wand5158/wsfinancas-app-teste/ws-financas-1.0.0` |
| Raiz do domínio (Document Root) | `/home3/wand5158/wsfinancas-app-teste/ws-financas-1.0.0/public` |
| Banco | `wand5158_wsfin_teste` |
| Usuário do banco | `wand5158_wsfin_user` |

O nome da pasta pode continuar `ws-financas-1.0.0`: a versão real fica no arquivo `VERSION`.

---

## 1. Antes de começar (5 min)

1. **cPanel → MultiPHP Manager:** `teste.wsfinancasemdia.com.br` em **PHP 8.3**.
2. **cPanel → Selecionar versão do PHP → Extensões:** ative `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `zip`, `curl`, `sodium`, `gd`, `intl`.
3. **cPanel → MultiPHP INI Editor** (domínio de teste): `memory_limit = 256M`, `max_execution_time = 120`, `upload_max_filesize = 64M`, `post_max_size = 64M`.
4. **cPanel → SSL/TLS Status:** rode o **AutoSSL** e espere o cadeado no subdomínio. Sem HTTPS o instalador não abre.
5. **cPanel → Bancos de dados MySQL:** confira que `wand5158_wsfin_user` está **adicionado** a `wand5158_wsfin_teste` com **TODOS OS PRIVILÉGIOS**. Tenha a senha em mãos.

## 2. Enviar os arquivos da 1.1.0

O ZIP traz os arquivos **na raiz**: ele é extraído direto dentro da pasta do sistema.

1. **Gerenciador de Arquivos** → abra `/home3/wand5158/wsfinancas-app-teste/ws-financas-1.0.0`.
2. **Carregar** → envie `ws-financas-1.1.0.zip` para essa pasta.
3. Selecione o ZIP → **Extrair** → destino: a própria pasta → confirme. Os arquivos antigos são substituídos; o seu **`.env` não é tocado** (o pacote não contém `.env`).
4. Apague o ZIP depois de extrair.
5. Se ainda não houver `.env`: copie `.env.example` → renomeie a cópia para `.env`. **Não precisa preencher APP_KEY nem senha.**
6. Ative "Mostrar arquivos ocultos" (Configurações, no canto superior direito) para ver `.env` e `.htaccess`.

> Já havia rodado a 1.0.0 com dados? Faça o mesmo procedimento (dados e `.env` são preservados). Em seguida o
> passo 3 roda só as migrações novas. A partir da 1.1.0, as próximas versões são aplicadas pelo painel (seção 5).

## 3. Instalar pelo navegador (10–15 min)

Abra **https://teste.wsfinancasemdia.com.br/instalar**.

1. **Código de instalação.** O instalador grava um código em
   `ws-financas-1.0.0/storage/app/instalador/CODIGO-DE-INSTALACAO.txt`. Abra o arquivo no Gerenciador de Arquivos (clique com o botão direito → **View**), copie o código e cole na tela.
   - O arquivo fica fora da pasta pública: só quem entra no cPanel consegue lê-lo. O código vale 2 horas.
   - Após 5 tentativas erradas ele é trocado (abra o arquivo de novo).
2. **Requisitos da hospedagem.** Itens "Obrigatório" em vermelho precisam ser resolvidos (a tela diz onde). Ao continuar, o instalador:
   - cria as pastas de armazenamento;
   - ajusta as permissões (755 nas pastas, 600 no `.env`);
   - protege as pastas internas com `.htaccess`;
   - limpa caches antigos.
3. **Chave de segurança.** Gera a `APP_KEY` (256 bits) e também, se ainda não existirem:
   - a chave da auditoria;
   - a senha dos backups;
   - os tokens dos webhooks.
   Tudo é gravado só no `.env`. Uma `APP_KEY` já existente é **mantida**: trocá-la tornaria ilegíveis os dados já criptografados.
4. **Banco de dados.** Servidor `localhost`, porta `3306`, banco `wand5158_wsfin_teste`, usuário `wand5158_wsfin_user` e a senha. O teste confere:
   - a conexão;
   - a versão do MySQL/MariaDB;
   - os privilégios, criando, alterando e apagando tabelas de teste.
   Só depois disso grava no `.env`.
5. **Endereço e ambiente.** `https://teste.wsfinancasemdia.com.br` e **Homologação**. O ambiente de testes ganha uma faixa amarela.
6. **Migrações.** A tela mostra a lista de tabelas a criar. Clique uma vez e o instalador:
   - cria as tabelas, com trava contra execução dupla;
   - cadastra os planos padrão;
   - nunca apaga tabelas nem dados existentes.
7. **Administrador principal.** Nome, e-mail e senha (mínimo de 10 caracteres, com maiúsculas, minúsculas, números e símbolos). Se já existir um proprietário no banco, o instalador **não cria outro**.
8. **Concluir.** O instalador é **bloqueado definitivamente**:
   - cria `storage/app/installed` e grava `WSF_INSTALLER_LOCKED=true` no `.env`;
   - apaga o código, a sessão de instalação e as cópias temporárias do `.env`;
   - passa a responder `/instalar` com "não encontrada" (404).

## 4. Depois de instalar (10 min)

1. **Cron (obrigatório).** cPanel → **Trabalhos Cron** → "Uma vez por minuto (`* * * * *`)":
   ```bash
   /usr/local/bin/ea-php83 /home3/wand5158/wsfinancas-app-teste/ws-financas-1.0.0/artisan schedule:run >> /dev/null 2>&1
   ```
   Se o cPanel acusar erro, use `/opt/cpanel/ea-php83/root/usr/bin/php` no início. Em **Proprietário → Sistema e backups** aparece "último sinal" quando o cron funciona.
2. **Guardar o `.env`.** Gerenciador de Arquivos → selecione `.env` → **Baixar** → guarde fora do servidor, em local seguro. Ele contém a `APP_KEY` e a `BACKUP_PASSWORD`; sem elas, backups e dados criptografados não podem ser lidos.
3. **Entrar** em `https://teste.wsfinancasemdia.com.br/entrar` e ativar a verificação em duas etapas (obrigatória para o proprietário).
4. **E-mail, WhatsApp, Asaas e IA.** Edite o `.env` (botão direito → **Edit**). Veja `INTEGRACOES.md`. Os tokens dos webhooks já foram gerados.

## 5. Atualizar pelo painel (sem SSH)

**Proprietário → Atualizações**:

1. **Enviar o pacote**, de uma destas formas:
   - pelo formulário da tela, se o pacote for menor que o limite mostrado;
   - pelo **Gerenciador de Arquivos**, para `storage/app/updates/pacotes/` (sem limite).
2. **Analisar.** Nada é alterado ainda. A análise confere:
   - se é um pacote do WS Finanças e se a versão é mais nova;
   - a versão mínima de origem e o PHP mínimo;
   - a assinatura digital;
   - os caminhos dentro do ZIP: recusa `..`, links simbólicos, `.env` e dados em `storage/`;
   - o espaço em disco.
   A tela mostra o **SHA-256** do pacote.
3. **Iniciar.** Confirme com a sua senha. As etapas rodam uma a uma, cada uma é uma requisição curta e, se a conexão cair, ela continua de onde parou:
   - validar;
   - **backup criptografado do banco**;
   - extrair numa área temporária;
   - **conferir o SHA-256 de cada arquivo**;
   - modo de manutenção (só a tela de atualização continua acessível);
   - **troca das pastas por renomeação** (instantânea; a versão anterior fica guardada);
   - migrações;
   - conferência (versão, tabelas, rotas);
   - saída da manutenção.
4. **Se algo falhar** depois da troca, a versão anterior volta sozinha:
   - os arquivos voltam;
   - as migrações são desfeitas;
   - o banco é restaurado do backup;
   - tabelas criadas pela tentativa são removidas.
   Antes da troca, nada é alterado.
5. **Reverter depois.** Use o botão **Reverter** no histórico. Vale para a última atualização concluída. Há dois modos:
   - **manter os dados:** desfaz as mudanças de banco daquela versão e volta os arquivos;
   - **restaurar o backup do banco:** volta os arquivos e o banco ao ponto anterior. Os lançamentos feitos depois da atualização são perdidos.

O que **nunca** é alterado por uma atualização:
- o `.env`;
- a pasta `storage/` (dados, backups, logs);
- itens próprios do site dentro de `public/` (por exemplo `.well-known`, usada pelo SSL, e `.user.ini`, criado pelo INI Editor).

### Assinatura dos pacotes (recomendado em produção)

O fornecedor gera um par de chaves com `deploy/assinar-pacote.php gerar-chaves`. Coloque **somente a chave pública** no `.env`:

```bash
WSF_UPDATE_PUBLIC_KEY=<chave pública em base64>
```

Com a chave configurada, o painel só aceita pacotes assinados por quem tem a chave secreta. Assim, nem quem roubar a senha do proprietário consegue instalar código adulterado.

Sem a chave, o painel aceita pacotes sem assinatura **somente** se você confirmar que o SHA-256 mostrado é igual ao informado pelo fornecedor.

## 6. Proteções de arquivos incluídas

- A raiz do domínio aponta para `/public`: o `.env`, a pasta `storage`, o `vendor` e o código ficam fora do alcance da web.
- Cada pasta interna tem um `.htaccess` que nega acesso: `app`, `bootstrap`, `config`, `database`, `deploy`, `docs`, `resources`, `routes`, `storage`, `vendor`.
- Um `.htaccess` na raiz do projeto nega `.env*`, `composer.*`, `artisan`, `VERSION`, os manifestos, `*.md`, `*.log`, `*.sql` e `*.zip`. Testado no Apache: tudo responde 403 mesmo se a raiz do domínio for configurada errada.
- O `public/.htaccess`:
  - força HTTPS, liberando apenas `/.well-known` para o SSL;
  - bloqueia arquivos ocultos e extensões sensíveis;
  - desativa a listagem de pastas.
- O instalador recusa continuar se a raiz do domínio não for `/public`.
- O `.env` fica com permissão 600.
- O código de instalação e a pasta de atualizações ficam em `storage/` com `.htaccess` de bloqueio e permissão 700/600.

## 7. Limitações da hospedagem compartilhada e alternativas seguras

| Limitação | Como o sistema lida |
|---|---|
| O PHP não cria o **cron** sozinho (exigiria guardar uma senha/token do cPanel no servidor). | Você cadastra o cron uma vez (seção 4). O comando exato aparece ao concluir a instalação. |
| Não há **composer** no servidor. | O pacote já traz a pasta `vendor` (~27 MB compactado). |
| Limite de **upload** do PHP. | Pacotes grandes são enviados pelo Gerenciador de Arquivos para `storage/app/updates/pacotes/`. |
| **Tempo máximo de execução** do PHP. | Instalador e atualização trabalham em etapas curtas e retomáveis. Com banco muito grande (backup demorado), gere antes o backup pelo cPanel → **Backup** e, se a etapa expirar, recarregue a página: ela continua. |
| PHP rodando com **usuário diferente** do dono dos arquivos (raro na HostGator). | A tela de Atualizações mostra "Manual". Atualize como na seção 2 (extraia o ZIP por cima, o `.env` e o `storage` são preservados) e depois clique em **Proprietário → Sistema e backups → Aplicar migrações pendentes** (com backup automático antes). |
| Durante a troca, o site fica alguns segundos em **manutenção**. | Os webhooks da Meta e do Asaas são reenviados automaticamente por eles. |
| Restaurar o **backup do banco** desfaz lançamentos feitos depois dele. | Use a reversão "mantendo os dados", que desfaz só as mudanças de estrutura da versão nova. |
| A **verificação de senhas vazadas** precisa de internet de saída. | Funciona na HostGator. Se o servidor bloquear, ponha `PASSWORD_BREACH_CHECK=false` no `.env`. |

### Reversão manual de emergência (se a tela não abrir)

1. Gerenciador de Arquivos → `storage/app/updates/execucoes/` → a pasta mais recente → `anterior/`. Ali estão as pastas da versão anterior.
2. Renomeie as pastas atuais do sistema (`app`, `vendor`, `public`, …) para `*-quebrado`.
3. Mova as pastas de `anterior/` para a pasta do sistema.
4. Se o banco precisar voltar sem a tela do sistema: use o backup do **cPanel → Backup → Backups parciais → Bancos de dados MySQL** (faça um download antes de cada atualização importante, como garantia extra) e restaure pelo mesmo menu.
5. Apague `storage/framework/down` se o site continuar em manutenção.

## 8. Reinstalação

Ela é bloqueada de propósito. Para reabrir o instalador, é preciso **as duas coisas**:
- apagar `storage/app/installed`;
- tirar `WSF_INSTALLER_LOCKED=true` do `.env`.

Mesmo assim, se o banco já tiver um proprietário, o instalador **não cria outro**, não troca senhas e não apaga dados. Só confere a conexão, roda migrações pendentes e volta a bloquear.
