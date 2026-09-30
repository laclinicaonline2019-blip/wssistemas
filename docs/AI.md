# Inteligência artificial

## Princípios

1. **A IA não substitui o médico.** Não diagnostica, não prescreve, não interpreta exames para o paciente.
2. **Nada inventado:** a IA só informa dados vindos de ferramentas do sistema (agenda, preços,
   endereço, horários). Se não sabe, diz que não sabe e oferece atendimento humano.
3. **Revisão humana obrigatória** para qualquer conteúdo clínico gerado (resumos, extrações de OCR).
4. **Tudo registrado:** conversas, chamadas de ferramentas, custo/tokens (`ai_conversations`,
   `ai_messages`, `ai_tool_calls`, `ai_usage`).
5. **Minimização (LGPD):** apenas o necessário é enviado ao provedor; provedores com contrato de
   tratamento de dados e sem uso dos dados para treinamento.

## Arquitetura

```
Canal (WhatsApp / chat do site / painel) → fila → ConversationOrchestrator
   → AiProviderInterface (Claude via API Anthropic | MockAiProvider)
   → Ferramentas (tool use) executadas NO BACKEND com o TenantContext da clínica:
       list_specialties · find_doctors · check_availability · hold_slot · book_appointment
       · cancel_appointment · reschedule · create_charge · get_payment_status
       · get_clinic_info · handoff_to_human
```

- **Agendamento:** `check_availability` consulta a agenda real (grade, limites por período,
  encaixes, bloqueios, feriados). `hold_slot` reserva o horário por poucos minutos com lock;
  `book_appointment` só confirma após validação transacional (a mesma usada pela recepção),
  protegida pela exclusion constraint. Esgotado o período, a IA procura o próximo horário livre.
- **Pagamento:** a IA gera a cobrança e informa o link; a confirmação vem apenas do webhook
  validado — a IA nunca "aceita" comprovante como pagamento (o comprovante vai para conferência humana).
- **Handoff:** pedido explícito, baixa confiança, reclamação ou tema clínico → conversa marcada
  para a fila da recepção (`ia.conversas`), com resumo.
- **Personalidade por clínica:** nome, saudação, tom e regras configuráveis (`ia.configurar`),
  sempre acrescidas das regras de segurança fixas do sistema.
- **Áudio:** transcrição (speech-to-text) → mesmo fluxo; resposta por voz opcional (TTS).
- **Imagem/OCR:** receitas, pedidos de exame e comprovantes → extração estruturada
  (medicamento, concentração, posologia) marcada como **"não verificada"** até revisão; pergunta ao
  paciente se deseja agendar ou apenas registrar o documento.
- **Assistente clínico (área do médico):** resumo do histórico e preenchimento assistido de
  campos, sempre como sugestão editável, com indicação de fonte.
- **Limites:** recursos de IA habilitados por plano (`ai_enabled`) e quotas mensais.

## Modelo

Provedor padrão planejado: Claude (Anthropic), configurável por `AI_MODEL`; `AI_MODE=mock` em
desenvolvimento. O provedor pode ser trocado sem alterar o orquestrador.
