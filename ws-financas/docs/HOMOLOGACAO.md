# Homologação — roteiro de aceite antes de comercializar

Ambiente separado (`hml.`), banco próprio, `APP_STAGE=homologation`, Asaas sandbox, WhatsApp com número de teste. Marque cada item.

## Cadastro e acesso
- [ ] Cadastro self-service cria cliente, empresa, administrador e teste grátis
- [ ] Login, bloqueio após 5 senhas erradas, "Esqueci minha senha" chega no e-mail cadastrado e o link funciona uma única vez
- [ ] 2FA obrigatório para proprietário e administradores; códigos de recuperação funcionam

## Painel (funções do sistema original)
- [ ] Contas do mês, marcar paga, editar, excluir (só esta / esta e as próximas)
- [ ] Atrasadas de meses anteriores aparecem até serem pagas
- [ ] Contas fixas (12 meses) e parceladas (valor dividido, "parcela 1/10")
- [ ] Plano das dívidas: avalanche × bola de neve, "usar mínimas + sobra", gráfico
- [ ] Fechamento e economia, sugestões, análise da IA
- [ ] Lançar rápido com os exemplos da tela
- [ ] Fluxo de caixa (12 meses, dia a dia, contas bancárias) e relatórios com CSV e PDF
- [ ] Celular: todas as abas sem rolagem horizontal

## Multiempresa e permissões
- [ ] Duas empresas, visão consolidada, relatório por empresa
- [ ] Contador só vê as empresas autorizadas e não lança
- [ ] Consulta não lança; financeiro não acessa usuários/assinatura
- [ ] Dois clientes diferentes: nenhum dado cruzado (trocar ids na URL dá 404)

## WhatsApp
- [ ] "Paguei R$ 350 de energia da empresa A." → lançado e confirmado
- [ ] "Recebi R$ 5.000 de um cliente." → pergunta a empresa quando há mais de uma
- [ ] "Cadastre uma conta de R$ 1.200 para pagar dia 20." → pede descrição/confirmação
- [ ] "Quanto tenho para pagar amanhã?" → lista correta
- [ ] DESFAZER e CORRIGIR PARA; mensagem repetida pede confirmação
- [ ] Número não autorizado é recusado; conta suspensa só consulta

## Cobrança
- [ ] Assinar plano (sandbox) → cobrança aparece com Pix/boleto/cartão → pagar no sandbox → ativa automaticamente
- [ ] Vencida → aviso; após carência → suspensa (consulta ok, lançamento bloqueado) → pagar → ativa
- [ ] Proprietário: trocar plano, prorrogar teste, baixa manual, cancelar; histórico registrado

## Operação
- [ ] `php artisan wsf:preflight` sem pendências
- [ ] Cron ativo (Proprietário → Sistema mostra "último sinal")
- [ ] Backup gerado, testado e copiado para fora do servidor; ensaio de restauração feito
- [ ] Auditoria íntegra
- [ ] Importação dos dados locais conferida com os dados reais do sistema antigo

## Atualização 1.1.1 (auditoria) — homologação
Fase 1 (atualização):
- [ ] Antes: anotar o total de registros de auditoria e o hash do último (Sistema → Auditoria / `wsf:audit:verify` da 1.1.0 aponta "quebrada no #4")
- [ ] Atualizar pelo painel (ZIP 1.1.1 em `storage/app/updates/pacotes`); todas as etapas ✓
- [ ] *Sistema → Auditoria → Verificar*: **íntegra**; #4 `backup.criar` e #5 `backup.teste_restauracao` listados "por compatibilidade" com o método
- [ ] Prontidão: "Auditoria: proteções no banco — 6 de 6 triggers"
- [ ] Rotina normal por alguns dias (lançamentos web/WhatsApp, cron, backups); e-mail diário da âncora recebido e conferido no painel
Fase 2 (somente após aprovar a fase 1):
- [ ] `AUDIT_HMAC_KEY` presente no `.env` e guardada fora do servidor
- [ ] *Ativar formato v2*; novos registros aparecem como "íntegro (v2)" na verificação
- [ ] Verificação íntegra com a cadeia mista (v1 + compatibilidade + v2)

Testes automatizados: `php artisan test` (SQLite) e em MariaDB/MySQL — ver README.
