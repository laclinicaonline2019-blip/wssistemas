# Migração dos dados do WS Finanças antigo (armazenamento local)

O sistema anterior guardava tudo no navegador (`localStorage`, chave `wsfin:v1`). A migração **não apaga nada** — nem no navegador, nem no sistema — e pode ser repetida sem duplicar.

## Caminho 1 — automático (mesmo endereço)
Se o novo sistema estiver no **mesmo domínio** onde o HTML antigo era aberto, a tela *Importar* detecta os dados deste navegador e mostra o botão **"Usar dados deste navegador"**. O painel também avisa quando encontra dados antigos.

## Caminho 2 — arquivo
1. Copie `public/ferramentas/exportar-dados-locais.html` para **a mesma pasta/endereço** de onde o HTML antigo era aberto (mesma origem do navegador) e abra-o **no mesmo navegador**.
2. Clique em **Baixar meus dados** → `ws-financas-dados-locais.json`.
3. No novo sistema: *Importar* → escolha a empresa → envie o arquivo.

## O que é importado
| Antigo | Novo |
|---|---|
| `despesa` (desc, valor, venc, pago, pagoEm, cat, grupo) | lançamento de despesa, com data de pagamento e série fixa preservadas |
| `receita` | lançamento de entrada |
| `divida` (credor, saldo, juros, parcela) | dívida do plano |
| `config` (orc, estrategia) | valor mensal para dívidas e estratégia |
| itens `exemplo` | ignorados |

Cada item guarda o id original (`external_ref`); reimportar só traz o que ainda não existe. O resumo de cada importação fica registrado em *Importar* e na auditoria.

Dados do modo "nuvem" do artefato do Claude não ficam no navegador: abra o HTML antigo, use o console do navegador ou recadastre — em caso de dúvida, fale com o suporte.
