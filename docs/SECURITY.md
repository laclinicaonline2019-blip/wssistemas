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
| Convênios | Permissões separadas (`convenio.gerenciar`, `convenio.autorizar`, `convenio.faturar`); filtro por filial em guias, autorizações e lotes; guia faturada e XML do lote **imutáveis** (guarda no model) com hash; carteirinha usada em guia não é apagada; XML só é gerado se válido no schema oficial; download do XML auditado; estorno manual de pagamento de convênio bloqueado | `GuideService`, `BatchService`, `TissMessageBuilder` |
| Pagamentos | Webhook autenticado por token (tempo constante) + **confirmação por consulta à API** antes de qualquer baixa; eventos idempotentes; cobrança com chave de idempotência; valor divergente/duplicidade/chargeback em revisão; nenhum dado de cartão trafega pelo sistema (Cielo split: Silent Order Post — os campos do cartão não têm `name` e vão do navegador direto à Cielo; o servidor recebe só o PaymentToken; a CSP libera `transaction(sandbox).pagador.com.br` **somente** na página `/pagar/{token}`); credenciais criptografadas (APP_KEY), ocultas na interface e na auditoria; MOCK bloqueado em produção | `PaymentService`, providers |
| PDF | dompdf com recursos remotos e PHP desabilitados (`isRemoteEnabled=false`, `chroot` em `public/`) | `DocumentPdf` |

## Checklist de produção (depende de configuração)

- [ ] `APP_ENV=production`, `APP_DEBUG=false`, `APP_STAGE=production`
- [ ] HTTPS com TLS 1.2+, `SESSION_SECURE_COOKIE=true`, `SECURITY_HSTS=true`, `FORCE_HTTPS=true`
- [ ] `TRUSTED_PROXIES` apenas com os IPs do load balancer
- [ ] `APP_KEY` gerada e guardada em cofre (perdê-la torna segredos 2FA ilegíveis)
- [ ] Remover o valor de `INSTALL_TOKEN` após a instalação
- [ ] `.env` com permissão 600 e **fora** da raiz pública (opções A/B de HOSTGATOR.md)
- [ ] Cron `schedule:run` ativo (processa a fila e verifica a auditoria diariamente)
- [ ] Exportar periodicamente o topo da cadeia de auditoria (`audit_chain_heads`) para fora do servidor
      (e-mail/armazenamento externo): quem tiver banco **e** `APP_KEY` poderia recalcular a cadeia
- [ ] (VPS) Usuário do banco da aplicação **sem** permissão de DDL/`DISABLE TRIGGER`
- [ ] Banco e Redis em rede privada; backups criptografados e testados (ver DEPLOY.md)
- [ ] `PASSWORD_BREACH_CHECK=true`, 2FA obrigatório para administradores
- [ ] WAF/rate limit na borda, monitoramento de erros e alertas
- [ ] Antivírus (ClamAV) para uploads (fase de documentos)
- [ ] Pentest antes do go-live e após mudanças relevantes
- [ ] `composer audit` no CI (já configurado) e atualização regular de dependências

## Resposta a incidentes

1. Suspender a clínica/usuário afetado (encerra sessões e tokens imediatamente).
2. Preservar logs e `audit_logs` (imutáveis) e correlacionar por `request_id`.
3. Rotacionar credenciais de integração e, se necessário, `APP_KEY` (com plano de recriptografia).
4. Avaliar comunicação à ANPD e aos titulares (LGPD art. 48) com o jurídico/DPO.

## Reporte de vulnerabilidades

Envie para o responsável técnico do projeto (defina um e-mail `security@`); não abra issue pública.
