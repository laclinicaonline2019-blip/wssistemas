<?php

namespace App\Services;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use App\Models\AiRequest;
use App\Models\Setting;
use App\Support\Text;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * IA (Claude, SDK oficial da Anthropic) para dar velocidade ao televendas:
 *  - lê o pedido do cliente (texto, foto da receita ou da caixa do remédio) e monta os itens da cotação;
 *  - lê a nota fiscal/cupom (CNPJ da farmácia, itens, valores) para a captação da venda.
 * Saída sempre em JSON validado por schema. A IA só transcreve: o televendas confere tudo.
 * Sem chave configurada, os itens de texto são separados por regras simples (modo assistido).
 */
class AiAssistant
{
    private const ITEMS_PROMPT = <<<'TXT'
    Você organiza pedidos de cotação de medicamentos enviados por clientes de farmácia no Brasil (texto, foto de receita, foto de caixa ou lista).
    Extraia cada produto solicitado como um item.
    Regras:
    - Copie o nome como escrito (corrigindo apenas erros óbvios de digitação de nomes comerciais conhecidos). Não invente medicamentos, doses ou concentrações.
    - presentation: concentração e forma/quantidade da embalagem quando informadas (ex.: "500mg 20 comprimidos"); senão "".
    - quantity: número de caixas/unidades pedidas; 1 se não informado.
    - preference: "generico" se o cliente pediu genérico, "referencia" se pediu o de marca/ético/referência, "similar" se pediu similar; senão "qualquer".
    - notes: observações do cliente sobre o item; senão "".
    - Ignore saudações e textos que não sejam produtos. Se não houver produto, devolva items vazio.
    TXT;

    private const RECEIPT_PROMPT = <<<'TXT'
    Você transcreve notas fiscais e cupons fiscais (NFC-e/NF-e/cupom) de farmácias brasileiras.
    Regras:
    - Copie exatamente o que está escrito; não deduza valores. Campo ilegível = "" (ou 0 para números).
    - issuer_cnpj: CNPJ do emitente (a farmácia), só dígitos. issuer_name: razão social ou nome fantasia do emitente.
    - nf_number: número da nota/cupom. nf_key: chave de acesso de 44 dígitos, só dígitos.
    - purchase_date: data da emissão no formato AAAA-MM-DD.
    - items: cada produto com description, quantity, unit_price e total (números com ponto decimal).
    - total: valor total pago (após descontos).
    TXT;

    public function enabled(): bool
    {
        return filled(config('pesquisafarma.anthropic.api_key')) && Setting::get('ai_enabled') === '1';
    }

    /** @return array<int, array{description:string, presentation:string, quantity:int, preference:string, notes:string}> */
    public function extractItems(?string $text, ?string $bytes = null, ?string $mime = null): array
    {
        if ($this->enabled() && (filled($text) || $bytes)) {
            try {
                $schema = $this->obj(['items' => ['type' => 'array', 'items' => $this->obj([
                    'description' => ['type' => 'string'], 'presentation' => ['type' => 'string'], 'quantity' => ['type' => 'integer'],
                    'preference' => ['type' => 'string', 'enum' => ['qualquer', 'referencia', 'generico', 'similar']], 'notes' => ['type' => 'string'],
                ])]]);
                $data = $this->call('itens_cotacao', self::ITEMS_PROMPT, $text ? "Mensagem do cliente:\n".$text : 'Extraia os itens da imagem.', $bytes, $mime, $schema);

                return $this->cleanItems((array) ($data['items'] ?? []));
            } catch (Throwable $e) {
                Log::warning('IA não extraiu itens: '.$e->getMessage());
            }
        }

        return $this->cleanItems($this->parseText((string) $text));
    }

    /**
     * Classifica a foto enviada pelo cliente: nota fiscal/cupom de compra, receita/lista/caixa para cotar, ou outra coisa.
     * Devolve null quando a IA não está disponível (o robô então pergunta ao cliente).
     */
    public function classifyImage(string $bytes, string $mime): ?string
    {
        if (! $this->enabled()) {
            return null;
        }
        try {
            $data = $this->call('classificar_foto', 'Você classifica fotos enviadas por clientes de farmácia no Brasil. "nota_fiscal": nota fiscal, NFC-e, cupom fiscal ou comprovante de compra com itens e valores. "receita": receita médica, lista de medicamentos, foto de caixa/embalagem de remédio. "outro": qualquer outra coisa.',
                'Classifique esta imagem.', $bytes, $mime, $this->obj(['tipo' => ['type' => 'string', 'enum' => ['nota_fiscal', 'receita', 'outro']]]));

            return in_array($data['tipo'] ?? null, ['nota_fiscal', 'receita'], true) ? $data['tipo'] : null;
        } catch (Throwable $e) {
            Log::warning('IA não classificou a foto: '.$e->getMessage());

            return null;
        }
    }

