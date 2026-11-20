<?php

namespace App\Services\Reports;

use App\Core\Support\Money;
use App\Core\Tenancy\TenantContext;
use App\Models\Account;
use App\Models\Company;
use App\Models\Membership;
use App\Models\ReportPreference;
use App\Models\ReportRun;
use App\Models\UsageCounter;
use App\Models\User;
use App\Models\WhatsappContact;
use App\Models\WhatsappMessage;
use App\Services\MailDelivery;
use App\Services\WhatsApp\WhatsAppClient;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * Envio dos relatórios automáticos — segunda-feira (semana anterior) e dia 1º (mês anterior), um por entidade.
 *
 *  - só para quem optou (preferências do cliente) e para as entidades escolhidas;
 *  - idempotente: cada envio (empresa + tipo + período + canal) é reservado numa linha única antes de sair —
 *    rodar de novo (cron repetido, reprocessamento) não manda duas vezes;
 *  - em lotes (WSF_RELATORIOS_LOTE por execução); o que sobrar sai na execução seguinte;
 *  - WhatsApp: dentro da janela de 24 h vai o texto completo; fora dela, só com MODELO APROVADO
 *    (WHATSAPP_RELATORIO_TEMPLATE), que convida a responder RELATÓRIO. Sem modelo, o canal é registrado como ignorado.
 */
class ReportDispatcher
{
    public function __construct(private readonly PeriodReport $reports, private readonly WhatsAppClient $wa, private readonly MailDelivery $mail, private readonly TenantContext $ctx) {}

    /** @return array{enviados:int, ignorados:int, falhas:int, pendentes:int} */
    public function run(string $kind, ?CarbonImmutable $today = null, ?int $limit = null): array
    {
        $today ??= CarbonImmutable::today();
        $limit ??= max(1, (int) config('wsf.reports.batch', 200));
        [$from, $to, $key] = PeriodReport::lastPeriod($kind, $today);
        $stats = ['enviados' => 0, 'ignorados' => 0, 'falhas' => 0, 'pendentes' => 0];
        $column = $kind === 'mensal' ? 'monthly' : 'weekly';

        $prefs = ReportPreference::query()->withoutGlobalScopes()->where($column, true)
            ->where(fn ($q) => $q->where('via_whatsapp', true)->orWhere('via_email', true))->orderBy('account_id')->get();
        foreach ($prefs as $pref) {
            $account = Account::query()->with('subscription.plan')->find($pref->account_id);
            if (! $account || ! $account->canWrite()) {
                continue;   // suspenso/cancelado: sem envio
            }
            $this->ctx->runFor($account->id, function () use ($account, $pref, $kind, $from, $to, $key, &$stats, &$limit) {
                $companies = Company::query()->where('active', true)->orderBy('name')->get()
                    ->when(is_array($pref->company_ids), fn ($c) => $c->whereIn('id', $pref->company_ids));
                foreach ($companies as $company) {
                    foreach (['whatsapp' => $pref->via_whatsapp, 'email' => $pref->via_email] as $channel => $on) {
                        if (! $on) {
                            continue;
                        }
                        if ($limit <= 0) {
                            $stats['pendentes']++;

                            continue;
                        }
                        $run = $this->claim($company, $kind, $key, $channel);
                        if (! $run) {
                            continue;   // já enviado (ou em envio) neste período
                        }
                        $limit--;
                        [$status, $detail] = $this->deliver($account, $pref, $company, $channel, $kind, $from, $to);
                        $run->update(['status' => $status, 'detail' => mb_substr($detail, 0, 190)]);
                        $stats[match ($status) { 'enviado' => 'enviados', 'ignorado' => 'ignorados', default => 'falhas' }]++;
                    }
                }
            }, null, 'system');
        }

        return $stats;
    }

    /** Reserva o envio (linha única); null = já enviado/em envio. Envio que FALHOU é tentado de novo nas execuções seguintes do dia. */
    private function claim(Company $company, string $kind, string $key, string $channel): ?ReportRun
    {
        $existing = ReportRun::query()->where('company_id', $company->id)->where('kind', $kind)->where('period_key', $key)->where('channel', $channel)->first();
        if ($existing) {
            // nova tentativa só para falha; a troca de situação é atômica (duas execuções não reenviam juntas)
            $retry = $existing->status === 'falhou'
                && ReportRun::query()->whereKey($existing->id)->where('status', 'falhou')->update(['status' => 'enviando', 'updated_at' => now()]) === 1;

            return $retry ? $existing->fresh() : null;
        }
        try {
            return ReportRun::query()->create(['company_id' => $company->id, 'kind' => $kind, 'period_key' => $key, 'channel' => $channel, 'status' => 'enviando']);
        } catch (QueryException) {
            return null;   // outra execução reservou no mesmo instante
        }
    }

