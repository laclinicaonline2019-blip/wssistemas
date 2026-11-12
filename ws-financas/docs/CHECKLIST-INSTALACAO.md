# Checklist de instalação — WS Finanças em Dia

Marque cada item. Detalhes de cada passo em `HOSTGATOR.md` e `INTEGRACOES.md`.

## Hoje, primeiro (são as etapas que mais demoram)
- [ ] **Meta:** em business.facebook.com, criar/abrir o Business Manager e pedir a **verificação da empresa** (CNPJ, comprovante de endereço, site com o domínio)
- [ ] **Meta:** separar um número de celular **exclusivo** para o WhatsApp oficial (não pode estar em uso no app WhatsApp)
- [ ] **Asaas:** criar a conta em asaas.com (envio de documentos) e uma conta no sandbox (sandbox.asaas.com)

## Fase 1 — HostGator (1 a 2 horas)
- [ ] cPanel → MultiPHP Manager: domínio/subdomínio em **PHP 8.3**
- [ ] cPanel → Bancos de dados MySQL: criar banco + usuário + **todos os privilégios** (anotar nome, usuário, senha)
- [ ] Enviar `ws-financas-1.0.0.zip` para `/home/SUA_CONTA/` (fora do `public_html`), extrair e renomear para `ws-financas`
- [ ] Domínios: criar `app.seudominio.com.br` com raiz `/home/SUA_CONTA/ws-financas/public`
- [ ] SSL/TLS Status: rodar o AutoSSL e esperar o cadeado aparecer
- [ ] Abrir `https://app.seudominio.com.br/instalar` e preencher (ambiente: Produção)
- [ ] **Anotar** o que a tela final mostra: comando do cron, URLs e tokens dos webhooks
- [ ] Trabalhos Cron → "Uma vez por minuto" → colar o comando (ajustar o caminho do PHP 8.3 se preciso)
- [ ] Contas de e-mail: criar `nao-responda@seudominio.com.br`; preencher `MAIL_*` no `.env`
- [ ] Entrar como proprietário e ativar a verificação em duas etapas (guardar os códigos de recuperação)
- [ ] Testar "Esqueci minha senha" com o seu e-mail
- [ ] Proprietário → Sistema: conferir a prontidão (o cron deve aparecer com "último sinal")
- [ ] Guardar `APP_KEY` e `BACKUP_PASSWORD` do `.env` em local seguro fora do servidor

## Fase 2 — Ambiente de testes (30 min)
- [ ] Repetir a Fase 1 em `hml.seudominio.com.br`, com **outro banco**, escolhendo "Homologação" no instalador
- [ ] Criar um cliente de teste e importar os dados do WS Finanças antigo (Importar)

## Fase 3 — Integrações (quando a Meta e o Asaas aprovarem)
- [ ] Asaas sandbox: chave da API + webhook no `.env` da **homologação**; assinar um plano e pagar no sandbox
- [ ] WhatsApp: app no developers.facebook.com, número, token permanente, App Secret, webhook (`/webhooks/whatsapp`)
- [ ] Autorizar seu celular em Painel → WhatsApp e mandar `ajuda`
- [ ] Testar os 4 exemplos: energia da empresa A · recebi 5.000 · conta de 1.200 dia 20 · quanto tenho para pagar amanhã?
- [ ] (Opcional) criar o modelo de lembrete na Meta e preencher `WHATSAPP_REMINDER_TEMPLATE`
- [ ] (Opcional) `ANTHROPIC_API_KEY` para a IA

## Fase 4 — Antes de vender
- [ ] Roteiro completo de `HOMOLOGACAO.md` aprovado
- [ ] Repetir as chaves **de produção** do Asaas e do WhatsApp no `.env` da produção
- [ ] Ajustar preços dos planos (Proprietário → Planos e preços)
- [ ] Termos de uso e política de privacidade revisados por advogado
- [ ] Backup gerado, baixado e guardado fora do servidor
- [ ] Pentest externo
