<?php

namespace App\Modules\Ai\Providers;

use App\Modules\Ai\Models\AiConfig;

/**
 * MOCK: sem IA real (demonstração). Responde de forma fixa e, se o paciente fala em
 * agendar, consulta os médicos pela ferramenta — o mesmo caminho da IA real.
 */
class MockLlmProvider implements LlmProvider
{
    /** MOCK: leitura simulada e identificada — nunca é usada como dado real. */
    public function extractDocument(AiConfig $config, string $instructions, string $bytes, string $mime, array $schema, callable $onRequest): array
    {
        $onRequest(['provider' => 'mock', 'model' => 'mock', 'stop_reason' => 'end_turn']);

        return ['doc_type' => 'exam_request', 'summary' => '[MOCK] Leitura simulada — pedido de exame de demonstração.', 'patient_name' => '', 'document_date' => '',
            'professional_name' => '', 'professional_registry' => '', 'medications' => [], 'exams' => [['name' => '[MOCK] Hemograma completo', 'code' => '']],
            'payment' => ['amount' => '', 'paid_at' => '', 'payer_name' => '', 'receiver_name' => '', 'method' => '', 'transaction_id' => ''],
            'legibility' => 'good', 'uncertain_fields' => [], 'raw_text' => '[MOCK] Texto simulado.'];
    }

    public function run(AiConfig $config, string $staticSystem, string $dynamicContext, array $history, array $tools, callable $execute, callable $onRequest): AgentResult
    {
        $last = mb_strtolower((string) (collect($history)->last()['text'] ?? ''));
        $onRequest(['provider' => 'mock', 'model' => 'mock', 'stop_reason' => 'end_turn']);

        if (str_contains($last, 'não verificado') || str_starts_with($last, '🎤')) {
            return new AgentResult('[MOCK] Recebi seu arquivo/áudio. A equipe vai conferir o documento. Deseja agendar uma consulta ou exame, ou só deixar registrado?', 'end_turn');
        }

        if (str_contains($last, 'agend') || str_contains($last, 'consulta') || str_contains($last, 'marcar')) {
            $doctors = $execute('list_doctors', []);
            $names = collect($doctors['doctors'] ?? [])->pluck('name')->take(5)->implode(', ');

            return new AgentResult("[MOCK] Posso ajudar a agendar. Médicos disponíveis: {$names}. Com qual deseja marcar?", 'end_turn');
        }

        return new AgentResult('[MOCK] Olá! Sou a assistente virtual (modo de demonstração). Posso ajudar com agendamentos, horários e informações da clínica.', 'end_turn');
    }
}
