# Relatório de testes — WS Finanças em Dia 1.1.1 (auditoria)

**Data:** 13/11/2026 · **Escopo autorizado:** desenvolvimento e testes em ambiente isolado. **Nada foi instalado nem
alterado na HostGator** (homologação e produção intocadas). A instalação em homologação aguarda autorização expressa.

## Pacote

| | |
|---|---|
| Arquivo | `ws-financas-1.1.1.zip` (27.866.412 bytes) |
| **SHA-256** | `1c374d0c8d9df06597293d38fe38ec65275cdbd34a42a28a65fa94b9f9ee0109` |
| Origem mínima | 1.1.0 (`wsf-update.json` → `min_from`) |
| Assinatura Ed25519 | não assinado (sem chave de assinatura no ambiente de build) — o painel pede a confirmação "sem assinatura" |
| Conteúdo | 12.571 arquivos conferidos por `CHECKSUMS.sha256`; sem `.env`, sem dados de `storage`, sem testes do projeto |
| Referência | pacote 1.1.0 usado nos testes: SHA-256 `04d3124f70ff48b449d28845da83ca742d557c294a8caa8da1138f47e8645c6c` |

## Ambiente isolado

| Componente | Versão / configuração |
|---|---|
| Banco principal | **Percona Server 5.7.44-48** (mesma versão da homologação), contêiner local; usuário do banco **sem SUPER** (ALL só no próprio banco); `log_bin` desligado; fuso do servidor America/Sao_Paulo |
| Outros bancos | MariaDB 10.11.14 · MySQL 8.0.46 (binlog ligado) · SQLite |
| Collations testadas | `utf8mb4_general_ci`, `utf8mb4_unicode_ci`, `latin1_swedish_ci` (padrão do banco) |
| Aplicação | PHP 8.3.6, Apache 2.4 (mod_php), HTTPS local com certificado autoassinado |
| Navegador | Chromium (Playwright). O domínio `teste.wsfinancasemdia.com.br` foi apontado para o Apache local **somente dentro do navegador de teste**, com proxy desligado (o log de acesso do Apache local confirma o atendimento) |
| E-mail | `MAIL_MAILER=log` (e-mail da âncora capturado do log) · WhatsApp em modo simulado com assinatura HMAC do webhook |

## 1. Testes automatizados (PHPUnit)

| Banco | Testes | Resultado |
|---|---|---|
| SQLite | 82 | 78 aprovados · 4 pulados (3 exigem triggers do MySQL; 1 exige MySQL do instalador — rodado à parte, 7/7 aprovados contra MariaDB) |
| MariaDB 10.11.14 | 82 | 81 aprovados · 1 pulado (instalador com MySQL, coberto acima) |
| MySQL 8.0.46 | 82 | 81 aprovados · 1 pulado (idem) |
| **Percona 5.7.44-48** (`utf8mb4_general_ci`) | 82 | 81 aprovados · 1 pulado (idem) |
| Percona 5.7.44-48 (banco `latin1_swedish_ci`) | 82 | 81 aprovados · 1 pulado (idem) |
| Estilo (Pint) | — | aprovado |

Novos testes da 1.1.1 — **JcsTest (8)**: exemplos §3.2.2 e §3.2.3 da RFC 8785; 14 vetores numéricos ECMAScript; escapes
mínimos e Unicode literal sem normalização; recusa de UTF-8 inválido, NaN/Infinito e inteiros fora de ±2^53−1; `{}` ≠ `[]`;
conversões explícitas sem perda (`$wsf` bytes/int/num, chaves inválidas, chaves numéricas); 2.000 idas e voltas aleatórias.

