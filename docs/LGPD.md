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
| Consentimento e finalidade | `patient_consents` (finalidade, versão do termo, data, canal, revogação) — ex.: comunicações por WhatsApp | 3 |
| Acesso e portabilidade (art. 18) | exportação dos dados do paciente (JSON/PDF) — `paciente.exportar` | 3/10 |
| Correção | edição com histórico de alterações | 3 |
| Anonimização / eliminação | anonimização de dados cadastrais quando permitido; **prontuário não é eliminado** antes do prazo legal (20 anos — Lei 13.787/2018; CFM 1.821/2007) | 3/17 |
| Registro de acesso a prontuário | auditoria `medical_record.viewed` | 5 |
| Minimização | painel de chamadas mostra só senha/nome parcial; IA recebe o mínimo necessário | 4/12 |
| Retenção | políticas configuráveis por tipo de dado, com rotinas agendadas | 17 |
| Incidentes | procedimento em SECURITY.md | — |

## Pontos que dependem da organização

Nomeação de encarregado (DPO), base legal de cada tratamento, termos de uso e política de
privacidade, contratos com suboperadores (nuvem, gateways, WhatsApp, IA), treinamento da equipe,
relatório de impacto (RIPD) e comunicação de incidentes à ANPD.
