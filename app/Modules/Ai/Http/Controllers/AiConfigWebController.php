<?php

namespace App\Modules\Ai\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Ai\Models\AiConfig;
use App\Modules\Ai\Models\AiRequest;
use App\Modules\Ai\Models\AiSession;
use App\Modules\Ai\Models\AiToolCall;
use App\Modules\Ai\Services\AiReceptionist;
use App\Modules\Messaging\Models\MessagingChannel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

/** Atendimento por IA: provedor (Claude, ChatGPT ou MOCK), chave própria, regras e uso (ia.configurar). */
class AiConfigWebController extends Controller
{
    public function index(): View
    {
        $since = now()->subDays(30);
        $recent = AiToolCall::query()->latest('created_at')->limit(15)->get();

        return view('ai.settings', [
            'config' => AiConfig::query()->first() ?? new AiConfig,
            'channel' => MessagingChannel::query()->latest()->first(),
            'platformKeys' => ['claude' => (bool) config('services.anthropic.key'), 'openai' => (bool) config('services.openai.key')],
            'usage' => AiRequest::query()->where('created_at', '>=', $since)
                ->selectRaw('COUNT(*) AS calls, COALESCE(SUM(input_tokens), 0) AS input_tokens, COALESCE(SUM(output_tokens), 0) AS output_tokens, COALESCE(SUM(cache_read_tokens), 0) AS cache_read_tokens, SUM(CASE WHEN error IS NULL THEN 0 ELSE 1 END) AS errors')
                ->first(),
            'sessions' => AiSession::query()->where('created_at', '>=', $since)->selectRaw('status, COUNT(*) AS qty')->groupBy('status')->pluck('qty', 'status'),
            'booked' => AiToolCall::query()->where('created_at', '>=', $since)->where('tool', 'confirm_appointment')->where('is_error', false)->count(),
            'recentCalls' => $recent,
            'threadOf' => AiSession::query()->whereIn('id', $recent->pluck('session_id')->unique())->pluck('thread_id', 'id'),
        ]);
    }

    public function save(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'provider' => ['required', Rule::in(array_keys(AiConfig::PROVIDERS))],
            'model' => ['nullable', 'required_if:provider,openai', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:\-\/]{1,79}$/'],
            'api_key' => ['nullable', 'string', 'min:20', 'max:300'],
            'remove_key' => ['nullable', 'boolean'],
            'assistant_name' => ['required', 'string', 'max:60'],
            'instructions' => ['nullable', 'string', 'max:8000'],
            'effort' => ['required', Rule::in(['low', 'medium', 'high'])],
            'max_replies_per_hour' => ['required', 'integer', 'between:5,200'],
            'max_daily_replies' => ['required', 'integer', 'between:10,20000'],
            'transcription_model' => ['nullable', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:\-]{1,79}$/'],
            'transcription_api_key' => ['nullable', 'string', 'min:20', 'max:300'],
        ], ['model.required_if' => 'Informe o modelo da OpenAI (ex.: o nome exato do modelo contratado na sua conta).', 'model.regex' => 'Nome de modelo inválido.'],
            ['model' => 'modelo', 'api_key' => 'chave da API', 'assistant_name' => 'nome da assistente', 'instructions' => 'informações da clínica']);

        $config = AiConfig::query()->first() ?? new AiConfig;
        $providerChanged = $config->exists && $config->provider !== $data['provider'];
        $config->fill([
            'provider' => $data['provider'], 'model' => $data['model'] ?: null, 'assistant_name' => $data['assistant_name'],
            'instructions' => $data['instructions'] ?? null, 'is_active' => $request->boolean('is_active'),
            'settings' => array_merge($config->settings ?? [], [
                'whatsapp_enabled' => $request->boolean('whatsapp_enabled'), 'allow_booking' => $request->boolean('allow_booking'),
                'allow_cancel' => $request->boolean('allow_cancel'), 'prepayment' => $request->boolean('prepayment'),
                'effort' => $data['effort'], 'max_replies_per_hour' => (int) $data['max_replies_per_hour'], 'max_daily_replies' => (int) $data['max_daily_replies'],
                'media_enabled' => $request->boolean('media_enabled'), 'audio_enabled' => $request->boolean('audio_enabled'),
                'transcription_model' => ($data['transcription_model'] ?? null) ?: 'whisper-1',
            ]),
        ]);
        // Chave: vazio mantém a atual (nunca é exibida de volta); trocar de provedor descarta a chave anterior.
        if (! empty($data['api_key'])) {
            $config->api_key = trim($data['api_key']);
        } elseif ($request->boolean('remove_key') || $providerChanged) {
            $config->api_key = null;
        }
        if (! empty($data['transcription_api_key'])) {
            $config->transcription_api_key = trim($data['transcription_api_key']);
        } elseif ($request->boolean('remove_transcription_key')) {
            $config->transcription_api_key = null;
        }
        $config->save();

        if ($config->is_active && $config->provider !== 'mock' && ! $config->apiKey()) {
            return back()->with('warning', 'Configuração salva, mas sem chave da API (nem da clínica, nem da plataforma): a IA vai passar as conversas para a equipe.');
        }

        return back()->with('success', 'Atendimento por IA atualizado.');
    }

    public function test(AiReceptionist $receptionist): RedirectResponse
    {
        $config = AiConfig::query()->firstOrFail();
        try {
            $text = $receptionist->testConnection($config);
        } catch (Throwable $e) {
            return back()->with('error', 'Falha no teste: '.mb_substr($e->getMessage(), 0, 250));
        }

        return back()->with('success', 'Conexão OK com '.AiConfig::PROVIDERS[$config->provider].' ('.$config->modelName().'). Resposta: "'.mb_substr($text, 0, 120).'"');
    }
}
