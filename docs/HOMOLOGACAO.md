# Homologação (Fase 19)

Homologação é a etapa em que o sistema roda **no servidor real**, com **integrações em SANDBOX**, e a clínica
valida cada módulo antes de liberar dados reais. Nada aqui usa dinheiro ou pacientes reais.

| Estágio (`APP_STAGE`) | Integrações | Dados | Faixa exibida |
|---|---|---|---|
| `development` | MOCK | demonstração | DESENVOLVIMENTO |
| `homologation` | SANDBOX (ASAAS sandbox, Cielo sandbox, WhatsApp número de teste, IA com chave real e limite de gasto) | fictícios | HOMOLOGAÇÃO |
| `production` | PRODUÇÃO | reais | — |

## 1. Montar o ambiente

1. Subdomínio separado (ex.: `homolog.suaclinica.com.br`) e **banco separado** do de produção.
2. Instale como em [HOSTGATOR.md](HOSTGATOR.md) e use no `.env`:

```dotenv
APP_ENV=production          # mesmo comportamento de produção (cache de config, sem debug)
APP_STAGE=homologation      # faixa "HOMOLOGAÇÃO" em todas as telas
APP_DEBUG=false
APP_URL=https://homolog.suaclinica.com.br
SESSION_SECURE_COOKIE=true
FORCE_HTTPS=true
MAIL_MAILER=smtp            # e-mails de teste: use caixas próprias da equipe
PLATFORM_BILLING_PROVIDER=asaas
PLATFORM_ASAAS_SANDBOX=true
BACKUP_PASSWORD=<senha forte, guardada no cofre>
```

3. Cron a cada minuto (obrigatório) e, depois de 2 minutos:

```bash
php artisan aivexa:preflight          # 0 erros para seguir; avisos devem ser entendidos
```

ou Super Admin → **Prontidão (produção)**. A tela mostra cada item com "como corrigir".

4. Dados de teste: `php artisan db:seed --class=DemoSeeder` (clínica, médicos, pacientes e agenda fictícios)
   **somente no banco de homologação**.

## 2. Integrações em sandbox

| Integração | Onde configurar | Conta de teste |
|---|---|---|
| ASAAS (cobrança do paciente) | Clínica → Configurações → Pagamentos → modo **Sandbox** | sandbox.asaas.com (chave `$aact_hmlg_…`) |
| Cielo | idem → **Sandbox** | cadastro em developercielo.github.io |
| ASAAS da plataforma (assinaturas) | `.env` `PLATFORM_ASAAS_*` com `PLATFORM_ASAAS_SANDBOX=true` | conta sandbox da plataforma |
| WhatsApp oficial | Mensagens → Canais → Cloud API | número de teste do Meta for Developers |
| WhatsApp não oficial | Canais → Z-API ou Evolution | instância de teste (aceite de risco registrado) |
| IA (Claude / ChatGPT) | IA → Configurações | chave própria com **limite de gasto** no painel do provedor |
| Open Finance | Conciliação → Pluggy | ambiente sandbox do Pluggy |
| ClamAV (opcional) | `.env` `FILE_SCANNER=clamav` | só em VPS |

Webhooks a cadastrar nos painéis (troque o domínio):

- Pagamentos: `https://homolog…/webhooks/pagamentos/{id-do-gateway}` (o endereço exato aparece na tela do gateway).
- Assinaturas: `https://homolog…/webhooks/assinaturas/asaas` com o token `PLATFORM_ASAAS_WEBHOOK_TOKEN`.
- WhatsApp: `https://homolog…/webhooks/whatsapp/{canal}` (aparece na tela do canal, com o token de verificação).

## 3. Roteiro de aceite por módulo

Marque cada item **na interface**, com o usuário do perfil indicado. Os 6 primeiros blocos também rodam
automaticamente no navegador (`tests/e2e`, ver [TESTING.md](TESTING.md)).

### Recepção (perfil recepção)
- [ ] Cadastrar paciente com CPF válido; CPF inválido é recusado; busca por nome/CPF/telefone.
- [ ] Agendar consulta; tentar o mesmo horário em outra aba → recusado (sem dupla marcação).
- [ ] Encaixe exige motivo; bloqueio de agenda impede agendamento.
- [ ] Check-in → senha na fila → chamada no painel da TV.
- [ ] Remarcar e cancelar com motivo; histórico registra quem fez.

