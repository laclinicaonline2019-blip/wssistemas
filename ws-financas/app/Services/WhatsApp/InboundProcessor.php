<?php

namespace App\Services\WhatsApp;

use App\Core\Audit\AuditLogger;
use App\Core\Tenancy\TenantContext;
use App\Models\Account;
use App\Models\Membership;
use App\Models\UsageCounter;
use App\Models\WhatsappContact;
use App\Models\WhatsappMessage;
use App\Services\Assistant\Assistant;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Processa uma mensagem recebida: identifica o cliente pelo telefone autorizado, evita processar duas vezes
 * (wamid único), respeita plano/limites/assinatura, chama o assistente e responde.
 */
class InboundProcessor
{
    public function __construct(private readonly WhatsAppClient $wa, private readonly Assistant $assistant, private readonly AuditLogger $audit, private readonly TenantContext $ctx) {}

    private ?WhatsappMessage $current = null;

    private const STATUS_RANK = ['sent' => 1, 'delivered' => 2, 'read' => 3];

    public function process(array $msg): ?string
    {
        $t0 = microtime(true);
        $this->current = null;
        $out = $this->handle($msg);
        $log = $this->current;
        WebhookDiagnostics::info('mensagem', ['mensagem' => WebhookDiagnostics::ref($msg['wamid'] ?? null), 'tipo' => $msg['type'] ?? null,
            'resultado' => $log?->status ?? 'duplicada', 'intencao' => $log?->intent, 'cliente' => $log?->account_id, 'lancamento' => (bool) $log?->entry_id,
            'ia' => (bool) $log?->used_ai, 'ms' => (int) round((microtime(true) - $t0) * 1000)]);

        return $out;
    }

    /** Status das mensagens enviadas (Meta → sent/delivered/read/failed): atualiza a resposta registrada e guarda o motivo de falha. */
    public function status(array $st): void
    {
        $row = $st['wamid'] !== '' ? WhatsappMessage::query()->where('wamid', $st['wamid'])->where('direction', 'out')->first() : null;
        $new = $st['status'];
        $ctx = ['mensagem' => WebhookDiagnostics::ref($st['wamid']), 'status' => $new, 'registrada' => (bool) $row, 'erros' => $st['errors'] ?: null];
        if ($new === 'failed') {
            WebhookDiagnostics::warning('status_meta', $ctx);
            Log::warning('WhatsApp: a Meta informou falha na entrega', ['erros' => $st['errors']]);
        } else {
            WebhookDiagnostics::info('status_meta', $ctx);
        }
        if (! $row || ! in_array($new, ['sent', 'delivered', 'read', 'failed'], true)) {
            return;
        }
        // não regride (delivered depois de read) e "failed" sempre prevalece
        if ($new === 'failed' || (self::STATUS_RANK[$new] ?? 0) > (self::STATUS_RANK[$row->status] ?? 0)) {
            $row->update(['status' => $new]);
        }
    }

