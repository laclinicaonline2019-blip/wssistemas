<?php

namespace App\Modules\Ai\Providers;

use Anthropic\Beta\Messages\BetaMessage;
use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use App\Modules\Ai\Models\AiConfig;
use Psr\Http\Client\ClientInterface;

/**
 * Claude (Anthropic) pelo SDK oficial para PHP.
 *
 * - Ciclo de ferramentas manual (stop_reason "tool_use" → tool_result), no máximo MAX_STEPS chamadas.
 * - Prompt caching: ferramentas + instruções fixas da clínica ficam no prefixo com cache;
 *   o contexto que muda (data/hora, paciente, rascunho) vai em um segundo bloco, sem cache.
 * - Pensamento adaptativo (padrão do modelo) com esforço "low" — conversa de recepção.
 * - Recusa por política: fallback no servidor ("default") e, se ainda assim recusar, a conversa
 *   vai para a equipe.
 */
class ClaudeProvider implements LlmProvider
{
    public const MAX_STEPS = 8;

    /** Modelos que aceitam o fallback de recusa no servidor. */
    private const FALLBACK_MODELS = ['claude-fable-5-1', 'claude-opus-5-5', 'claude-opus-5', 'claude-sonnet-5-5'];

    public function __construct(private readonly ?ClientInterface $transporter = null) {}

    public function run(AiConfig $config, string $staticSystem, string $dynamicContext, array $history, array $tools, callable $execute, callable $onRequest): AgentResult
    {
        $client = $this->client($config);
        $model = $config->modelName();
        $messages = array_map(fn ($m) => ['role' => $m['role'], 'content' => $m['text']], $history);
        $toolDefs = array_map(fn ($t) => ['name' => $t['name'], 'description' => $t['description'], 'inputSchema' => $t['schema']], $tools);
        $system = [
            ['type' => 'text', 'text' => $staticSystem, 'cacheControl' => ['type' => 'ephemeral']],
            ['type' => 'text', 'text' => $dynamicContext],
        ];

        return $this->loop($client, $config, $model, $messages, $system, $toolDefs, $execute, $onRequest);
    }

    /** Imagem (bloco image) ou PDF (bloco document) + structured outputs (output_config.format). */
    public function extractDocument(AiConfig $config, string $instructions, string $bytes, string $mime, array $schema, callable $onRequest): array
    {
        $client = $this->client($config);
        $model = $config->modelName();
        $source = ['type' => 'base64', 'mediaType' => $mime, 'data' => base64_encode($bytes)];
        $block = $mime === 'application/pdf' ? ['type' => 'document', 'source' => $source] : ['type' => 'image', 'source' => $source];
        $started = microtime(true);

        try {
            /** @var BetaMessage $response */
            $response = $client->beta->messages->create(
                maxTokens: 8000,
                messages: [['role' => 'user', 'content' => [$block, ['type' => 'text', 'text' => 'Transcreva e organize este documento conforme as regras.']]]],
                model: $model,
                outputConfig: ['effort' => 'low', 'format' => ['type' => 'json_schema', 'schema' => $schema]],
                system: $instructions,
            );
        } catch (APIStatusException|APIConnectionException $e) {
            $onRequest(['provider' => 'claude', 'model' => $model, 'latency_ms' => (int) ((microtime(true) - $started) * 1000), 'error' => mb_substr($e->getMessage(), 0, 500)]);
            throw new AiProviderException('Anthropic não leu o documento: '.mb_substr($e->getMessage(), 0, 200), 0, $e);
        }

        $onRequest(['provider' => 'claude', 'model' => $response->model, 'input_tokens' => $response->usage->inputTokens, 'output_tokens' => $response->usage->outputTokens,
            'cache_read_tokens' => (int) $response->usage->cacheReadInputTokens, 'stop_reason' => $response->stopReason, 'latency_ms' => (int) ((microtime(true) - $started) * 1000)]);
        if ($response->stopReason === 'refusal' || $response->stopReason === 'max_tokens') {
            throw new AiProviderException('A IA não concluiu a leitura ('.$response->stopReason.').');
        }

        $text = '';
        foreach ($response->content as $b) {
            if ($b->type === 'text') {
                $text .= $b->text;
            }
        }
        $data = json_decode($text, true);

        return is_array($data) ? $data : throw new AiProviderException('A IA devolveu um formato inválido.');
    }

    private function client(AiConfig $config): Client
    {
        $key = $config->apiKey();
        if (! $key) {
            throw new AiProviderException('Chave da API da Anthropic não configurada.');
        }

        return new Client(apiKey: $key, authToken: '', baseUrl: (string) config('services.anthropic.base_url'), requestOptions: array_filter(['timeout' => 90.0, 'maxRetries' => 2, 'transporter' => $this->transporter]));
    }

    private function loop(Client $client, AiConfig $config, string $model, array $messages, array $system, array $toolDefs, callable $execute, callable $onRequest): AgentResult
    {
        $fallback = in_array($model, self::FALLBACK_MODELS, true);

        for ($step = 0; $step < self::MAX_STEPS; $step++) {
            $started = microtime(true);
            try {
                /** @var BetaMessage $response */
                $response = $client->beta->messages->create(
                    maxTokens: 4096,
                    messages: $messages,
                    model: $model,
                    outputConfig: ['effort' => (string) $config->setting('effort', 'low')],
                    system: $system,
                    tools: $toolDefs ?: null,
                    fallbacks: $fallback ? 'default' : null,
                    betas: $fallback ? ['server-side-fallback-2026-07-01'] : null,
                );
            } catch (APIStatusException $e) {
                $onRequest(['provider' => 'claude', 'model' => $model, 'latency_ms' => (int) ((microtime(true) - $started) * 1000), 'error' => mb_substr($e->getMessage(), 0, 500)]);
                throw new AiProviderException('Anthropic recusou a chamada: '.mb_substr($e->getMessage(), 0, 200), 0, $e);
            } catch (APIConnectionException $e) {
                $onRequest(['provider' => 'claude', 'model' => $model, 'latency_ms' => (int) ((microtime(true) - $started) * 1000), 'error' => 'conexão']);
                throw new AiProviderException('Sem conexão com a API da Anthropic.', 0, $e);
            }

            $onRequest([
                'provider' => 'claude', 'model' => $response->model, 'input_tokens' => $response->usage->inputTokens,
                'output_tokens' => $response->usage->outputTokens, 'cache_read_tokens' => (int) $response->usage->cacheReadInputTokens,
                'stop_reason' => $response->stopReason, 'latency_ms' => (int) ((microtime(true) - $started) * 1000),
            ]);

            if ($response->stopReason === 'refusal') {
                return new AgentResult('', 'refusal', refused: true);
            }

            if ($response->stopReason !== 'tool_use') {
                $text = '';
                foreach ($response->content as $block) {
                    if ($block->type === 'text') {
                        $text .= $block->text;
                    }
                }

                return new AgentResult(trim($text), (string) $response->stopReason);
            }

            // Todos os tool_result da rodada voltam numa única mensagem do usuário.
            $results = [];
            foreach ($response->content as $block) {
                if ($block->type === 'tool_use') {
                    $out = $execute($block->name, $block->input);
                    $results[] = ['type' => 'tool_result', 'toolUseID' => $block->id, 'content' => json_encode($out, JSON_UNESCAPED_UNICODE), 'isError' => isset($out['error'])];
                }
            }
            $messages[] = ['role' => 'assistant', 'content' => $response->content];
            $messages[] = ['role' => 'user', 'content' => $results];
        }

        return new AgentResult('', 'max_steps');
    }
}
