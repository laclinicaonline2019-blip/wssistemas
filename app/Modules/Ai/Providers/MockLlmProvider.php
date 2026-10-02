<?php

namespace App\Modules\Ai\Providers;

use App\Modules\Ai\Models\AiConfig;

/**
 * MOCK: sem IA real (demonstração). Responde de forma fixa e, se o paciente fala em
 * agendar, consulta os médicos pela ferramenta — o mesmo caminho da IA real.
 */
class MockLlmProvider implements LlmProvider
{
    public function run(AiConfig $config, string $staticSystem, string $dynamicContext, array $history, array $tools, callable $execute, callable $onRequest): AgentResult
    {
        $last = mb_strtolower((string) (collect($history)->last()['text'] ?? ''));
        $onRequest(['provider' => 'mock', 'model' => 'mock', 'stop_reason' => 'end_turn']);

        if (str_contains($last, 'agend') || str_contains($last, 'consulta') || str_contains($last, 'marcar')) {
            $doctors = $execute('list_doctors', []);
            $names = collect($doctors['doctors'] ?? [])->pluck('name')->take(5)->implode(', ');

            return new AgentResult("[MOCK] Posso ajudar a agendar. Médicos disponíveis: {$names}. Com qual deseja marcar?", 'end_turn');
        }

        return new AgentResult('[MOCK] Olá! Sou a assistente virtual (modo de demonstração). Posso ajudar com agendamentos, horários e informações da clínica.', 'end_turn');
    }
}
