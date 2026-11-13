# Auditoria (versão 1.1.1)

A trilha de auditoria registra quem fez o quê, quando e por qual canal (web, WhatsApp, sistema). É **append-only** e
encadeada: cada registro guarda o hash do anterior (`prev_hash`) e um HMAC-SHA256 do próprio conteúdo. Nenhum registro
histórico é alterado, apagado ou recalculado pela 1.1.1.

## O que mudou na 1.1.1

| | 1.1.0 | 1.1.1 |
|---|---|---|
| Formato do hash | v1: `json_encode` do PHP (a ordem das chaves importa) | v1 mantido para os registros antigos; **v2** (após ativação): JSON canônico RFC 8785 (JCS) com versão, chave e data UTC dentro do que é assinado |
| Registros #3/#4 ("cadeia quebrada") | o MySQL reordena as chaves do JSON e a verificação falhava | verificação por **compatibilidade**: a ordem original é reproduzida e só é aceita se o HMAC gravado bater (impossível sem a chave); o método fica registrado no relatório |
| Gravação simultânea | trava no último registro | trava única em `audit_chain_head` (`SELECT … FOR UPDATE`), até o COMMIT |
| Proteção no banco | nenhuma | 6 triggers + índice único em `prev_hash` |
| Exclusão do final da cadeia | não detectável | **âncora diária por e-mail** |
| Data | `created_at` (fuso do servidor) | v2: `created_at_utc` com microssegundos (`2026-11-13T14:19:07.948250Z`) |
| Restauração de backup | apagava e regravava | **sem perda**: só repõe registros ausentes; nunca apaga nem sobrescreve |
| Retenção LGPD | apagava a auditoria do cliente eliminado | a auditoria é mantida (prova de integridade; dados sensíveis já são mascarados na gravação) |

## Duas fases

**Fase 1 — instalação da 1.1.1 (automática na atualização).** A migração `2026_11_14_000100_audit_v2` é só aditiva:
duas colunas novas (`hash_version`, `created_at_utc`, vazias nos registros antigos), índice único em `prev_hash`, tabela
`audit_chain_head` (topo da cadeia) e os triggers. Antes de mexer em qualquer coisa ela confere: (1) que não há `prev_hash`
repetido; (2) que o usuário do banco pode criar triggers. Depois, compara a impressão SHA-256 de cada registro antes e
depois — se mudar uma única, a migração para. No MySQL 5.7 usa `ALGORITHM=INPLACE, LOCK=NONE` (sem bloquear gravações).
Nessa fase os registros continuam sendo gravados no formato v1.

**Fase 2 — ativação do v2 (manual, irreversível).** *Proprietário → Sistema e backups → Auditoria → Ativar formato v2*,
com a senha e a confirmação `ATIVAR V2`. Exige `AUDIT_HMAC_KEY` no `.env` (o v2 não aceita a APP_KEY como alternativa).
A partir daí todo processo (web, WhatsApp, fila, cron) grava v2 — a versão de gravação é lida do banco sob a trava, e os
triggers recusam qualquer gravação v1 (inclusive de um código antigo que ainda esteja rodando). Depois de ativado, o painel
não permite reverter o sistema para versões anteriores à 1.1.1.

Ative só depois de validar a fase 1 em homologação (verificação íntegra, backups, rotina normal por alguns dias).

## Verificação

* **Painel:** *Sistema e backups → Auditoria → Verificar auditoria*. Trabalha em lotes (até 60 s por clique); se a
  cadeia for grande, aparece **Continuar verificação** e ela retoma de onde parou.
* **Automática:** todo dia às 05:30 (`wsf:audit:verify`), com evento-resumo gravado na própria cadeia.
* **Relatório assinado:** `storage/app/auditoria/relatorio-AAAAMMDD-HHMMSS.json` (HMAC), com o resumo por status, os
  registros conferidos por compatibilidade (método e número de tentativas) e os problemas.

Status por registro (separados — um problema nunca é "misturado" com outro):

| Status | Significado |
|---|---|
| `integro_v2` / `integro_v1` | conferido diretamente |
| `integro_v1_compat` | v1 conferido reproduzindo a ordem original das chaves (método registrado) |
| `ligacao_quebrada` | `prev_hash` não aponta para o registro anterior (algo foi apagado no meio) |
| `bifurcacao` | dois registros apontam para o mesmo anterior |
| `hmac_invalido` | conteúdo alterado |
| `rebaixamento` | registro v1 gravado depois da ativação do v2 |
| `chave_diferente` | a `AUDIT_HMAC_KEY` atual não é a chave histórica (key_id) |
| `nao_verificavel` | limite de tentativas atingido (ver "Limites") |
| `topo_divergente` | o topo registrado em `audit_chain_head` não é o último registro (exclusão do final) |
| `exclusao_do_final` | a cadeia atual não contém o registro da última âncora, ou tem menos registros que ela |

**Limites** (`config/wsf.php` → `audit`): lote de 500 registros; até 50.000 tentativas por registro v1 e 2.000.000 por
execução; 60 s por execução. A compatibilidade tenta, nesta ordem: a ordem lida; a ordem original do código (todas as
ações gravadas pela 1.0.0/1.1.0 estão catalogadas); todas as permutações, só para objetos de até 5 chaves.

## Âncora diária (exclusão do final)

