<?php

namespace App\Services;

use App\Core\Support\Money;
use App\Models\Account;
use App\Models\Entry;
use App\Models\UsageCounter;
use App\Models\WhatsappContact;
use App\Models\WhatsappMessage;
use App\Services\WhatsApp\WhatsAppClient;

/**
 * Lembrete diário "contas que vencem hoje" — mensagem iniciada pela empresa: pela regra da Meta só pode ser
 * enviada com MODELO APROVADO (WHATSAPP_REMINDER_TEMPLATE) e para quem aceitou receber (opt-in).
 * Sem modelo configurado, nada é enviado.
 */
class Reminders
{
    public function __construct(private readonly WhatsAppClient $wa) {}

    public function send(): int
    {
        $template = (string) config('services.whatsapp.reminder_template');
        if ($template === '') {
            return 0;
        }
        $sent = 0;
        foreach (WhatsappContact::query()->where('active', true)->where('reminders_opt_in', true)->get() as $c) {
            $account = Account::query()->with('subscription.plan')->find($c->account_id);
            if (! $account || ! $account->canWrite() || ! $account->feature('whatsapp')) {
                continue;
            }
            $q = Entry::query()->where('account_id', $c->account_id)->where('type', 'despesa')->where('paid', false)->where('due_date', today()->toDateString());
            if ($c->default_company_id) {
                $q->where('company_id', $c->default_company_id);
            }
            $count = $q->count();
            if ($count === 0) {
                continue;
            }
            $total = (int) $q->sum('amount_cents');
            $id = $this->wa->sendTemplate($c->phone, $template, (string) config('services.whatsapp.reminder_template_lang'), [(string) $count, Money::brl($total)]);
            WhatsappMessage::query()->create(['account_id' => $c->account_id, 'direction' => 'out', 'wamid' => $id, 'phone_hash' => $c->phone_hash,
                'body' => "[lembrete] {$count} conta(s), ".Money::brl($total), 'intent' => 'lembrete', 'status' => $id ? 'sent' : 'failed']);
            UsageCounter::bump($c->account_id, ['whatsapp_out' => 1]);
            $sent++;
        }

        return $sent;
    }
}