    /** @return array<string, mixed> */
    public function readReceipt(string $bytes, string $mime): array
    {
        if (! $this->enabled()) {
            return [];
        }
        $num = ['type' => 'number'];
        $str = ['type' => 'string'];
        $schema = $this->obj([
            'issuer_cnpj' => $str, 'issuer_name' => $str, 'nf_number' => $str, 'nf_key' => $str, 'purchase_date' => $str, 'total' => $num,
            'items' => ['type' => 'array', 'items' => $this->obj(['description' => $str, 'quantity' => $num, 'unit_price' => $num, 'total' => $num])],
        ]);
        try {
            return $this->call('nota_fiscal', self::RECEIPT_PROMPT, 'Transcreva esta nota fiscal conforme as regras.', $bytes, $mime, $schema);
        } catch (Throwable $e) {
            Log::warning('IA não leu a nota: '.$e->getMessage());

            return [];
        }
    }

    /** Leitura por regras: linha, vírgula, ";" ou " e " separam produtos; "2x", "2 cx" = quantidade. */
    public function parseText(string $text): array
    {
        $noise = '/\b(ol[aá]|oi+|bom dia|boa tarde|boa noite|tudo bem\??|por favor|pfv|obrigad[oa]|gostaria de (cotar|saber o pre[cç]o d[eoa]s?)|'
            .'preciso d[eoa]s?|quero cotar|queria cotar|cotar|cota[cç][aã]o d[eoa]s?|quanto (custa|t[aá])|qual o pre[cç]o d[eoa]s?|vcs? t[eê]m|voc[eê]s t[eê]m)\b[\s,!.:?]*/iu';
        $items = [];
        foreach (preg_split('/[\n;]+|,(?!\d)|\s+e\s+(?=\d|[[:alpha:]])/u', (string) $text) as $line) {
            $line = trim(preg_replace($noise, ' ', $line));
            $line = trim(preg_replace('/^[\-\*•]+\s*|^\d+[\.\)]\s+/u', '', $line), " \t.!?:");
            if (mb_strlen($line) < 3 || ! preg_match('/[[:alpha:]]{3,}/u', $line)) {
                continue;
            }
            $qty = 1;
            if (preg_match('/^(\d{1,2})\s*(x|cx|caixas?|un|unid\.?|unidades?)?\s+/iu', $line, $m)) {
                $qty = (int) $m[1];
                $line = trim(substr($line, strlen($m[0])));
            } elseif (preg_match('/\s+(\d{1,2})\s*(x|cx|caixas?|un|unidades?)$/iu', $line, $m)) {
                $qty = (int) $m[1];
                $line = trim(substr($line, 0, -strlen($m[0])));
            }
            $pref = 'qualquer';
            if (preg_match('/gen[eé]rico/iu', $line)) {
                $pref = 'generico';
            } elseif (preg_match('/\b(refer[eê]ncia|[eé]tico|original|de marca)\b/iu', $line)) {
                $pref = 'referencia';
            } elseif (preg_match('/\bsimilar\b/iu', $line)) {
                $pref = 'similar';
            }
            $line = trim(preg_replace('/\s*\b(gen[eé]rico|refer[eê]ncia|[eé]tico|original|de marca|similar)\b\s*/iu', ' ', $line));
            $presentation = '';
            if (preg_match('/\s(\d+[\.,]?\d*\s?(mg|ml|g|mcg|ui|%)(\b|\s).*)$/iu', $line, $m)) {
                $presentation = trim($m[1]);
                $line = trim(substr($line, 0, -strlen($m[0])));
            }
            if (mb_strlen($line) < 3) {
                continue;
            }
            $items[] = ['description' => $line, 'presentation' => $presentation, 'quantity' => $qty, 'preference' => $pref, 'notes' => ''];
        }

        return $items;
    }