A cadeia sozinha não percebe se os **últimos** registros forem apagados. Todo dia às 05:50 (`wsf:audit:anchor`) o
sistema grava `{último id, último hash, total}` assinado em `storage/app/auditoria/ancoras/` e envia por **e-mail** ao(s)
administrador(es) da plataforma (ou a `WSF_AUDIT_ANCORA_DESTINO`, separado por vírgulas) — uma cópia fora do servidor.

Para conferir: abra o e-mail, copie o bloco entre `-----BEGIN WSF ANCORA-----` e `-----END WSF ANCORA-----` e cole em
*Auditoria → Conferir âncora recebida por e-mail*. Guarde esses e-mails (ex.: uma pasta/etiqueta própria).
Carimbo de tempo RFC 3161 (TSA): **desativado** nesta versão (`WSF_AUDIT_TSA=off`).

## Chave (`AUDIT_HMAC_KEY`)

* Gerada pelo instalador web. Guarde-a fora do servidor junto com a `APP_KEY` e a `BACKUP_PASSWORD`.
* O v2 registra a impressão da chave (`key_id`, 16 caracteres) no topo da cadeia e em cada registro assinado.
* **Rotação não é suportada nesta versão.** Se a chave do `.env` mudar depois da ativação, a gravação é recusada
  (mensagem no painel/log) e a verificação mostra `chave_diferente`. Correção: devolver ao `.env` a chave original.
* Registros v1 usam `AUDIT_HMAC_KEY` se existir, senão a `APP_KEY` (como na 1.0.0/1.1.0).

## Proteções no banco (MySQL/MariaDB)

| Trigger | Efeito |
|---|---|
| `audit_logs_no_update` / `audit_logs_no_delete` | UPDATE e DELETE em `audit_logs` são recusados |
| `audit_logs_chain_guard` | só aceita INSERT cujo `prev_hash` é o topo atual; recusa v1 após o v2; confere o formato de `created_at_utc` |
| `audit_logs_advance_head` | avança o topo a cada INSERT (mantém compatível um código 1.1.0 que ainda esteja rodando) |
| `audit_head_guard` / `audit_head_no_delete` | o topo não retrocede, a versão não diminui, `key_id` e o marco de ativação não mudam, a linha não é apagada |

As comparações dos triggers são binárias (independem da collation padrão do banco — testado com `latin1_swedish_ci`,
`utf8mb4_general_ci` e `utf8mb4_unicode_ci`).

**Requisito:** o usuário do banco precisa poder criar triggers. No MySQL 5.7 da HostGator (log binário desligado) basta o
privilégio TRIGGER (incluído em "ALL PRIVILEGES" do cPanel). Com log binário ligado, o MySQL exige SUPER ou
`log_bin_trust_function_creators=1`; sem isso a migração para **antes de qualquer alteração** e explica o motivo.

Quem tem acesso total ao banco (DDL) pode remover os triggers — isso não é impedido, mas qualquer alteração posterior é
detectada pela verificação (HMAC/ligação/topo) e pela âncora externa.

## Fuso horário

A conexão da aplicação usa `-03:00` (`DB_TIMEZONE`), somente na sessão — o servidor MySQL não é alterado. O v2 assina a data
em UTC com microssegundos; a tela de auditoria do cliente mostra o horário local.

## Recuperação

* **Reverter pelo painel** (*Atualizações → Reverter*): enquanto o v2 **não** foi ativado, é possível voltar para a 1.1.0.
  As estruturas da auditoria (colunas, topo, triggers) **são mantidas** — a 1.1.0 continua gravando normalmente sob elas.
  Depois da ativação do v2 a reversão para versões anteriores à 1.1.1 é recusada; corrija "para frente".
* **Falha durante a atualização:** a recuperação automática desfaz só o que aquela execução criou e repõe, sem apagar nem
  sobrescrever, registros que faltem.
* **Restaurar backup** (`wsf:backup:restore arquivo.wsfbak --force`): sem perda — insere apenas registros ausentes; a
  auditoria só é preenchida a partir do backup se a tabela estiver vazia (banco novo), em ordem, e o topo é reconstruído.

## Limitações conhecidas

1. **Janela da atualização:** se um processo da 1.1.0 (ex.: cron do minuto) gravar ao mesmo tempo que a 1.1.1, a ordem de
   travas é diferente e o MySQL pode desfazer uma das transações (deadlock). A transação inteira é desfeita (negócio e
   auditoria juntos); a cadeia nunca é corrompida. Durante a atualização o site fica em manutenção, então a janela é de segundos.
2. **Após reverter só o código para a 1.1.0:** o botão "Gerar backup agora" da 1.1.0 informa falha no teste de restauração
   (o teste da 1.1.0 não conhece as colunas novas). O arquivo de backup é gerado completo e é restaurável pela 1.1.1.
   Reversão para 1.1.0 é medida de emergência.
3. Um administrador de banco com acesso total pode apagar o final da cadeia **e** o topo; só a âncora externa (e-mail)
   denuncia isso — por isso os e-mails das âncoras devem ser guardados.
4. TSA (RFC 3161) desativada; rotação de chave não suportada.

## Comandos (para quem tem terminal; tudo também está no painel)

```bash
php artisan wsf:audit:verify               # verificação completa, relatório assinado
php artisan wsf:audit:verify --sem-evento  # sem gravar o evento-resumo
php artisan wsf:audit:anchor               # âncora agora (arquivo + e-mail)
```
