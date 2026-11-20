# Relatório — 1.1.6: 2FA opcional para clientes e português completo · WS Finanças em Dia

**Data:** 20/11/2026 · Feito e testado em ambiente isolado. Homologação e produção **não** foram alteradas.

## 1. Diagnóstico (antes)

* **2FA:** `WSF_2FA_ADMIN_CLIENTE` vinha **`true`** no modelo do `.env` (e no `.env` da homologação) → todo cliente que se cadastra (administrador da própria conta) era obrigado a ativar. Desativar pedia só a senha.
* **Inglês:** o sistema não tinha traduções do Laravel (`lang/`). Medido: "The password field must contain at least one symbol.", "The email field is required.", "The password field confirmation does not match." etc.
* **E-mail de recuperação:** o layout padrão acrescentava "If you're having trouble clicking the … button, copy and paste the URL below…" e "All rights reserved.".

## 2. Alterações

| Arquivo | Alteração |
|---|---|
| `config/wsf.php` | 2FA de clientes lido de `WSF_2FA_CLIENTE_OBRIGATORIO` (padrão **false**). A antiga `WSF_2FA_ADMIN_CLIENTE` não é mais lida — assim a atualização vale **sem editar o `.env`**. `WSF_2FA_PLATAFORMA` (proprietário) inalterado (**obrigatório**). |
| `app/Http/Controllers/SignupController.php` | opção do cadastro: com 2FA → tela de ativação; sem → painel |
| `resources/views/auth/signup.blade.php` | caixa "Quero ativar a verificação em duas etapas (recomendado)" |
| `app/Http/Controllers/SecurityController.php` | desativar exige **senha atual + código válido** do aplicativo (ou código de recuperação); tentativa recusada registrada na auditoria; proprietário não desativa |
| `resources/views/security/show.blade.php` | situação (Ativa/Desativada), ativar, desativar com senha + código |
| `resources/views/security/two-factor.blade.php` | "Agora não — ativar depois em Segurança" para clientes (o proprietário não tem essa opção) |
| `lang/pt_BR/validation.php` | **todas** as 137 regras de validação do Laravel 13 + nomes dos campos (senha, e-mail, nome…) |
| `lang/pt_BR/passwords.php`, `auth.php`, `pagination.php` | redefinição de senha, login, paginação |
| `lang/pt_BR.json` | textos do layout dos e-mails ("Olá!", "Se não conseguir clicar no botão…", "Todos os direitos reservados.") e mensagens internas |
| `.env.example`, `docs/SEGURANCA.md`, `docs/HOMOLOGACAO.md`, `README.md`, `VERSION` | documentação e versão 1.1.6 |
| `tests/Feature/TwoFactorOptionalAndPtBrTest.php` | 7 testes novos |

Sem migração, sem alteração no banco ou em dados; SMTP, auditoria, WhatsApp e Asaas inalterados. Clientes que já ativaram o 2FA continuam com ele.

## 3. Testes

**Automatizados:** 128 por banco — SQLite 124 aprovados/4 pulados; MariaDB 10.11, MySQL 8.0 e Percona 5.7 127 aprovados/1 pulado (os pulados dependem de recursos de outro banco). Novos: padrão da configuração (cliente opcional mesmo com a linha antiga `true` no ambiente; proprietário obrigatório); cadastro sem 2FA + login só com senha; cadastro com 2FA + login exigindo o código; desativar recusado sem senha, com senha errada ou código errado, aceito com senha + código, reativar; proprietário obrigado e sem poder desativar; mensagens de validação de 5 telas sem inglês (inclui "A senha deve conter pelo menos um caractere especial."); e-mail de recuperação em português + link redefine a senha.

**Ponta a ponta (Apache + Percona 5.7; instância 1.1.5 com `WSF_2FA_ADMIN_CLIENTE=true` no `.env`, como a homologação; atualização pelo painel):**

| Etapa | Resultado |
|---|---|
| 1.1.5: cadastro sem marcar nada | levado à ativação obrigatória; mensagens em inglês ("The password field must contain at least one symbol.") |
| Atualização 1.1.5 → 1.1.6 | concluída; `.env` intocado |
| Cadastro com 2FA **desativado** | entra direto no painel; Segurança "Desativada"; sai e entra **só com e-mail e senha** |
| Cadastro com 2FA **ativado** | tela de ativação (com "Agora não"); ativado; login pede o código; código errado → "Código inválido."; código certo → painel |
| Proprietário | 2FA ativo, **sem** opção de desativar, "Obrigatória para administradores da plataforma" |
| Mensagens | "A confirmação da senha não confere." · "A senha deve conter pelo menos uma letra maiúscula e uma minúscula." · "**A senha deve conter pelo menos um caractere especial.**" · "A senha deve conter pelo menos um número." · "Informe o e-mail." · "Informe a senha." |
| Recuperação (SMTPS real local) | e-mail "Redefinição de senha — WS Finanças em Dia", **nenhum texto em inglês**; link abre o formulário; senha sem caractere especial → mensagem em português; nova senha aceita; login com a nova senha |
| Auditoria | íntegra |

Log: `e2e-1.1.6.log`.

## 4. Instalação na homologação (após sua autorização)

1. Enviar `ws-financas-1.1.6.zip` para `storage/app/updates/pacotes` e conferir o SHA-256 (`ws-financas-1.1.6.zip.sha256`).
2. *Atualizações → Analisar → Iniciar* (aceita a partir da 1.1.5 instalada). Sem migração; **não é preciso editar o `.env`**.
3. Conferir: novo cadastro sem marcar 2FA entra direto; com 2FA marcado, pede a ativação; *Esqueci minha senha* chega em português.
4. Opcional: para voltar a exigir 2FA dos administradores de cliente, `WSF_2FA_CLIENTE_OBRIGATORIO=true` no `.env`.
