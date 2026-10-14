<?php

namespace App\Modules\Sprint\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Support\BusinessRuleViolation;
use App\Core\Tenancy\TenantContext;
use App\Modules\Platform\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Comissão da plataforma (dona do Melhor Sprint) sobre as vendas das clínicas.
 * Taxa padrão em config/sprint.php; o Super Admin pode definir uma taxa por clínica.
 */
class CommissionService
{
    public function __construct(private readonly TenantContext $context, private readonly AuditLogger $audit) {}

    /** Taxa vigente da clínica em centésimos de % (150 = 1,50%). */
    public function rateFor(string $companyId): int
    {
        $bp = Company::query()->whereKey($companyId)->value('sprint_commission_bp');

        return max(0, min(10000, (int) ($bp ?? config('sprint.commission_bp', 0))));
    }

    public function platformWallet(): ?string
    {
        return config('sprint.platform.asaas_wallet_id') ?: null;
    }

    public function platformSubordinate(): ?string
    {
        return config('sprint.platform.cielo_subordinate_id') ?: null;
    }

    /** Super Admin: taxa própria da clínica (null = volta à taxa padrão). */
    public function setRate(Company $company, ?int $bp): void
    {
        if ($bp !== null && ($bp < 0 || $bp > 3000)) {
            throw new BusinessRuleViolation('Comissão entre 0% e 30%.', 'invalid_rate');
        }

        // Fora do $fillable: a clínica não altera a própria comissão por nenhuma tela/API.
        $company->forceFill(['sprint_commission_bp' => $bp])->save();
    }

    /**
     * Resumo por clínica no período (painel da plataforma).
     *
     * @return Collection<int, object>
     */
    public function summary(string $from, string $to): Collection
    {
        [$start, $end] = $this->range($from, $to);

        return $this->context->runAsSystem(fn () => DB::table('sprint_splits as s')
            ->join('companies as c', 'c.id', '=', 's.company_id')
            ->where('s.party', 'platform')->where('s.status', '!=', 'reversed')
            ->whereBetween('s.created_at', [$start, $end])
            ->groupBy('s.company_id', 'c.trade_name')
            ->selectRaw("s.company_id, c.trade_name,
                SUM(CASE WHEN s.mode = 'native' THEN s.amount_cents ELSE 0 END) AS native_cents,
                SUM(CASE WHEN s.status = 'to_invoice' THEN s.amount_cents ELSE 0 END) AS to_invoice_cents,
                SUM(CASE WHEN s.status = 'invoiced' THEN s.amount_cents ELSE 0 END) AS invoiced_cents,
                COUNT(DISTINCT s.sale_id) AS sales")
            ->orderBy('c.trade_name')->get());
    }

    /** Volume vendido por clínica no período (base da comissão). */
    public function volume(string $from, string $to): Collection
    {
        [$start, $end] = $this->range($from, $to);

        return $this->context->runAsSystem(fn () => DB::table('sprint_payments')
            ->where('status', 'paid')->whereBetween('paid_at', [$start, $end])
            ->groupBy('company_id')->selectRaw('company_id, SUM(amount_cents) AS total')->pluck('total', 'company_id'));
    }

    /** Super Admin: marca a comissão "a cobrar" do período como cobrada (fatura/boleto emitido à clínica). */
    public function markInvoiced(string $companyId, string $from, string $to): int
    {
        [$start, $end] = $this->range($from, $to);

        return $this->context->runFor($companyId, function () use ($companyId, $start, $end, $from, $to) {
            $total = (int) DB::table('sprint_splits')->where('company_id', $companyId)->where('party', 'platform')->where('status', 'to_invoice')
                ->whereBetween('created_at', [$start, $end])->sum('amount_cents');

            if ($total <= 0) {
                throw new BusinessRuleViolation('Não há comissão a cobrar no período.', 'nothing_to_invoice');
            }

            DB::table('sprint_splits')->where('company_id', $companyId)->where('party', 'platform')->where('status', 'to_invoice')
                ->whereBetween('created_at', [$start, $end])->update(['status' => 'invoiced', 'settled_at' => now(), 'updated_at' => now()]);
            $this->audit->record('sprint.commission_invoiced', Company::query()->find($companyId), metadata: ['amount_cents' => $total, 'from' => $from, 'to' => $to]);

            return $total;
        });
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function range(string $from, string $to): array
    {
        return [CarbonImmutable::parse($from, 'America/Sao_Paulo')->startOfDay()->utc(), CarbonImmutable::parse($to, 'America/Sao_Paulo')->endOfDay()->utc()];
    }
}
