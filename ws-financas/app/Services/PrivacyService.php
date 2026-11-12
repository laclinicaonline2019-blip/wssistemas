<?php

namespace App\Services;

use App\Core\Audit\AuditLogger;
use App\Models\Account;
use App\Models\BankAccount;
use App\Models\Company;
use App\Models\Debt;
use App\Models\Entry;
use App\Models\Membership;
use App\Models\User;
use App\Models\WhatsappContact;
use App\Models\WhatsappMessage;
use Illuminate\Support\Facades\DB;

/**
 * LGPD: portabilidade (exportação completa dos dados do cliente) e retenção/eliminação.
 *  - Cancelado → dados guardados pelo prazo contratual (WSF_RETENCAO_APOS_CANCELAMENTO) e depois apagados.
 *  - Texto das mensagens do WhatsApp anonimizado após WSF_RETENCAO_MENSAGENS dias.
 *  - Auditoria mantida pelo prazo legal (WSF_RETENCAO_AUDITORIA), depois removida.
 */
class PrivacyService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** Executa dentro do contexto do cliente (escopo garante que só os dados dele saem). */
    public function export(Account $account): array
    {
        $this->audit->log('lgpd.exportar', $account);

        return [
            'gerado_em' => now()->toIso8601String(),
            'cliente' => ['nome' => $account->name, 'tipo' => $account->person_type, 'documento' => $account->document, 'email' => $account->email, 'telefone' => $account->phone, 'criado_em' => $account->created_at?->toIso8601String()],
            'empresas' => Company::query()->get()->map(fn ($c) => ['id' => $c->id, 'nome' => $c->name, 'apelido' => $c->alias, 'documento' => $c->document]),
            'usuarios' => Membership::query()->with('user')->get()->map(fn ($m) => ['nome' => $m->user->name, 'email' => $m->user->email, 'papel' => $m->role]),
            'contas_bancarias' => BankAccount::query()->get()->map->only(['id', 'company_id', 'name', 'bank', 'opening_balance_cents']),
            'lancamentos' => Entry::query()->get()->map(fn ($e) => $e->only(['id', 'company_id', 'type', 'description', 'amount_cents', 'category', 'competence', 'paid', 'source', 'recurrence_kind', 'installment_no', 'installments_total'])
                + ['due_date' => $e->due_date?->toDateString(), 'paid_at' => $e->paid_at?->toDateString()]),
            'dividas' => Debt::query()->get()->map->only(['id', 'company_id', 'creditor', 'balance_cents', 'monthly_rate', 'min_payment_cents']),
            'whatsapp_numeros' => WhatsappContact::query()->get()->map(fn ($c) => ['rotulo' => $c->label, 'telefone' => $c->phone]),
        ];
    }

    /** Rotina diária. @return array<string,int> */
    public function runRetention(): array
    {
        $stats = ['clientes_apagados' => 0, 'mensagens_anonimizadas' => 0, 'auditoria_removida' => 0];

        foreach (Account::query()->where('status', 'canceled')->whereNotNull('purge_after')->where('purge_after', '<', now())->get() as $account) {
            $this->purge($account);
            $stats['clientes_apagados']++;
        }

        $stats['mensagens_anonimizadas'] = WhatsappMessage::query()->whereNotNull('body')
            ->where('created_at', '<', now()->subDays((int) config('wsf.lgpd.whatsapp_body_days')))->update(['body' => null]);

        $stats['auditoria_removida'] = DB::table('audit_logs')->where('created_at', '<', now()->subDays((int) config('wsf.lgpd.audit_days')))->delete();

        return $stats;
    }

    /** Eliminação definitiva de um cliente (dados financeiros, usuários exclusivos, mensagens). */
    public function purge(Account $account): void
    {
        DB::transaction(function () use ($account) {
            $userIds = Membership::query()->withoutGlobalScopes()->where('account_id', $account->id)->pluck('user_id');
            WhatsappMessage::query()->where('account_id', $account->id)->delete();
            DB::table('whatsapp_pendings')->where('account_id', $account->id)->delete();
            foreach (['entries', 'debts', 'company_settings', 'local_imports', 'bank_accounts', 'company_access', 'whatsapp_contacts'] as $t) {
                DB::table($t)->where('account_id', $account->id)->delete();
            }
            DB::table('companies')->where('account_id', $account->id)->delete();
            DB::table('memberships')->where('account_id', $account->id)->delete();
            // usuários que não pertencem a nenhum outro cliente (ex.: contador de vários clientes é mantido)
            User::query()->whereIn('id', $userIds)->where('is_platform_admin', false)->whereDoesntHave('memberships', fn ($q) => $q->withoutGlobalScopes())->delete();
            $name = $account->name;
            $account->forceFill(['name' => 'Cliente excluído', 'document' => null, 'phone' => null, 'email' => 'excluido+'.$account->id.'@invalid', 'purge_after' => null, 'status_reason' => 'dados eliminados (LGPD)'])->save();
            $this->audit->log('lgpd.eliminar_cliente', $account, ['nome_anterior_hash' => hash('sha256', $name)], accountId: $account->id, channel: 'sistema');
        });
    }
}
