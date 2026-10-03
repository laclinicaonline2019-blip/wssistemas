<?php

namespace App\Modules\Security\Services;

use App\Core\Audit\AuditLogger;
use App\Modules\Platform\Models\Company;
use Illuminate\Support\Facades\DB;

/**
 * Retenção de dados (LGPD, Fase 17) — só dados OPERACIONAIS, configuráveis por clínica.
 *
 * Nunca toca prontuário, documentos médicos, anexos, financeiro ou a trilha de auditoria
 * (guarda legal). O que faz, conforme os prazos da clínica:
 *  - avisos internos lidos/antigos: apagados;
 *  - registros de chamadas à IA: apagados; ações da IA: entrada/resultado removidos (fica o "o quê/quando");
 *  - texto das conversas de WhatsApp (opcional, padrão: manter): substituído por aviso de retenção;
 *  - conteúdo bruto de webhooks de pagamento: removido;
 *  - links de acesso do portal já vencidos: apagados.
 */
class RetentionService
{
    public const DEFAULTS = ['notifications' => 180, 'ai_logs' => 365, 'whatsapp_text' => 0, 'webhook_payloads' => 180];

    public const LABELS = [
        'notifications' => 'Avisos internos da equipe (sino)',
        'ai_logs' => 'Registros técnicos da IA (chamadas e detalhes das ações)',
        'whatsapp_text' => 'Texto das conversas de WhatsApp (0 = manter)',
        'webhook_payloads' => 'Conteúdo bruto dos avisos dos gateways de pagamento',
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    public function policy(Company $company): array
    {
        return array_map('intval', array_merge(self::DEFAULTS, (array) $company->setting('retention', [])));
    }

    /** @return array<string, int> quantidades afetadas */
    public function apply(Company $company): array
    {
        $p = $this->policy($company);
        $cid = $company->id;
        $before = fn (int $days) => now()->subDays($days);
        $done = ['notifications' => 0, 'ai_requests' => 0, 'ai_tool_calls' => 0, 'whatsapp_text' => 0, 'webhook_payloads' => 0, 'portal_tokens' => 0];

        DB::transaction(function () use ($p, $cid, $before, &$done) {
            if ($p['notifications'] > 0) {
                $old = DB::table('staff_notifications')->where('company_id', $cid)->where('created_at', '<', $before($p['notifications']))->pluck('id');
                foreach ($old->chunk(500) as $ids) {
                    DB::table('staff_notification_reads')->whereIn('notification_id', $ids)->delete();
                    $done['notifications'] += DB::table('staff_notifications')->whereIn('id', $ids)->delete();
                }
            }
            if ($p['ai_logs'] > 0) {
                $done['ai_requests'] = DB::table('ai_requests')->where('company_id', $cid)->where('created_at', '<', $before($p['ai_logs']))->delete();
                $done['ai_tool_calls'] = DB::table('ai_tool_calls')->where('company_id', $cid)->where('created_at', '<', $before($p['ai_logs']))
                    ->where(fn ($q) => $q->whereNotNull('input')->orWhereNotNull('result'))->update(['input' => null, 'result' => null]);
            }
            if ($p['whatsapp_text'] > 0) {
                $done['whatsapp_text'] = DB::table('messages')->where('company_id', $cid)->where('channel', 'whatsapp')->where('created_at', '<', $before($p['whatsapp_text']))
                    ->where('body', '!=', '[texto removido pela política de retenção]')->update(['body' => '[texto removido pela política de retenção]', 'params' => null, 'updated_at' => now()]);
            }
            if ($p['webhook_payloads'] > 0) {
                $done['webhook_payloads'] = DB::table('payment_webhook_events')->where('company_id', $cid)->where('received_at', '<', $before($p['webhook_payloads']))
                    ->whereNotNull('payload')->update(['payload' => null]);
            }
            $done['portal_tokens'] = DB::table('patient_account_tokens')->where('company_id', $cid)->where('expires_at', '<', now()->subDays(30))->delete();
        });

        if (array_sum($done) > 0) {
            $this->audit->record('retention.applied', $company, metadata: $done + ['policy' => $p], companyId: $cid, actorType: 'system');
        }

        return $done;
    }
}
