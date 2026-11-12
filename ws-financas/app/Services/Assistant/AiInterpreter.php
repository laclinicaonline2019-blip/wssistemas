<?php

namespace App\Services\Assistant;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use App\Models\UsageCounter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Plano B do interpretador: quando as regras não entendem a mensagem, o Claude extrai os campos
 * em JSON (structured outputs). Resultado da IA SEMPRE passa por confirmação do usuário.
 * Também gera a "Análise do Claude" do fechamento do mês.
 */
class AiInterpreter
{
    private const FALLBACK_MODELS = ['claude-fable-5-2', 'claude-fable-5-1', 'claude-opus-5-5', 'claude-opus-5', 'claude-sonnet-5-5'];

    public function __construct(private readonly ?Client $client = null) {}

    public function available(): bool
    {
        return config('wsf.ai.mode') !== 'off' && (string) config('services.anthropic.key') !== '';
    }

    /** @param list<string> $companyNames */
    public function interpret(string $accountId, string $text, array $companyNames, CarbonImmutable $today): ?array
    {
        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['intent', 'amount', 'date', 'paid', 'description', 'company', 'installments', 'recurring'],
            'properties' => [
                'intent' => ['type' => 'string', 'enum' => ['despesa', 'receita', 'pagar', 'consulta_pagar', 'consulta_receber', 'resumo', 'desconhecido']],
                'amount' => ['type' => ['number', 'null'], 'description' => 'Valor em reais (ex.: 1200.5)'],
                'date' => ['type' => ['string', 'null'], 'description' => 'AAAA-MM-DD (vencimento ou data do pagamento/recebimento)'],
                'paid' => ['type' => 'boolean'],
                'description' => ['type' => 'string'],
                'company' => ['type' => ['string', 'null'], 'description' => 'Nome exato de uma das empresas listadas, ou null'],
                'installments' => ['type' => ['integer', 'null']],
                'recurring' => ['type' => 'boolean'],
            ],
        ];
        $system = "Você interpreta mensagens curtas de WhatsApp de brasileiros sobre finanças e devolve só os campos pedidos.\n"
            ."despesa = conta/gasto; receita = dinheiro recebido; pagar = marcar como paga uma conta já cadastrada (sem valor novo);\n"
            .'consulta_pagar/consulta_receber/resumo = perguntas. Se não for sobre finanças, use desconhecido. Não invente valores.';
        $user = 'Hoje é '.$today->toDateString().'. Empresas do cliente: '.($companyNames ? implode('; ', $companyNames) : 'nenhuma')."\nMensagem: ".$text;

        $data = $this->call($accountId, $system, $user, 1024, $schema);
        if (! is_array($data) || ($data['intent'] ?? 'desconhecido') === 'desconhecido') {
            return null;
        }

        return [
            'intent' => $data['intent'],
            'amount_cents' => isset($data['amount']) && $data['amount'] > 0 ? (int) round($data['amount'] * 100) : null,
            'date' => $data['date'] && preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['date']) ? $data['date'] : $today->toDateString(),
            'date_explicit' => true,
            'paid' => (bool) $data['paid'],
            'recurring' => (bool) $data['recurring'],
            'installments' => isset($data['installments']) && $data['installments'] >= 2 ? min(120, (int) $data['installments']) : null,
            'description' => mb_substr(trim((string) $data['description']), 0, 120),
            'company_hint' => $data['company'] ?: null,
            'period' => null,
            'raw' => $text,
            'ai' => true,
        ];
    }

    /** Análise do fechamento em linguagem simples (substitui o "Pedir análise ao Claude" do painel original). */
    public function analysis(string $accountId, string $prompt): ?string
    {
        return $this->call($accountId, null, $prompt, 2000, null);
    }

    private function call(string $accountId, ?string $system, string $user, int $maxTokens, ?array $schema): mixed
    {
        if (! $this->available()) {
            return null;
        }
        $model = (string) config('services.anthropic.model');
        $fallback = in_array($model, self::FALLBACK_MODELS, true);
        $client = $this->client ?? new Client(apiKey: (string) config('services.anthropic.key'), authToken: '', baseUrl: (string) config('services.anthropic.base_url'),
            requestOptions: ['timeout' => 45.0, 'maxRetries' => 1]);

        try {
            $outputConfig = ['effort' => (string) config('wsf.ai.effort', 'low')];
            if ($schema) {
                $outputConfig['format'] = ['type' => 'json_schema', 'schema' => $schema];
            }
            $response = $client->beta->messages->create(
                maxTokens: $maxTokens,
                messages: [['role' => 'user', 'content' => $user]],
                model: $model,
                outputConfig: $outputConfig,
                system: $system,
                fallbacks: $fallback ? 'default' : null,
                betas: $fallback ? ['server-side-fallback-2026-07-01'] : null,
            );
        } catch (APIStatusException|APIConnectionException $e) {
            Log::warning('IA indisponível: '.mb_substr($e->getMessage(), 0, 300));

            return null;
        }

        UsageCounter::bump($accountId, ['ai_calls' => 1, 'ai_input_tokens' => (int) $response->usage->inputTokens, 'ai_output_tokens' => (int) $response->usage->outputTokens]);

        if ($response->stopReason === 'refusal' || $response->stopReason === 'max_tokens') {
            return null;
        }
        $text = '';
        foreach ($response->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            }
        }

        return $schema ? json_decode($text, true) : trim($text);
    }
}
