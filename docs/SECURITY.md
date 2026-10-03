# Segurança

Prioridade máxima do projeto. Este documento descreve os controles **implementados** e os que
**dependem de configuração** da infraestrutura/organização. O sistema não é declarado "100% seguro":
segurança é um processo contínuo (revisões, pentests, atualizações).

## Controles implementados

| Área | Controle | Onde |
|---|---|---|
| Senhas | Argon2id (ou bcrypt se o PHP não suportar), política forte, troca obrigatória no 1º acesso, opção HIBP | `config/hashing.php`, `PasswordRules` |
| Força bruta | Rate limit e-mail+IP; bloqueio após N falhas (padrão 10 / 15 min); rate limit por IP nas rotas de login | `LoginService`, `throttle:auth` |
| Enumeração | Mensagem neutra idêntica e custo de hash equalizado | `LoginService` |
| MFA | TOTP com anti-replay + 8 códigos de recuperação HMAC; obrigatório p/ Super Admin; opcional obrigatório por clínica | `TwoFactorService`, `EnsureTwoFactorEnrolled` |
| Sessão | Cookie HttpOnly/SameSite, sessão criptografada, expiração 60 min, regeneração no login, encerramento de outras sessões na troca de senha e no bloqueio | `.env`, `AccountController`, `UserService` |
| Tokens API | Expiração (12 h), revogação no logout/bloqueio/suspensão, poda diária | Sanctum, `routes/console.php` |
| Multi-tenant | Contexto do backend, escopo que falha fechado, 404 entre tenants, FKs compostas | `app/Core/Tenancy` |
| Autorização | RBAC granular por filial; checagem no backend; anti-escalonamento | `PermissionService`, `AccessGuard` |
| CSRF | Token em todos os formulários web | Laravel |
| XSS | Escape automático Blade; **CSP sem inline scripts** | `SecurityHeaders` |
| SQL Injection | Somente Eloquent/Query Builder parametrizados; `LIKE` com escape | todo o código |
| Clickjacking | `X-Frame-Options: DENY`, `frame-ancestors 'none'` | `SecurityHeaders` |
| Headers | nosniff, Referrer-Policy, Permissions-Policy, COOP, HSTS (configurável), `Cache-Control: no-store` autenticado | `SecurityHeaders` |
| Auditoria | Cadeia HMAC por empresa (detecta alteração/exclusão) + verificação diária; trigger append-only quando o banco permite; segredos mascarados; negações preservadas em rollback | `AuditLogger`, `AuditChain` |
| Instalador web | Só existe antes da instalação; exige `INSTALL_TOKEN` (≥16 caracteres, comparação em tempo constante); depois retorna 404 | `WebInstallerController` |
| Apache | `.htaccess` bloqueia arquivos ocultos; opção C protege `.env`/`vendor` quando tudo fica em `public_html` | `public/.htaccess`, `deploy/hostgator` |
| Segredos | Somente variáveis de ambiente; `.env` fora do Git; segredo 2FA criptografado com `APP_KEY` | `.env.example` |
| Rastreabilidade | `X-Request-Id` em respostas, logs e auditoria | `AssignRequestId` |
| Erros | Páginas genéricas; sem stack trace com `APP_DEBUG=false` | `resources/views/errors` |
| Arquivos | Disco local privado (`serve=false`); anexos com tipo detectado pelo conteúdo (finfo), nome aleatório, download com `Content-Disposition` e CSP `sandbox` | `config/filesystems.php`, `PatientFileService` |
| Documentos médicos | Imutáveis após emissão; selo HMAC; código de verificação aleatório (12 caracteres, ~59 bits) com página pública limitada a 30 consultas/min; cancelamento só pelo emitente | `DocumentService` |
| Financeiro | Valores em centavos (inteiros); livro imutável com estorno vinculado; dinheiro só por caixa aberto; fechamento cego e conferência por outra pessoa; desconto e estorno com permissões próprias; CSV protegido contra injeção de fórmulas | `FinanceService` |
| Portal do paciente | Guard separado (`patient`), tenant pela URL e conta validada a cada requisição; todas as consultas filtradas pelo paciente da conta (404 para dados de outro); senha forte, rate limit, bloqueio temporário, mensagens neutras, links de uso único com expiração e só o hash no banco; sem "lembrar-me"; prontuário não exposto; arquivos só quando liberados um a um; downloads com `no-store` e auditados; ator `patient` na auditoria | `PortalAccountService`, `ResolvePortalTenant`, `PortalService` |
| WhatsApp | Webhook com assinatura HMAC (App Secret) e token de verificação; credenciais criptografadas e nunca exibidas; eventos idempotentes; botão de resposta só vale para o próprio paciente; texto livre só na janela de 24 h; encaixe nunca por canal automático; conversas por permissão `ia.conversas` | `InboundService`, `MetaCloudProvider` |
| WhatsApp não oficial | Opcional, com aceite de risco registrado e auditado; selo NÃO OFICIAL nas telas; webhook autenticado por token secreto na URL (comparação em tempo constante; "Gerar novo token" invalida a anterior); Evolution só por HTTPS; credenciais criptografadas e nunca exibidas; grupos e mensagens do próprio número ignorados | `ZApiProvider`, `EvolutionApiProvider`, `ChannelWebController` |
| Assinatura (SaaS) | Bloqueio por inadimplência no login e no middleware: só quem tem `assinatura.gerenciar` entra, e só na área de assinatura (API responde 402); demais usuários não entram; pagamento confirmado na API do gateway (webhook com token e idempotente); valor menor que a fatura não libera; MOCK de pagamento desativado em produção; dados nunca apagados ao bloquear ou cancelar; tudo auditado | `SubscriptionService`, `ResolveTenant`, `LoginService` |
| Relatórios | Cada relatório exige a sua permissão (`relatorio.operacional`, `relatorio.financeiro`, `relatorio.clinico`) e respeita as unidades do usuário; relatório clínico só agregado (sem pacientes); exportações auditadas, sem cache; CSV protegido contra fórmula; Excel gerado sem fórmulas; limite de período e de linhas | `ReportService`, `ReportExporter` |
| Fechamento médico × clínica | Retrato imutável com SHA-256 (adulteração detectada na tela); só meses encerrados; correção por nova versão; médico vê e responde só os próprios demonstrativos; repasse gerado uma única vez | `ClosingService` |
| Conciliação bancária | Livro financeiro não é alterado (só vínculos); soma conferida no servidor; um lançamento por linha garantido no banco de dados; lançamentos do extrato nunca entram no caixa do operador; credenciais do Open Finance criptografadas e ocultas; arquivo até 5 MB; tudo auditado (importar, conciliar, ignorar, desfazer) | `Reconciler`, `StatementImporter` |
| Mídia (Fase 13) | Arquivos recebidos em disco privado por clínica, nome aleatório, tipo detectado pelo conteúdo e limites de tamanho; download só autenticado (`ia.conversas`), sem cache, CSP `sandbox` e auditado; URLs de mídia só https; base64 nunca gravado no banco; leitura sempre "não verificada"; comprovante não dá baixa; anexar à ficha exige `documento.anexar` e respeita a cota do plano | `MediaPipeline`, `MediaStore`, `AiMediaWebController` |
| IA (recepcionista) | O modelo só **pede** ações; tudo é executado no backend com o escopo da clínica e as mesmas regras da agenda (sem encaixe, sem dupla marcação, idempotência); agendar exige proposta + confirmação em mensagem posterior; identificação com limite de 3 tentativas; emergência, "atendente", consentimento revogado e limites aplicados **antes** do modelo; erro/recusa → equipe; informações da clínica isoladas como dados e sem poder sobre as regras; chave da API criptografada, nunca exibida nem auditada; trocar de provedor descarta a chave; tela por permissão `ia.configurar` | `AiReceptionist`, `ReceptionistTools` |
| Convênios | Permissões separadas (`convenio.gerenciar`, `convenio.autorizar`, `convenio.faturar`); filtro por filial em guias, autorizações e lotes; guia faturada e XML do lote **imutáveis** (guarda no model) com hash; carteirinha usada em guia não é apagada; XML só é gerado se válido no schema oficial; download do XML auditado; estorno manual de pagamento de convênio bloqueado | `GuideService`, `BatchService`, `TissMessageBuilder` |
| Pagamentos | Webhook autenticado por token (tempo constante) + **confirmação por consulta à API** antes de qualquer baixa; eventos idempotentes; cobrança com chave de idempotência; valor divergente/duplicidade/chargeback em revisão; nenhum dado de cartão trafega pelo sistema (Cielo split: Silent Order Post — os campos do cartão não têm `name` e vão do navegador direto à Cielo; o servidor recebe só o PaymentToken; a CSP libera `transaction(sandbox).pagador.com.br` **somente** na página `/pagar/{token}`); credenciais criptografadas (APP_KEY), ocultas na interface e na auditoria; MOCK bloqueado em produção | `PaymentService`, providers |
| PDF | dompdf com recursos remotos e PHP desabilitados (`isRemoteEnabled=false`, `chroot` em `public/`) | `DocumentPdf` |

