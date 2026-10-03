<?php

namespace App\Modules\Messaging\Http\Controllers;

use App\Core\Audit\AuditLogger;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessagingChannel;
use App\Modules\Messaging\Providers\MessagingException;
use App\Modules\Messaging\Providers\UnofficialWhatsAppProvider;
use App\Modules\Messaging\Services\AppointmentNotifier;
use App\Modules\Messaging\Services\MessageService;
use App\Modules\Platform\Models\Company;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Configuração do WhatsApp e das mensagens automáticas (integracao.gerenciar). */
class ChannelWebController extends Controller
{
    public function __construct(private readonly TenantContext $context, private readonly AuditLogger $audit) {}

    public function index(AppointmentNotifier $notifier): View
    {
        $company = Company::query()->findOrFail($this->context->companyId());
        $channel = MessagingChannel::query()->latest()->first();

        return view('messaging.settings', [
            'company' => $company, 'channel' => $channel, 'reminderHours' => $notifier->reminderHours($company),
            'stats' => Message::query()->where('direction', 'out')->where('created_at', '>=', now()->subDays(30))
                ->selectRaw('status, COUNT(*) AS qty')->groupBy('status')->pluck('qty', 'status'),
            'recent' => Message::query()->with('patient:id,name,social_name')->where('direction', 'out')->latest()->limit(25)->get(),
        ]);
    }

    public function saveChannel(Request $request): RedirectResponse
    {
        $unofficial = in_array($request->input('provider'), MessagingChannel::UNOFFICIAL, true);
        $data = $request->validate([
            'provider' => ['required', Rule::in(array_keys(MessagingChannel::PROVIDERS))],
            'mode' => ['nullable', 'required_if:provider,meta', Rule::in(array_keys(MessagingChannel::MODES))],
            'zapi_instance_id' => ['nullable', 'required_if:provider,zapi', 'regex:/^[A-Za-z0-9]{8,64}$/'],
            'zapi_token' => ['nullable', 'regex:/^[A-Za-z0-9]{8,128}$/'], 'zapi_client_token' => ['nullable', 'string', 'max:200'],
            'evolution_url' => ['nullable', 'required_if:provider,evolution', 'url:https', 'max:200'],
            'evolution_instance' => ['nullable', 'required_if:provider,evolution', 'regex:/^[A-Za-z0-9_.\-]{1,100}$/'],
            'evolution_api_key' => ['nullable', 'string', 'max:200'],
            'accept_risk' => Rule::when($unofficial, ['accepted']),
            'name' => ['required', 'string', 'max:80'],
            'phone_number_id' => ['nullable', 'required_if:provider,meta', 'regex:/^\d{5,40}$/'],
            'waba_id' => ['nullable', 'regex:/^\d{5,40}$/'],
            'display_phone' => ['nullable', 'string', 'max:20'],
            'api_version' => ['nullable', 'required_if:provider,meta', 'regex:/^v\d{1,2}\.\d$/'],
            'access_token' => ['nullable', 'string', 'max:1000'], 'app_secret' => ['nullable', 'string', 'max:200'],
            'templates' => ['nullable', 'array'], 'templates.*.name' => ['nullable', 'regex:/^[a-z0-9_]{1,512}$/'], 'templates.*.language' => ['nullable', 'regex:/^[a-z]{2}(_[A-Z]{2})?$/'],
            'is_active' => ['nullable', 'boolean'],
        ], ['phone_number_id.regex' => 'O Phone Number ID tem só números.', 'templates.*.name.regex' => 'Nome de modelo: letras minúsculas, números e _.',
            'accept_risk.accepted' => 'Para usar um provedor NÃO OFICIAL, confirme que a clínica está ciente dos riscos.',
            'evolution_url.url' => 'Informe a URL do servidor da Evolution API com https://.'],
            ['phone_number_id' => 'Phone Number ID', 'api_version' => 'versão da API', 'zapi_instance_id' => 'ID da instância (Z-API)',
                'evolution_url' => 'URL do servidor (Evolution API)', 'evolution_instance' => 'nome da instância (Evolution API)']);

        $data['mode'] = match (true) {
            $data['provider'] === 'mock' => 'mock',
            $unofficial => 'production', // não oficial: sem ambiente de teste da Meta
            ($data['mode'] ?? 'test') === 'mock' => 'test',
            default => $data['mode'] ?? 'test',
        };

        $channel = MessagingChannel::query()->latest()->first() ?? new MessagingChannel;
        $credentials = $channel->credentials ?? [];
        foreach (['access_token', 'app_secret', 'zapi_token', 'zapi_client_token', 'evolution_api_key'] as $k) {
            if (! empty($data[$k])) {
                $credentials[$k] = $data[$k]; // segredo: vazio = mantém o atual (nunca exibido de volta)
            }
        }
        foreach (['zapi_instance_id', 'evolution_url', 'evolution_instance'] as $k) {
            if (array_key_exists($k, $data) && $data[$k] !== null) {
                $credentials[$k] = rtrim(trim($data[$k]), '/');
            }
        }
        $providerChanged = $channel->provider !== $data['provider'];

        $channel->fill([
            // Campos da Meta ficam ocultos (e não enviados) para os outros provedores: mantém o que havia.
            'provider' => $data['provider'], 'mode' => $data['mode'], 'name' => $data['name'],
            'phone_number_id' => array_key_exists('phone_number_id', $data) ? $data['phone_number_id'] : $channel->phone_number_id,
            'waba_id' => array_key_exists('waba_id', $data) ? $data['waba_id'] : $channel->waba_id, 'display_phone' => $data['display_phone'] ?? null,
            'api_version' => $data['api_version'] ?? $channel->api_version ?? 'v21.0',
            'credentials' => $credentials, 'is_active' => $request->boolean('is_active'),
            'templates' => isset($data['templates']) ? array_filter($data['templates'], fn ($t) => ! empty($t['name'])) : $channel->templates,
        ]);
        // Aceite do risco do provedor não oficial: registrado (quem e quando) a cada escolha de provedor.
        $riskAccepted = $unofficial && ($providerChanged || ! $channel->risk_accepted_at);
        if ($riskAccepted) {
            $channel->forceFill(['risk_accepted_at' => now(), 'risk_accepted_by' => $request->user()->id]);
        } elseif (! $unofficial) {
            $channel->forceFill(['risk_accepted_at' => null, 'risk_accepted_by' => null]);
        }
        if (! $channel->verify_token) {
            $channel->verify_token = Str::random(40);
        }
        $channel->save();
        if ($riskAccepted) {
            $this->audit->record('messaging.unofficial_risk_accepted', $channel, metadata: ['provider' => $data['provider']]);
        }

        if ($data['provider'] === 'zapi' && empty($credentials['zapi_token'])) {
            return back()->with('warning', 'Canal salvo, mas falta o token da instância Z-API — nada será enviado.');
        }
        if ($data['provider'] === 'evolution' && empty($credentials['evolution_api_key'])) {
            return back()->with('warning', 'Canal salvo, mas falta a API key da Evolution API — nada será enviado.');
        }
        if ($unofficial) {
            return back()->with('success', 'WhatsApp NÃO OFICIAL configurado. Cadastre a URL do webhook no painel do serviço e use "Verificar conexão".');
        }

        if ($data['provider'] === 'meta' && (empty($credentials['access_token']) || empty($credentials['app_secret']))) {
            return back()->with('warning', 'Canal salvo, mas faltam o token de acesso e/ou o App Secret — sem eles nada é enviado e o webhook é recusado.');
        }

        return back()->with('success', 'WhatsApp configurado.');
    }

