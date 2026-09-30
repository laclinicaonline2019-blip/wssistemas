# LGPD e privacidade

> O sistema oferece **mecanismos técnicos** que apoiam a conformidade com a LGPD (Lei 13.709/2018).
> Conformidade depende também de medidas jurídicas e organizacionais da clínica (controladora) e
> da operadora da plataforma. **Não afirmamos conformidade automática.**

## Papéis

- **Clínica:** controladora dos dados de seus pacientes.
- **Plataforma (AivexaClínica):** operadora — trata dados conforme instruções da clínica (contrato/DPA).
- Dados de saúde são **dados pessoais sensíveis** (art. 5º, II; art. 11).

## Mecanismos existentes (Fases 1–2)

- Isolamento lógico por clínica, controle de acesso por perfil e filial (necessidade de saber).
- Super Admin sem acesso a dados clínicos.
- Trilha de auditoria imutável de acessos e alterações; exportação para investigações.
- Criptografia de segredos; senhas com Argon2id; 2FA.
- Segregação de ambientes; seeds apenas com dados fictícios.

## Mecanismos planejados

| Direito / princípio | Mecanismo | Fase |
|---|---|---|
| Consentimento e finalidade | ✅ `patient_consents`: finalidade, versão do termo, canal, quem registrou, IP; concessão e revogação são registros imutáveis (histórico é a prova). O atendimento em si não depende de consentimento (art. 11, II, "f") | 3 |
| Acesso e portabilidade (art. 18) | ✅ exportação JSON dos dados do titular (`paciente.exportar`), auditada; PDF no portal (Fase 10) | 3/10 |
| Correção | ✅ edição com histórico de alterações (tela do paciente) | 3 |
| Anonimização / eliminação | ✅ anonimização irreversível de dados cadastrais (`paciente.anonimizar`, empresa toda, com motivo/protocolo); mantém nº de prontuário, ano de nascimento, sexo e cidade/UF. **Prontuário não é eliminado** antes do prazo legal (20 anos — Lei 13.787/2018) | 3 |
| Registro de acesso ao cadastro | ✅ auditoria `patient.viewed` (web e API) | 3 |
| Minimização em listas | ✅ CPF mascarado em listagens e busca | 3 |
| Registro de acesso a prontuário | auditoria `medical_record.viewed` | 5 |
| Minimização | painel de chamadas mostra só senha/nome parcial; IA recebe o mínimo necessário | 4/12 |
| Retenção | políticas configuráveis por tipo de dado, com rotinas agendadas | 17 |
| Incidentes | procedimento em SECURITY.md | — |

## Ponto de atenção: auditoria × anonimização

A trilha de auditoria é imutável e registra valores anteriores/novos das alterações de cadastro.
Após uma anonimização, os eventos **antigos** continuam contendo dados pessoais (base legal:
segurança, prestação de contas e exercício regular de direitos). O evento de anonimização em si
não copia dados pessoais. O DPO deve definir o prazo de retenção da trilha (a expurgação exigirá
rotina própria que preserve a cadeia de integridade).

## Pontos que dependem da organização

Nomeação de encarregado (DPO), base legal de cada tratamento, termos de uso e política de
privacidade, contratos com suboperadores (nuvem, gateways, WhatsApp, IA), treinamento da equipe,
relatório de impacto (RIPD) e comunicação de incidentes à ANPD.