| Varredura de arquivos (Fase 17) | Todo upload (anexos do paciente, mídia do WhatsApp, extratos) passa por verificações próprias — PDF com JavaScript/`/Launch`/arquivo embutido/mídia ativa (inclusive nomes escapados), imagem poliglota com código, EICAR — e, se configurado, pelo **ClamAV** (clamd, INSTREAM); `fail_closed` opcional; bloqueios auditados (`security.file_blocked`); resultado gravado (`scan_status`) | `FileScanner` |
| Proxies/WAF | `TRUSTED_PROXIES=cloudflare` usa as faixas oficiais da Cloudflare (IP real do visitante no rate limit, bloqueio e auditoria); ou lista própria | `bootstrap/app.php`, `config/proxies.php` |
| Central de segurança | Logins com falha, bloqueios, arquivos barrados, exportações, admins sem 2FA, tokens ativos, eventos recentes | `SecurityCenterController` |
| Retenção (LGPD) | Rotina diária só sobre dados operacionais (avisos, logs técnicos da IA, texto do WhatsApp se a clínica quiser, conteúdo bruto de webhooks, links vencidos do portal); nunca prontuário, documentos, financeiro ou auditoria | `RetentionService` |

## Cloudflare (WAF) — recomendado na frente do site

1. Aponte o DNS do domínio para a Cloudflare (proxy laranja ligado) e use SSL **Full (strict)**.
2. No `.env`: `TRUSTED_PROXIES=cloudflare` (as faixas ficam em `config/proxies.php` — confira em cloudflare.com/ips).
3. Regras sugeridas (WAF → Custom rules):
   - *Managed Challenge* para `/login`, `/portal/*/entrar` e `/esqueci-a-senha` quando o país não for BR (ajuste ao seu público);
   - *Rate limiting*: `/login` 20 req/min por IP; `/api/v1/auth/*` 30 req/min;
   - *Block* para caminhos que nunca devem ser acessados: `/.env`, `/vendor/*`, `/storage/*`, `/.git/*`, `*.sql`;
   - **Não** desafie as rotas de webhook (`/webhooks/*`) — os gateways e a Meta não resolvem desafios.
