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
| Acesso e portabilidade (art. 18) | ✅ exportação JSON dos dados do titular (`paciente.exportar`), auditada; ✅ portal do paciente com documentos em PDF, consultas e pagamentos (Fase 10) | 3/10 |
| Correção | ✅ edição com histórico de alterações (tela do paciente) | 3 |
| Anonimização / eliminação | ✅ anonimização irreversível de dados cadastrais (`paciente.anonimizar`, empresa toda, com motivo/protocolo); mantém nº de prontuário, ano de nascimento, sexo e cidade/UF. **Prontuário não é eliminado** antes do prazo legal (20 anos — Lei 13.787/2018) | 3 |
| Registro de acesso ao cadastro | ✅ auditoria `patient.viewed` (web e API) | 3 |
| Faturamento ao convênio (Fase 9) | Guias e lotes guardam a carteirinha do beneficiário pelo prazo legal de guarda (cumprimento de obrigação legal/contratual — art. 7º, II e V; art. 11, II, "a"). Na anonimização, a carteirinha usada em guia fica inativa e mascarada no cadastro; a guia faturada é preservada | 9 |
| Portal do paciente (Fase 10) | Acesso do titular aos próprios dados com login individual; aceite de termos na ativação; acessos e downloads registrados; CPF mascarado; o conteúdo do prontuário não é exibido (cópia por solicitação à clínica); anonimização bloqueia o acesso | 10 |
| Minimização em listas | ✅ CPF mascarado em listagens e busca | 3 |
| Registro de acesso a prontuário | ✅ auditoria `medical_record.viewed` (web e API), início, finalização e adendos — **sem conteúdo clínico** na trilha (apenas versão e hash) | 5 |
| Sigilo do prontuário | ✅ recepção/financeiro não veem dados clínicos; somente o médico autor edita; Super Admin não acessa prontuários | 5 |
| Integridade e guarda (Lei 13.787/2018, CFM 1.821/2007) | ✅ versões imutáveis com hash encadeado; correções apenas por adendo; sem exclusão | 5 |
| Validação pública de documentos | ✅ mostra só tipo, data, médico, situação e **iniciais** do paciente; código aleatório de 12 caracteres (não enumerável) e limite de consultas | 6 |
| Anexos do paciente | ✅ área privada fora da pasta pública, por clínica; download somente autenticado, com permissão e auditado; nunca excluídos (arquivamento) | 6 |
| CID em atestado | ✅ somente com autorização expressa do paciente (registrada no documento) | 6 |
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