    /** @return array{0:string, 1:string} situação e detalhe */
    private function deliver(Account $account, ReportPreference $pref, Company $company, string $channel, string $kind, CarbonImmutable $from, CarbonImmutable $to): array
    {
        try {
            $facts = $this->reports->facts($company, $kind, $from, $to);
            $text = $this->reports->render($facts, $this->reports->polish($account->id, $facts, $this->reports->insights($facts)));
            $subject = 'Relatório '.($kind === 'mensal' ? 'mensal' : 'semanal').' — '.$company->name.' — '.PeriodReport::label($kind, $from, $to);

            return $channel === 'email' ? $this->email($pref, $subject, $text) : $this->whatsapp($account, $pref, $company, $facts, $text, $kind);
        } catch (\Throwable $e) {
            Log::warning('Relatório automático não enviado', ['empresa' => $company->id, 'canal' => $channel, 'erro' => class_basename($e)]);

            return ['falhou', 'erro interno ('.class_basename($e).')'];
        }
    }

    private function email(ReportPreference $pref, string $subject, string $text): array
    {
        $to = $pref->email ?: $this->adminEmail($pref->account_id);
        if (! $to) {
            return ['ignorado', 'sem e-mail de destino'];
        }
        $r = $this->mail->sendReport($to, $subject, $text);

        return $r['ok'] ? ['enviado', 'e-mail'] : ['falhou', (string) ($r['dica'] ?? 'falha no envio')];
    }

    private function whatsapp(Account $account, ReportPreference $pref, Company $company, array $facts, string $text, string $kind): array
    {
        if (! $account->feature('whatsapp')) {
            return ['ignorado', 'plano sem WhatsApp'];
        }
        $contact = $this->contact($pref);
        if (! $contact) {
            return ['ignorado', 'nenhum número validado para receber'];
        }
        // janela de 24 h aberta (a pessoa escreveu nas últimas 24 h): texto livre, sem custo de modelo
        $open = WhatsappMessage::query()->withoutGlobalScopes()->where('phone_hash', $contact->phone_hash)->where('direction', 'in')->where('created_at', '>=', now()->subHours(23))->exists();
        if ($open) {
            $id = $this->wa->sendText($contact->phone, $text);
            $body = $text;
        } else {
            $template = (string) config('services.whatsapp.report_template');
            if ($template === '') {
                return ['ignorado', 'fora da janela de 24 h e sem modelo aprovado (WHATSAPP_RELATORIO_TEMPLATE)'];
            }
            $params = [$kind === 'mensal' ? 'mensal' : 'semanal', $company->name, Money::brl($facts['despesas']), Money::brl($facts['saldo'])];
            $id = $this->wa->sendTemplate($contact->phone, $template, (string) config('services.whatsapp.report_template_lang', 'pt_BR'), $params);
            $body = '[relatório '.$kind.'] '.$company->name;
        }
        WhatsappMessage::query()->create(['account_id' => $account->id, 'direction' => 'out', 'wamid' => $id, 'phone_hash' => $contact->phone_hash, 'body' => $body,
            'intent' => 'relatorio', 'status' => $id ? 'sent' : 'failed']);
        UsageCounter::bump($account->id, ['whatsapp_out' => 1]);

        return $id ? ['enviado', $open ? 'whatsapp (texto)' : 'whatsapp (modelo)'] : ['falhou', 'WhatsApp recusou o envio'];
    }

    /** Número escolhido ou, se não houver, o número validado de um administrador da conta. */
    public function contact(ReportPreference $pref): ?WhatsappContact
    {
        $q = fn () => WhatsappContact::query()->where('active', true)->whereNotNull('verified_at');
        if ($pref->whatsapp_contact_id && ($c = $q()->whereKey($pref->whatsapp_contact_id)->first())) {
            return $c;
        }
        $admins = Membership::query()->where('role', 'admin')->where('status', 'active')->pluck('user_id');

        return $q()->whereIn('user_id', $admins)->orderBy('created_at')->first();
    }

    private function adminEmail(string $accountId): ?string
    {
        $userId = Membership::query()->where('role', 'admin')->where('status', 'active')->orderBy('created_at')->value('user_id');

        return $userId ? User::query()->whereKey($userId)->value('email') : (Account::query()->whereKey($accountId)->value('email') ?: null);
    }
}
