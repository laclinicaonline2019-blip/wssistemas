<?php

namespace App\Modules\Ai\Providers;

use App\Modules\Ai\Models\AiConfig;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * ChatGPT (OpenAI) — Chat Completions com "function calling".
 * POST {base}/chat/completions; ferramentas como {type: function, function: {name, description, parameters}};
 * resposta com tool_calls → mensagens {role: tool, tool_call_id, content}. O modelo é escolhido
 * pela clínica (sem padrão fixo — os nomes de modelo da OpenAI mudam com frequência).
 */
class OpenAiProvider implements LlmProvider
{
    public const MAX_STEPS = 8;

    public function run(AiConfig $config, string $staticSystem, string $dynamicContext, array $history, array $tools, callable $execute, callable $onRequest): AgentResult
    {
        $key = $config->apiKey();
        $model = $config->modelName();
        if (! $key || ! $model) {
            throw new AiProviderException('Chave da API da OpenAI ou modelo não configurados.');
        }

        $messages = [['role' => 'system', 'content' => $staticSystem."\n\n".$dynamicContext]];
        foreach ($history as $m) {
            $messages[] = ['role' => $m['role'], 'content' => $m['text']];
        }
        $toolDefs = array_map(fn ($t) => ['type' => 'function', 'function' => ['name' => $t['name'], 'description' => $t['description'], 'parameters' => $t['schema']]], $tools);

        for ($step = 0; $step < self::MAX_STEPS; $step++) {
            $started = microtime(true);
            try {
                $response = Http::withToken($key)->acceptJson()->asJson()->timeout(60)->retry(2, 1000, throw: false)
                    ->post(rtrim((string) config('services.openai.base_url'), '/').'/chat/completions', [
                        'model' => $model, 'messages' => $messages, 'max_completion_tokens' => 4096,
                    ] + ($toolDefs ? ['tools' => $toolDefs, 'tool_choice' => 'auto'] : []));
            } catch (ConnectionException $e) {
                $onRequest(['provider' => 'openai', 'model' => $model, 'latency_ms' => (int) ((microtime(true) - $started) * 1000), 'error' => 'conexão']);
                throw new AiProviderException('Sem conexão com a API da OpenAI.', 0, $e);
            }

            if (! $response->successful()) {
                $error = (string) ($response->json('error.message') ?? 'HTTP '.$response->status());
                $onRequest(['provider' => 'openai', 'model' => $model, 'latency_ms' => (int) ((microtime(true) - $started) * 1000), 'error' => mb_substr($error, 0, 500)]);
                throw new AiProviderException('OpenAI recusou a chamada: '.mb_substr($error, 0, 200));
            }

            $choice = $response->json('choices.0');
            $finish = (string) ($choice['finish_reason'] ?? '');
            $onRequest([
                'provider' => 'openai', 'model' => (string) $response->json('model', $model), 'input_tokens' => (int) $response->json('usage.prompt_tokens'),
                'output_tokens' => (int) $response->json('usage.completion_tokens'), 'cache_read_tokens' => (int) $response->json('usage.prompt_tokens_details.cached_tokens'),
                'stop_reason' => $finish, 'latency_ms' => (int) ((microtime(true) - $started) * 1000),
            ]);

            $message = $choice['message'] ?? [];
            if (! empty($message['refusal'])) {
                return new AgentResult('', 'refusal', refused: true);
            }
            if (empty($message['tool_calls'])) {
                return new AgentResult(trim((string) ($message['content'] ?? '')), $finish);
            }

            $messages[] = ['role' => 'assistant', 'content' => $message['content'] ?? null, 'tool_calls' => $message['tool_calls']];
            foreach ($message['tool_calls'] as $call) {
                $input = json_decode((string) ($call['function']['arguments'] ?? '{}'), true);
                $out = is_array($input) ? $execute((string) $call['function']['name'], $input) : ['error' => 'Argumentos inválidos (JSON).'];
                $messages[] = ['role' => 'tool', 'tool_call_id' => $call['id'], 'content' => json_encode($out, JSON_UNESCAPED_UNICODE)];
            }
        }

        return new AgentResult('', 'max_steps');
    }
}
