<?php

namespace App\Modules\Ai\Services\Media;

use App\Modules\Ai\Models\AiConfig;
use App\Modules\Ai\Models\AiMedia;
use App\Modules\Ai\Models\AiRequest;
use App\Modules\Ai\Services\AiReceptionist;

/**
 * Leitura (OCR + organização) de imagem ou PDF pela IA da clínica, com saída em JSON garantida
 * pelo schema (structured outputs no Claude / json_schema estrito na OpenAI).
 *
 * A IA só TRANSCREVE: não corrige, não completa e não interpreta. O resultado é sempre
 * "não verificado" até a conferência humana.
 */
class DocumentReader
{
    public const INSTRUCTIONS = <<<'TXT'
    Você transcreve e organiza documentos de saúde enviados por pacientes a uma clínica médica no Brasil: receitas, pedidos de exame, resultados de exame, comprovantes de pagamento (PIX, boleto, cartão), atestados, carteirinhas de convênio e documentos pessoais.

    Regras:
    - Copie exatamente o que está escrito. Não corrija, não complete e não deduza nomes de medicamentos, concentrações, doses, posologia ou exames. Se uma parte estiver ilegível ou ambígua, deixe o campo vazio ("") e cite-o em uncertain_fields.
    - Não interprete resultados, não dê opinião clínica e não avalie gravidade. Em resultado de exame, apenas transcreva o conteúdo em raw_text.
    - summary: uma frase neutra dizendo o que é o documento (ex.: "Receita com 2 medicamentos, Dr. Fulano, 10/09/2026").
    - Datas como AAAA-MM-DD quando legíveis; caso contrário, como estão escritas. Valores em reais como escritos (ex.: 150,00).
    - Se não for um documento de saúde ou financeiro, use doc_type "other"; se não der para ler, "unreadable".
    - raw_text: a transcrição completa do texto legível, na ordem em que aparece.
    TXT;

    public function __construct(private readonly AiReceptionist $receptionist) {}

    public static function schema(): array
    {
        $str = ['type' => 'string'];
        $obj = fn (array $props) => ['type' => 'object', 'properties' => $props, 'required' => array_keys($props), 'additionalProperties' => false];

        return $obj([
            'doc_type' => ['type' => 'string', 'enum' => array_keys(AiMedia::DOC_TYPES)],
            'summary' => $str, 'patient_name' => $str, 'document_date' => $str, 'professional_name' => $str, 'professional_registry' => $str,
            'medications' => ['type' => 'array', 'items' => $obj(['name' => $str, 'concentration' => $str, 'form' => $str, 'dosage_instructions' => $str, 'quantity' => $str])],
            'exams' => ['type' => 'array', 'items' => $obj(['name' => $str, 'code' => $str])],
            'payment' => $obj(['amount' => $str, 'paid_at' => $str, 'payer_name' => $str, 'receiver_name' => $str, 'method' => $str, 'transaction_id' => $str]),
            'legibility' => ['type' => 'string', 'enum' => ['good', 'partial', 'poor']],
            'uncertain_fields' => ['type' => 'array', 'items' => $str],
            'raw_text' => $str,
        ]);
    }

    /** @return array<string, mixed> dados extraídos (validados contra o schema básico) */
    public function read(AiConfig $config, AiMedia $media, string $bytes, ?string $sessionId = null): array
    {
        $data = $this->receptionist->provider($config)->extractDocument($config, self::INSTRUCTIONS, $bytes, (string) $media->mime, self::schema(),
            fn (array $usage) => AiRequest::create(['session_id' => $sessionId] + array_intersect_key($usage, array_flip(
                ['provider', 'model', 'input_tokens', 'output_tokens', 'cache_read_tokens', 'stop_reason', 'latency_ms', 'error'],
            )) + ['model' => 'desconhecido']));

        if (! isset($data['doc_type']) || ! array_key_exists($data['doc_type'], AiMedia::DOC_TYPES)) {
            $data['doc_type'] = 'unreadable';
        }
        $data['medications'] = array_values(array_filter((array) ($data['medications'] ?? []), 'is_array'));
        $data['exams'] = array_values(array_filter((array) ($data['exams'] ?? []), 'is_array'));
        $data['uncertain_fields'] = array_values(array_filter((array) ($data['uncertain_fields'] ?? []), 'is_string'));

        return $data;
    }
}