    private function cleanItems(array $items): array
    {
        $out = [];
        foreach ($items as $i) {
            $desc = trim((string) ($i['description'] ?? ''));
            if ($desc === '') {
                continue;
            }
            $out[] = [
                'description' => mb_substr($desc, 0, 200),
                'presentation' => mb_substr(trim((string) ($i['presentation'] ?? '')), 0, 120),
                'quantity' => max(1, min(99, (int) ($i['quantity'] ?? 1))),
                'preference' => in_array($i['preference'] ?? '', ['referencia', 'generico', 'similar'], true) ? $i['preference'] : 'qualquer',
                'notes' => mb_substr(trim((string) ($i['notes'] ?? '')), 0, 200),
            ];
        }

        return array_slice($out, 0, 40);
    }

    private function obj(array $props): array
    {
        return ['type' => 'object', 'properties' => $props, 'required' => array_keys($props), 'additionalProperties' => false];
    }

    private function call(string $purpose, string $system, string $instruction, ?string $bytes, ?string $mime, array $schema): array
    {
        $model = (string) config('pesquisafarma.anthropic.model');
        $client = new Client(apiKey: (string) config('pesquisafarma.anthropic.api_key'), authToken: '', baseUrl: (string) config('pesquisafarma.anthropic.base_url'),
            requestOptions: ['timeout' => 60.0, 'maxRetries' => 2]);
        $content = [];
        if ($bytes) {
            $source = ['type' => 'base64', 'mediaType' => $mime ?: 'image/jpeg', 'data' => base64_encode($bytes)];
            $content[] = $mime === 'application/pdf' ? ['type' => 'document', 'source' => $source] : ['type' => 'image', 'source' => $source];
        }
        $content[] = ['type' => 'text', 'text' => $instruction];
        $fallback = in_array($model, ['claude-opus-5-5', 'claude-opus-5', 'claude-sonnet-5-5', 'claude-fable-5-2', 'claude-fable-5-1'], true);
        $started = microtime(true);
        $log = fn (array $extra) => AiRequest::create(['purpose' => $purpose, 'model' => $model, 'latency_ms' => (int) ((microtime(true) - $started) * 1000)] + $extra);

        try {
            $response = $client->beta->messages->create(
                maxTokens: 8000,
                messages: [['role' => 'user', 'content' => $content]],
                model: $model,
                // Esforço baixo: tarefa de transcrição, prioridade é velocidade no atendimento.
                outputConfig: ['effort' => 'low', 'format' => ['type' => 'json_schema', 'schema' => $schema]],
                system: $system,
                fallbacks: $fallback ? 'default' : null,
                betas: $fallback ? ['server-side-fallback-2026-07-01'] : null,
            );
        } catch (APIStatusException|APIConnectionException $e) {
            $log(['error' => mb_substr($e->getMessage(), 0, 250)]);
            throw $e;
        }

        $log(['model' => $response->model, 'input_tokens' => $response->usage->inputTokens, 'output_tokens' => $response->usage->outputTokens]);
        if (in_array($response->stopReason, ['refusal', 'max_tokens'], true)) {
            throw new \RuntimeException('IA não concluiu ('.$response->stopReason.').');
        }
        $text = '';
        foreach ($response->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            }
        }
        $data = json_decode($text, true);

        return is_array($data) ? $data : throw new \RuntimeException('Resposta da IA em formato inválido.');
    }

    /** Normaliza dados da nota lidos pela IA. */
    public static function normalizeReceipt(array $d): array
    {
        return [
            'issuer_cnpj' => Text::digits($d['issuer_cnpj'] ?? '') ?: null,
            'issuer_name' => ($d['issuer_name'] ?? '') ?: null,
            'nf_number' => ($d['nf_number'] ?? '') ?: null,
            'nf_key' => Text::digits($d['nf_key'] ?? '') ?: null,
            'purchase_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($d['purchase_date'] ?? '')) ? $d['purchase_date'] : null,
            'total' => isset($d['total']) && $d['total'] > 0 ? round((float) $d['total'], 2) : null,
            'items' => array_values(array_filter(array_map(fn ($i) => [
                'description' => trim((string) ($i['description'] ?? '')),
                'quantity' => (float) ($i['quantity'] ?? 1) ?: 1,
                'unit_price' => round((float) ($i['unit_price'] ?? 0), 2),
                'total' => round((float) ($i['total'] ?? 0), 2),
            ], (array) ($d['items'] ?? [])), fn ($i) => $i['description'] !== '')),
        ];
    }
}
