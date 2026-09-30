# Segurança

Prioridade máxima do projeto. Este documento descreve os controles **implementados** e os que
**dependem de configuração** da infraestrutura/organização. O sistema não é declarado "100% seguro":
segurança é um processo contínuo (revisões, pentests, atualizações).

## Controles implementados

| Área | Controle | Onde |
|---|---|---|
| Senhas | Argon2id, política forte, troca obrigatória no 1º acesso, opção HIBP | `HASH_DRIVER`, `PasswordRules` |
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
| Auditoria | Append-only no banco, segredos mascarados, negações preservadas em rollback | `AuditLogger` |
| Segredos | Somente variáveis de ambiente; `.env` fora do Git; segredo 2FA criptografado com `APP_KEY` | `.env.example` |
| Rastreabilidade | `X-Request-Id` em respostas, logs e auditoria | `AssignRequestId` |
| Erros | Páginas genéricas; sem stack trace com `APP_DEBUG=false` | `resources/views/errors` |
| Arquivos | Disco local privado (`serve=false`) | `config/filesystems.php` |

## Checklist de produção (depende de configuração)

- [ ] `APP_ENV=production`, `APP_DEBUG=false`, `APP_STAGE=production`
- [ ] HTTPS com TLS 1.2+, `SESSION_SECURE_COOKIE=true`, `SECURITY_HSTS=true`, `FORCE_HTTPS=true`
- [ ] `TRUSTED_PROXIES` apenas com os IPs do load balancer
- [ ] `APP_KEY` gerada e guardada em cofre (perdê-la torna segredos 2FA ilegíveis)
- [ ] Usuário do banco da aplicação **sem** permissão de `ALTER TABLE`/`DISABLE TRIGGER` (migrations com outro usuário)
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