**AuditChainTest (20)**: estado da homologação (9 registros v1, #4 `backup.criar` e #5 `backup.teste_restauracao` com as
chaves reordenadas) → íntegro por compatibilidade com o método; compatibilidade nunca aceita conteúdo diferente; limite de
tentativas → "não verificável"; transição v1→v2 com dados variados (acentos, emoji, 0.1+0.2, PHP_INT_MAX, `{}`, `[]`,
chaves numéricas, −0, bytes inválidos, 1e21, 5e-324) e microssegundos distintos; ativação exige chave própria e não
repete; chave trocada bloqueia a gravação e aparece como `chave_diferente`; banco recusa UPDATE/DELETE/retrocesso do topo/
exclusão do topo; banco recusa bifurcação e rebaixamento; adulteração (v1 e v2) detectada com os triggers removidos;
exclusão no meio, no final e detecção pela âncora; topo divergente; bifurcação e rebaixamento detectados mesmo sem
proteções; verificação em lotes que retoma, com relatório assinado e evento-resumo; âncora por e-mail e conferência
(âncora forjada recusada); painel do proprietário (verificar, ativar v2 com senha errada/certa, âncora, conferir);
restauração sem perda; restauração em banco vazio reconstruindo cadeia e topo; recuperação de falha de migração iniciada
pela 1.1.0; retenção LGPD não apaga a auditoria. Teste antigo de adulteração ajustado (no MySQL o banco agora recusa o UPDATE).

## 2. Concorrência (Percona 5.7, código real da 1.1.1)

Cada gravação dentro de uma transação de negócio (INSERT de negócio + auditoria), `CACHE_STORE=database`, processos paralelos:

| Cenário | Resultado |
|---|---|
| A — 10 processos × 100, código 1.1.1, v1 | **1000 ok / 0 falhas** |
| D — 10 processos × 100, código 1.1.1, v2 | **1000 ok / 0 falhas** |
| B — 6 processos 1.1.1 + 4 processos com a lógica da 1.1.0, v1 | 1.1.1: 527 ok / 73 deadlock · 1.1.0: 19 ok / 381 deadlock |
| C — 10 processos 1.1.1 (v2) + 4 com a lógica da 1.1.0 | 1.1.0: 200/200 recusados (`rebaixamento`) · 1.1.1: 984 deadlocks |
| Consistência final | negócio 2708 = auditoria 2708; `prev_hash` duplicado 0; topo = último id; verificação íntegra (1692 v1 + 1017 v2) |

Conclusão: sozinho, o código 1.1.1 não falha. A convivência com processos 1.1.0 (só na janela da atualização, com o site em
manutenção) gera deadlocks que desfazem a transação inteira — **nunca** corrompem a cadeia nem deixam negócio sem auditoria.
Tentativa de alinhar a ordem de travas com a 1.1.0 foi medida e descartada (causava deadlock entre processos 1.1.1: 184/1000).

## 3. Ponta a ponta 1.1.0 → 1.1.1 (Percona 5.7, ZIP final, do zero)

| # | Etapa | Resultado |
|---|---|---|
| 1 | Instalador web 1.1.0 (`.env` copiado no "Gerenciador de Arquivos") | concluído; instalador bloqueado (404) |
| 2 | Eventos na 1.1.0: login/2FA, **Gerar backup agora**, cadastro de cliente, 4 lançamentos | #3 `backup.criar` `{"bytes","arquivo"}` e #4 `backup.teste_restauracao` `{"ok","arquivo","problemas"}` reordenados pelo MySQL; verificador da 1.1.0: **"Cadeia de auditoria quebrada no registro #3"** (reproduz a homologação) |
| 3 | Atualização pelo painel | 9 etapas ✓; migração 71 ms; **13/13 impressões SHA-256 idênticas**; 6 triggers; 2 colunas; verificação **íntegra** (19 registros, 8 por compatibilidade com o método registrado) |
| 4 | Reverter (código) → 1.1.0 | ok; tela Sistema da 1.1.0 abre (200); 1.1.0 grava sob os triggers (2 lançamentos) e o topo acompanha (28 × 28); **20/20 impressões idênticas** |
| 4b | Reaplicar 1.1.1 | migração idempotente 28 ms; **28/28 impressões idênticas** |
| 5 | Ativar v2 pelo painel (senha + "ATIVAR V2"); web (3 lançamentos com acento/emoji/aspas/barra), WhatsApp (3 webhooks assinados → 2 lançamentos + consulta), cron e fila | v2 ativo; canais web, whatsapp e sistema gravando |
| 6 | Âncora (`wsf:audit:anchor`, e-mail capturado) colada no painel; verificação; reversão após v2 | "Âncora confere"; **íntegra: 45 registros (13 por compatibilidade)**; reversão para 1.1.0 **recusada** (antirrebaixamento); cliente vê a trilha no horário local |
| 7 | Restauração sem perda (backup anterior à atualização), com um lançamento apagado de propósito | 1 registro reposto; 2 diferenças mantidas como estão; nada apagado/sobrescrito; **47/47 impressões idênticas**; cadeia **íntegra** (22 v1 + 13 compatibilidade + 12 v2) |
| — | Prontidão | chave definida · 6 de 6 triggers · gravação v2 · última verificação íntegra · âncora recente |

