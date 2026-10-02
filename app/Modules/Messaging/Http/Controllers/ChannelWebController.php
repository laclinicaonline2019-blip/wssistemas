<?php

namespace App\Modules\Messaging\Http\Controllers;

use App\Core\Audit\AuditLogger;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessagingChannel;
use App\Modules\Messaging\Services\AppointmentNotifier;
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
        $data = $request->validate([
            'provider' => ['required', Rule::in(array_keys(MessagingChannel::PROVIDERS))],
            'mode' => ['required', Rule::in(array_keys(MessagingChannel::MODES))],
            'name' => ['required', 'string', 'max:80'],
            'phone_number_id' => ['nullable', 'required_if:provider,meta', 'regex:/^\d{5,40}$/'],
            'waba_id' => ['nullable', 'regex:/^\d{5,40}$/'],
            'display_phone' => ['nullable', 'string', 'max:20'],
            'api_version' => ['required', 'regex:/^v\d{1,2}\.\d$/'],
            'access_token' => ['nullable', 'string', 'max:1000'], 'app_secret' => ['nullable', 'string', 'max:200'],
            'templates' => ['nullable', 'array'], 'templates.*.name' => ['nullable', 'regex:/^[a-z0-9_]{1,512}$/'], 'templates.*.language' => ['nullable', 'regex:/^[a-z]{2}(_[A-Z]{2})?$/'],
            'is_active' => ['nullable', 'boolean'],
        ], ['phone_number_id.regex' => 'O Phone Number ID tem só números.', 'templates.*.name.regex' => 'Nome de modelo: letras minúsculas, números e _.'],
            ['phone_number_id' => 'Phone Number ID', 'api_version' => 'versão da API']);

        if ($data['provider'] === 'mock') {
            $data['mode'] = 'mock';
        } elseif ($data['mode'] === 'mock') {
            $data['mode'] = 'test';
        }

        $channel = MessagingChannel::query()->latest()->first() ?? new MessagingChannel;
        $credentials = $channel->credentials ?? [];
        foreach (['access_token', 'app_secret'] as $k) {
            if (! empty($data[$k])) {
                $credentials[$k] = $data[$k]; // vazio = mantém o atual (nunca exibido de volta)
            }
        }

        $channel->fill([
            'provider' => $data['provider'], 'mode' => $data['mode'], 'name' => $data['name'], 'phone_number_id' => $data['phone_number_id'] ?? null,
            'waba_id' => $data['waba_id'] ?? null, 'display_phone' => $data['display_phone'] ?? null, 'api_version' => $data['api_version'],
            'credentials' => $credentials, 'templates' => array_filter($data['templates'] ?? [], fn ($t) => ! empty($t['name'])), 'is_active' => $request->boolean('is_active'),
        ]);
        if (! $channel->verify_token) {
            $channel->verify_token = Str::random(40);
        }
        $channel->save();

        if ($data['provider'] === 'meta' && (empty($credentials['access_token']) || empty($credentials['app_secret']))) {
            return back()->with('warning', 'Canal salvo, mas faltam o token de acesso e/ou o App Secret — sem eles nada é enviado e o webhook é recusado.');
        }

        return back()->with('success', 'WhatsApp configurado.');
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