### Médico (perfil médico)
- [ ] Atender: triagem visível, prontuário com autosave, finalizar e criar adendo (versão anterior preservada).
- [ ] Receita simples e de controle especial (2 vias), atestado e pedido de exame; QR Code valida o documento.
- [ ] Recepção não abre prontuário (sem a permissão, a tela retorna acesso negado).

### Financeiro (perfil financeiro)
- [ ] Conta a receber → link de pagamento ASAAS sandbox → pagar no sandbox → baixa automática via webhook.
- [ ] Mesmo webhook reenviado não duplica a baixa; estorno gera lançamento inverso (nada é apagado).
- [ ] Caixa: abrir, receber, fechar às cegas, conferência com diferença.
- [ ] Importar extrato OFX/CSV → conciliação sugerida → confirmar.
- [ ] Relatórios em tela, PDF, Excel e CSV; fechamento mensal do médico com confirmação dele.
- [ ] Convênio: guia, lote XML TISS validado, glosa e recurso.

### WhatsApp + IA
- [ ] Paciente manda mensagem → IA responde, oferece horários e agenda **só após confirmação**.
- [ ] Pergunta clínica ("posso tomar…?") → IA **não** orienta e transfere para humano.
- [ ] Pedido de humano → conversa vai para a fila da recepção; IA para de responder naquela conversa.
- [ ] Áudio é transcrito; foto de pedido de exame vira dados **NÃO VERIFICADO** até alguém conferir.
- [ ] Lembrete automático da consulta sai no horário configurado.
- [ ] Toda chamada à IA aparece em Atendimento IA (chamadas recentes, tokens e erros dos últimos 30 dias).

### Portal do paciente
- [ ] Ativação por link; ver consultas, documentos e pagamentos; agendar, confirmar e cancelar consulta.
- [ ] Paciente A não acessa documento do paciente B (troque o identificador na URL → 404).

### Gestão (perfil administrador da clínica)
- [ ] Criar usuário e perfil; permissões refletem na hora; usuário bloqueado não entra.
- [ ] 2FA obrigatório para administradores; Central de segurança mostra logins com falha.
- [ ] Exportação LGPD do paciente; anonimização mantém prontuário (nada clínico é apagado).
- [ ] Auditoria: ações aparecem com quem/quando; a verificação diária da cadeia (`aivexa:audit:verify`) não acusa falhas.

### Plataforma (Super Admin)
- [ ] Criar clínica, plano, trocar plano (upgrade proporcional), fatura sandbox paga via webhook.
- [ ] Fatura vencida além do prazo → clínica bloqueada (só "Assinatura" acessível) → pagamento libera.
- [ ] Prontidão sem erros; Backups → "Gerar backup agora" e baixar.
- [ ] **Teste de restauração** (seção 4) feito pelo menos uma vez.

## 4. Ensaio de restauração (obrigatório antes de produção)

1. Super Admin → **Backups** → baixe o último `aivexa-db-*.sql.gz.enc`.
2. Descriptografe: `php artisan aivexa:backup --decrypt=aivexa-db-….sql.gz.enc --to=restaurado.sql.gz`.
3. Crie um banco vazio no cPanel e importe (`gunzip restaurado.sql.gz` e phpMyAdmin → Importar, ou
   `mysql banco_vazio < restaurado.sql`).
4. Aponte uma cópia do sistema para esse banco e confira login, pacientes e financeiro.
5. Registre data, tempo gasto e responsável. (O teste automatizado `BackupReadinessTest` repete isso a cada push.)

## 5. Critérios de saída (assinatura)

- [ ] `php artisan aivexa:preflight` com **0 erros** no servidor de homologação.
- [ ] Todos os itens da seção 3 marcados, sem defeito crítico aberto.
- [ ] Ensaio de restauração concluído.
- [ ] Pentest externo feito com o roteiro de [PENTEST.md](PENTEST.md) e achados altos corrigidos.
- [ ] DPO/encarregado revisou [LGPD.md](LGPD.md) e os termos de uso/privacidade da clínica.

| Papel | Nome | Data | Assinatura |
|---|---|---|---|
| Responsável da clínica | | | |
| Responsável técnico | | | |
| Encarregado de dados (LGPD) | | | |