Sobre o banco real: lá o #4 é `backup.criar` e o #5 é `backup.teste_restauracao` (no teste isolado ficaram #3/#4 porque a
sequência de logins foi diferente). As duas ordens originais estão catalogadas e cobertas por teste automatizado com a
numeração da homologação (#4/#5).

## 4. Defeitos encontrados e corrigidos durante os testes

1. **Collation (grave):** os triggers comparavam o hash com variáveis que herdam a collation padrão do banco; em banco
   `utf8mb4_general_ci` (tabelas `utf8mb4_unicode_ci`) toda gravação de auditoria falhava (erro 1267). Corrigido com
   comparação binária; testado em três collations.
2. **Permissão para triggers:** com log binário ligado e usuário sem SUPER (MySQL 8) a criação falhava no meio. A migração
   agora testa a permissão **antes de qualquer alteração** e explica o motivo (testado: nenhuma coluna criada).
3. **Reversão com cache de views:** após reverter, as views compiladas da 1.1.1 eram usadas pela 1.1.0 (erro 500 na tela
   Sistema). A reversão e a recuperação automática agora limpam views, rotas e config.
4. **Falha de migração iniciada pela 1.1.0:** a recuperação removeria `audit_chain_head` e manteria os triggers que
   dependem dela (a gravação da auditoria pararia). Corrigido e coberto por teste nos 3 bancos MySQL.
5. **Comando `wsf:backup:restore`:** ainda descrevia "substitui os dados" e somava errado o relatório. Corrigido.

## 5. Limitações conhecidas (documentadas em `docs/AUDITORIA.md`)

1. Deadlocks possíveis só na janela da atualização, se um processo 1.1.0 gravar ao mesmo tempo (transação inteira desfeita; cadeia íntegra).
2. Depois de reverter só o código para a 1.1.0, o teste de restauração do botão de backup **da 1.1.0** informa falha (não
   conhece as colunas novas); o arquivo de backup é gerado completo e restaurável pela 1.1.1. Reverter para 1.1.0 é medida de emergência.
3. Exclusão do final da cadeia junto com o topo, por quem tem acesso total ao banco, só é denunciada pela âncora externa (guarde os e-mails).
4. TSA (RFC 3161) desativada; rotação da `AUDIT_HMAC_KEY` não suportada.
5. Fuso `-03:00` fixo na conexão (o Brasil não tem horário de verão desde 2019).

## 6. Antes de instalar em homologação (aguardando autorização)

* Confirmar no cPanel a collation padrão do banco `wand5158_wsfin_teste` (o defeito 1 já está corrigido; é só registro).
* Confirmar que o `.env` da homologação tem `AUDIT_HMAC_KEY` (necessária apenas para a fase 2).
* Seguir o roteiro "Atualização 1.1.1" em `docs/HOMOLOGACAO.md` — fase 1 primeiro; ativação do v2 só depois de aprovada.
