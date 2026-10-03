<?php

namespace App\Modules\Ai\Services\Media;

use App\Modules\Ai\Models\AiConfig;
use App\Modules\Ai\Models\AiRequest;
use App\Modules\Ai\Providers\AiProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Transcrição de áudio (fala → texto) pela OpenAI (POST {base}/audio/transcriptions), em português.
 * O Claude não recebe áudio; por isso a transcrição usa a OpenAI mesmo quando a conversa é com o Claude.
 * Modo MOCK: texto fixo identificado.
 */
class Transcriber
{
    public function transcribe(AiConfig $config, string $bytes, string $ext): string
    {
        if ($config->provider === 'mock') {
            AiRequest::create(['provider' => 'mock', 'model' => 'mock-transcricao', 'stop_reason' => 'transcription']);

            return '[MOCK] Transcrição simulada do áudio.';
        }

        $key = $config->transcriptionKey();
        if (! $key) {
            throw new AiProviderException('Sem chave da OpenAI para transcrever áudio.');
        }
        $model = (string) $config->setting('transcription_model', 'whisper-1');
        $started = microtime(true);

        try {
            $r = Http::withToken($key)->acceptJson()->timeout(120)->asMultipart()
                ->attach('file', $bytes, 'audio.'.$ext)
                ->post(rtrim((string) config('services.openai.base_url'), '/').'/audio/transcriptions', ['model' => $model, 'language' => 'pt', 'response_format' => 'json']);
        } catch (ConnectionException $e) {
            throw new AiProviderException('Sem conexão com a OpenAI (transcrição).', 0, $e);
        }

        $error = $r->successful() ? null : mb_substr((string) ($r->json('error.message') ?? 'HTTP '.$r->status()), 0, 500);
        AiRequest::create(['provider' => 'openai', 'model' => $model, 'stop_reason' => 'transcription', 'latency_ms' => (int) ((microtime(true) - $started) * 1000), 'error' => $error]);
        if ($error) {
            throw new AiProviderException('OpenAI recusou a transcrição: '.$error);
        }

        return trim((string) $r->json('text'));
    }
}