4. Ative *Bot Fight Mode* com exceção para `/webhooks/*`.

## RLS (Row Level Security) no PostgreSQL

O isolamento hoje é garantido em duas camadas na aplicação (escopo global que falha fechado + FKs compostas
`(company_id, id)` no banco, cobertas por testes). RLS no PostgreSQL seria uma terceira camada, aplicável só em
VPS com PostgreSQL e usuário de banco sem `BYPASSRLS`: política `company_id = current_setting('app.company_id')`
por tabela, com a variável definida a cada requisição pelo `TenantContext`. Não está ativada nesta versão —
fica planejada para a migração a VPS (MySQL/MariaDB da HostGator não tem RLS).

## Checklist de produção (depende de configuração)

- [ ] `APP_ENV=production`, `APP_DEBUG=false`, `APP_STAGE=production`
- [ ] HTTPS com TLS 1.2+, `SESSION_SECURE_COOKIE=true`, `SECURITY_HSTS=true`, `FORCE_HTTPS=true`
- [ ] `TRUSTED_PROXIES` apenas com os IPs do load balancer (ou `cloudflare`)
- [ ] `APP_KEY` gerada e guardada em cofre (perdê-la torna segredos 2FA ilegíveis)
- [ ] Remover o valor de `INSTALL_TOKEN` após a instalação
- [ ] `.env` com permissão 600 e **fora** da raiz pública (opções A/B de HOSTGATOR.md)
- [ ] Cron `schedule:run` ativo (processa a fila e verifica a auditoria diariamente)
- [ ] Exportar periodicamente o topo da cadeia de auditoria (`audit_chain_heads`) para fora do servidor
      (e-mail/armazenamento externo): quem tiver banco **e** `APP_KEY` poderia recalcular a cadeia
- [ ] (VPS) Usuário do banco da aplicação **sem** permissão de DDL/`DISABLE TRIGGER`
- [ ] Banco e Redis em rede privada; `BACKUP_PASSWORD` definida (backups AES-256-GCM), cópias fora do servidor e
      ensaio de restauração (ver [PRODUCAO.md](PRODUCAO.md))
- [ ] `php artisan aivexa:preflight --strict` sem erros nem avisos (verifica a maior parte desta lista)
- [ ] `PASSWORD_BREACH_CHECK=true`, 2FA obrigatório para administradores
- [ ] WAF/rate limit na borda, monitoramento de erros e alertas
- [ ] Antivírus: na VPS, `FILE_SCANNER=clamav` (na HostGator ficam as verificações próprias)
- [ ] Cloudflare/WAF com `TRUSTED_PROXIES=cloudflare` (ver acima)
- [ ] Pentest antes do go-live e após mudanças relevantes — roteiro em [PENTEST.md](PENTEST.md)
- [ ] `composer audit` no CI (já configurado) e atualização regular de dependências

## Resposta a incidentes

1. Suspender a clínica/usuário afetado (encerra sessões e tokens imediatamente).
2. Preservar logs e `audit_logs` (imutáveis) e correlacionar por `request_id`.
3. Rotacionar credenciais de integração e, se necessário, `APP_KEY` (com plano de recriptografia).
4. Avaliar comunicação à ANPD e aos titulares (LGPD art. 48) com o jurídico/DPO.

## Reporte de vulnerabilidades

Envie para o responsável técnico do projeto (defina um e-mail `security@`); não abra issue pública.
