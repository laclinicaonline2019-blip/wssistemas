<?php

namespace App\Modules\Ai\Providers;

use App\Modules\Ai\Models\AiConfig;

/**
 * Provedor de modelo de linguagem com uso de ferramentas (tool use).
 *
 * O provedor conduz o ciclo "modelo pede ferramenta → sistema executa → devolve resultado"
 * até a resposta final. As ferramentas são sempre executadas pelo SISTEMA (com as regras da
 * agenda, permissões e isolamento da clínica) — o modelo só pede.
 */
interface LlmProvider
{
    /**
     * @param  list<array{role: 'user'|'assistant', text: string}>  $history  conversa (texto), da mais antiga para a mais nova
     * @param  list<array{name: string, description: string, schema: array}>  $tools
     * @param  callable(string $name, array $input): array  $execute  executa a ferramenta e devolve o resultado
     * @param  callable(array $usage): void  $onRequest  registra tokens/latência de cada chamada
     */
    public function run(AiConfig $config, string $staticSystem, string $dynamicContext, array $history, array $tools, callable $execute, callable $onRequest): AgentResult;
}
