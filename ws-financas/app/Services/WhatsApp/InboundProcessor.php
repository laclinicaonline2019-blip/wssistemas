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
use Illuminate\Database\QueryException;

/**
 * Processa uma mensagem recebida: identifica o cliente pelo telefone autorizado, evita processar duas vezes
 * (wamid único), respeita plano/limites/assinatura, chama o assistente e responde.
 */
class InboundProcessor
{
    public function __construct(private readonly WhatsAppClient $wa, private readonly Assistant $assistant, private readonly AuditLogger $audit) {}

    public function process(array $msg): ?string
    {
        $hash = WhatsappContact::hashPhone($msg['from']);

        // Idempotência: a Meta reentrega webhooks; o wamid é único na tabela.
        try {
            $log = WhatsappMessage::query()->create(['direction' => 'in', 'wamid' => $msg['wamid'] ?: null, 'phone_hash' => $hash, 'body' => $msg['text'], 'status' => 'received']);
        } catch (QueryException) {
            return null;
        }

        $contact = WhatsappContact::query()->withoutGlobalScopes()->where('phone_hash', $hash)->where('active', true)->first();
        if (! $contact) {
            $log->update(['status' => 'unknown_number']);

            return $this->reply($msg['from'], null, "Olá! Este é o assistente do *WS Finanças em Dia*. Seu número ainda não está autorizado.\nPeça ao administrador da sua conta para cadastrá-lo em Configurações → WhatsApp.");
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
        $result = $this->assistant->handle([
            'account_id' => $account->id, 'key' => $hash, 'channel' => 'whatsapp', 'role' => $role, 'company_ids' => $companyIds,
            'default_company_id' => $contact->default_company_id, 'can_write' => $account->canWrite() && $contact->can_write, 'user_id' => $userId,
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
        WhatsappMessage::query()->create(['account_id' => $accountId, 'direction' => 'out', 'wamid' => $id, 'phone_hash' => WhatsappContact::hashPhone($to), 'body' => $text, 'status' => $id ? 'sent' : 'failed']);
        if ($accountId) {
            UsageCounter::bump($accountId, ['whatsapp_out' => 1]);
        }

        return $text;
    }
}