    /** Provedor não oficial: o WhatsApp está conectado (QR Code lido, celular online)? */
    public function check(MessageService $messages): RedirectResponse
    {
        $channel = MessagingChannel::query()->latest()->firstOrFail();
        $provider = $messages->provider($channel);
        if (! $provider instanceof UnofficialWhatsAppProvider) {
            return back()->with('error', 'A verificação de conexão é para provedores não oficiais. Na API oficial, envie uma mensagem de teste.');
        }

        try {
            $status = $provider->connectionStatus($channel);
        } catch (MessagingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with($status['ok'] ? 'success' : 'error', $status['detail']);
    }

    public function rotate(): RedirectResponse
    {
        $channel = MessagingChannel::query()->latest()->firstOrFail();
        $channel->forceFill(['verify_token' => Str::random(40)])->save();
        $this->audit->record('messaging.verify_token_rotated', $channel);

        return back()->with('success', 'Novo token de verificação gerado. Atualize-o no painel da Meta.');
    }

    public function saveAutomation(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'reminder_hours' => ['nullable', 'string', 'max:40', 'regex:/^\s*\d{1,3}(\s*,\s*\d{1,3})*\s*$/'],
            'cancel_min_hours' => ['required', 'integer', 'between:0,168'],
        ], ['reminder_hours.regex' => 'Informe as horas separadas por vírgula (ex.: 24, 2).']);

        $hours = collect(explode(',', (string) ($data['reminder_hours'] ?? '')))->map(fn ($h) => (int) trim($h))->filter(fn ($h) => $h >= 1 && $h <= 168)->unique()->sortDesc()->values()->all();
        $company = Company::query()->findOrFail($this->context->companyId());
        $settings = $company->settings ?? [];
        $settings['messaging'] = array_merge($settings['messaging'] ?? [], [
            'reminders_enabled' => $request->boolean('reminders_enabled'), 'reminder_hours' => $hours,
            'on_booking' => $request->boolean('on_booking'), 'on_cancel' => $request->boolean('on_cancel'),
            'on_reschedule' => $request->boolean('on_reschedule'), 'on_no_show' => $request->boolean('on_no_show'),
            'whatsapp_enabled' => $request->boolean('whatsapp_enabled'), 'email_enabled' => $request->boolean('email_enabled'),
            'require_consent' => $request->boolean('require_consent'), 'cancel_min_hours' => (int) $data['cancel_min_hours'],
        ]);
        $company->update(['settings' => $settings]);

        return back()->with('success', 'Mensagens automáticas atualizadas.');
    }
}