    private function handle(array $msg): ?string
    {
        $hash = WhatsappContact::hashPhone($msg['from']);

        // Idempotência: a Meta reentrega webhooks; o wamid é único na tabela. Só a DUPLICIDADE é silenciosa —
        // qualquer outro erro de banco vai para o diagnóstico e para o log (antes era engolido sem rastro).
        try {
            $log = WhatsappMessage::query()->create(['direction' => 'in', 'wamid' => $msg['wamid'] ?: null, 'phone_hash' => $hash, 'body' => $msg['text'], 'status' => 'received']);
        } catch (QueryException $e) {
            if (! empty($msg['wamid']) && WhatsappMessage::query()->where('wamid', $msg['wamid'])->exists()) {
                WebhookDiagnostics::info('duplicada', ['mensagem' => WebhookDiagnostics::ref($msg['wamid'])]);

                return null;
            }
            WebhookDiagnostics::error('erro_banco', WebhookDiagnostics::exception($e) + ['tabela' => 'whatsapp_messages', 'mensagem' => WebhookDiagnostics::ref($msg['wamid'] ?? null)]);
            throw $e;
        }
        $this->current = $log;

        // Limite por número (proteção contra rajadas/abuso e custo): excedente é registrado e ignorado, sem resposta.
        $rateKey = 'wsf:wa:rate:'.$hash;
        if (RateLimiter::tooManyAttempts($rateKey, (int) config('services.whatsapp.rate_per_5min', 30))) {
            $log->update(['status' => 'rate_limited']);

            return null;
        }
        RateLimiter::hit($rateKey, 300);

        $contact = WhatsappContact::query()->withoutGlobalScopes()->where('phone_hash', $hash)->where('active', true)->first();
        if (! $contact) {
            $log->update(['status' => 'unknown_number']);
            if (! Cache::add('wsf:wa:unknown:'.$hash, 1, now()->addDay())) {
                return null;   // número desconhecido: responde no máximo uma vez por dia
            }

            return $this->reply($msg['from'], null, "Olá! Este é o assistente do *WS Finanças em Dia*. Seu número ainda não está autorizado.\nPeça ao administrador da sua conta para cadastrá-lo em Configurações → WhatsApp.");
        }

        // Validação do número: só depois que o próprio celular envia o código mostrado no painel
        if (! $contact->verified()) {
            $log->update(['account_id' => $contact->account_id, 'status' => 'verification']);
            $result = $contact->checkCode((string) $msg['text']);
            if ($result === 'ok') {
                $this->ctx->runFor($contact->account_id, fn () => $this->audit->log('whatsapp.validar', $contact, ['final' => $contact->phone_last4], accountId: $contact->account_id, channel: 'whatsapp'));

                return $this->reply($msg['from'], $contact->account_id, "✅ Número validado! Agora você já pode lançar e consultar pelo WhatsApp.\n\n".$this->assistant->help());
            }

            return $this->reply($msg['from'], $contact->account_id, match ($result) {
                'locked' => '🔒 Muitas tentativas com código errado. Peça ao administrador para gerar um novo código no painel (Configurações → WhatsApp).',
                'expired' => '⌛ O código de validação expirou. Peça ao administrador para gerar um novo no painel (Configurações → WhatsApp).',
                default => "🔐 Este número está aguardando validação.\nEnvie aqui o código de 6 dígitos mostrado no painel do WS Finanças (Configurações → WhatsApp).",
            });
        }

        $account = Account::query()->with('subscription.plan')->find($contact->account_id);
        $log->update(['account_id' => $account?->id]);
        $this->wa->markRead($msg['wamid']);
        $plan = $account?->plan();

        if (! $account || ! $plan || ! $plan->has('whatsapp')) {
            $log->update(['status' => 'plan_blocked']);

            return $this->reply($msg['from'], $account?->id, 'O plano atual não inclui o assistente no WhatsApp. Faça upgrade no painel, em Assinatura.');
        }
        $usage = UsageCounter::current($account->id);
        if ($plan->whatsapp_messages_month > 0 && $usage->whatsapp_in >= $plan->whatsapp_messages_month) {
            $log->update(['status' => 'limit']);

            return $this->reply($msg['from'], $account->id, 'O limite mensal de mensagens do seu plano foi atingido. Use o painel ou faça upgrade em Assinatura.');
        }
        UsageCounter::bump($account->id, ['whatsapp_in' => 1]);

        if ($msg['text'] === null || trim($msg['text']) === '') {
            $log->update(['status' => 'unsupported']);

            return $this->reply($msg['from'], $account->id, 'Por enquanto entendo só mensagens de texto. Ex.: paguei 350 de energia');
        }

        [$role, $companyIds, $userId] = $this->permissions($contact);
        // data da mensagem (a Meta pode reentregar com atraso): "ontem", "hoje" e a data do lançamento seguem o envio
        $sentAt = ! empty($msg['timestamp']) ? CarbonImmutable::createFromTimestamp((int) $msg['timestamp'])->setTimezone(config('app.timezone')) : null;
        $baseDate = $sentAt && $sentAt->toDateString() !== today()->toDateString() && $sentAt->gte(now()->subDays(7)) && $sentAt->lte(now()) ? $sentAt->toDateString() : null;

        $result = $this->assistant->handle([
            'account_id' => $account->id, 'key' => $hash, 'channel' => 'whatsapp', 'role' => $role, 'company_ids' => $companyIds,
            'default_company_id' => $contact->default_company_id, 'can_write' => $account->canWrite() && $contact->can_write, 'user_id' => $userId,
            'base_date' => $baseDate,
        ], $msg['text']);

        $log->update(['status' => 'processed', 'intent' => $result['intent'], 'entry_id' => $result['entry_ids'][0] ?? null, 'used_ai' => $result['used_ai']]);

        return $this->reply($msg['from'], $account->id, $result['reply']);
    }

    /** Número ligado a um usuário herda o papel e as empresas dele; número avulso lança em todas (financeiro). */
    private function permissions(WhatsappContact $c): array
    {
        if ($c->user_id) {
            $m = Membership::query()->withoutGlobalScopes()->where('account_id', $c->account_id)->where('user_id', $c->user_id)->where('status', 'active')->first();
            if ($m) {
                $ids = app(TenantContext::class)->runFor($c->account_id, fn () => $m->allowedCompanyIds());

                return [$m->role, $ids, $c->user_id];
            }

            return ['consulta', [], $c->user_id];
        }

        return [$c->can_write ? 'financeiro' : 'consulta', null, null];
    }

    private function reply(string $to, ?string $accountId, string $text): ?string
    {
        $id = $this->wa->sendText($to, $text);
        $r = $this->wa->lastResult ?? [];
        $ctx = ['resultado' => $id ? 'aceita' : 'falhou', 'modo' => $this->wa->mock() ? 'mock' : 'cloud', 'http' => $r['http'] ?? null]
            + array_filter(['codigo' => $r['codigo'] ?? null, 'subcodigo' => $r['subcodigo'] ?? null, 'titulo' => $id ? null : ($r['titulo'] ?? ($r['erro'] ?? null))]);
        $id ? WebhookDiagnostics::info('resposta', $ctx) : WebhookDiagnostics::error('resposta', $ctx);
        WhatsappMessage::query()->create(['account_id' => $accountId, 'direction' => 'out', 'wamid' => $id, 'phone_hash' => WhatsappContact::hashPhone($to), 'body' => $text, 'status' => $id ? 'sent' : 'failed']);
        if ($accountId) {
            UsageCounter::bump($accountId, ['whatsapp_out' => 1]);
        }

        return $text;
    }
}
