<?php

namespace App\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Support\BusinessRule;
use App\Core\Support\Money;
use App\Core\Support\Months;
use App\Core\Tenancy\TenantContext;
use App\Models\BankAccount;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Debt;
use App\Models\Entry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Regras financeiras do painel (as mesmas do WS Finanças original, agora no servidor):
 * contas do mês, atrasadas de meses anteriores, entradas, fixas/parceladas, dívidas e plano.
 */
class FinanceService
{
    public function __construct(private readonly TenantContext $ctx, private readonly AuditLogger $audit) {}

    /** Empresas visíveis: uma (id) ou todas as autorizadas ("todas" = consolidado). */
    public function companies(?string $selector): Collection
    {
        $q = Company::query()->where('active', true)->orderBy('name');
        if ($selector && $selector !== 'todas') {
            $q->whereKey($selector);
        }
        $list = $q->get();
        if ($list->isEmpty()) {
            throw new BusinessRule('Empresa não encontrada ou sem permissão de acesso.', 'empresa');
        }

        return $list;
    }

    public function company(string $id): Company
    {
        return Company::query()->where('active', true)->findOr($id, fn () => throw new BusinessRule('Empresa não encontrada ou sem permissão de acesso.', 'empresa'));
    }

    // ---------------------------------------------------------------- leitura

    /** Dados do mês no formato do painel (itens iguais aos do armazenamento local original). */
    public function monthItems(Collection $companies, string $month): array
    {
        $ids = $companies->pluck('id');
        $names = $companies->pluck('name', 'id');
        $multi = $companies->count() > 1;

        $entries = Entry::query()->whereIn('company_id', $ids)
            ->where(fn ($q) => $q->where('competence', $month)
                ->orWhere(fn ($q) => $q->where('type', 'despesa')->where('paid', false)->where('competence', '<', $month)))
            ->orderBy('due_date')->get();

        $items = $entries->map(fn (Entry $e) => $this->item($e, $multi ? $names[$e->company_id] ?? null : null))->all();

        foreach (Debt::query()->whereIn('company_id', $ids)->orderBy('created_at')->get() as $d) {
            $items[] = [
                'id' => $d->id, 'tipo' => 'divida', 'credor' => $d->creditor, 'saldo' => $d->balance_cents / 100, 'juros' => $d->monthly_rate,
                'parcela' => $d->min_payment_cents / 100, 'criado' => $d->created_at?->getTimestampMs(), 'exemplo' => $d->is_sample,
                'empresa' => $multi ? ($names[$d->company_id] ?? null) : null,
            ];
        }

        $settings = CompanySetting::query()->whereIn('company_id', $ids)->get();
        $items[] = [
            'id' => 'config', 'tipo' => 'config',
            'orc' => $settings->sum('debt_budget_cents') / 100,
            'estrategia' => $settings->first()?->debt_strategy ?? 'avalanche',
        ];

        return $items;
    }

    public function item(Entry $e, ?string $companyName = null): array
    {
        $base = [
            'id' => $e->id, 'tipo' => $e->type, 'desc' => $e->description, 'valor' => $e->amount_cents / 100, 'mes' => $e->competence,
            'criado' => $e->created_at?->getTimestampMs(), 'exemplo' => $e->is_sample, 'origem' => $e->source,
            'conta' => $e->bank_account_id, 'empresa' => $companyName, 'company_id' => $e->company_id,
        ];
        if ($e->recurrence_group) {
            $base['grupo'] = $e->recurrence_group;
            $base['parcela'] = $e->installments_total && $e->recurrence_kind === 'parcelada' ? $e->installment_no.'/'.$e->installments_total : null;
        }
        if ($e->type === 'despesa') {
            return $base + ['cat' => $e->category ?? 'Outros', 'venc' => $e->due_date->toDateString(), 'pago' => $e->paid, 'pagoEm' => $e->paid_at?->toDateString()];
        }

        return $base + ['cat' => $e->category ?? '', 'data' => $e->due_date->toDateString(), 'pago' => true];
    }

    // ---------------------------------------------------------------- escrita

    /**
     * Cria um lançamento; com repetir > 1 cria a série (fixa: mesmo valor todo mês; parcelada: valor dividido).
     *
     * @param  array{tipo:string, desc:string, valor_cents:int, cat?:?string, data:string, pago?:bool, repetir?:int, modo?:string, conta?:?string, source?:string, exemplo?:bool, external_ref?:?string}  $d
     * @return list<Entry>
     */
    public function create(Company $company, array $d): array
    {
        $type = $d['tipo'] === 'receita' ? 'receita' : 'despesa';
        $desc = trim(Str::limit(strip_tags($d['desc']), 190, ''));
        if ($desc === '' || ($d['valor_cents'] ?? 0) <= 0) {
            throw new BusinessRule('Preencha descrição e um valor maior que zero.', 'validacao');
        }
        $date = CarbonImmutable::parse($d['data']);
        $repeat = max(1, min(120, (int) ($d['repetir'] ?? 1)));
        $mode = ($d['modo'] ?? 'fixa') === 'parcelada' ? 'parcelada' : 'fixa';
        $conta = $this->bankAccountId($company, $d['conta'] ?? null);
        $cat = $this->category($type, $d['cat'] ?? null, $desc);
        $group = $repeat > 1 ? (string) Str::ulid() : null;

        $amounts = array_fill(0, $repeat, (int) $d['valor_cents']);
        if ($repeat > 1 && $mode === 'parcelada') {
            $part = intdiv((int) $d['valor_cents'], $repeat);
            $amounts = array_fill(0, $repeat, $part);
            $amounts[0] += (int) $d['valor_cents'] - $part * $repeat; // centavos de arredondamento na 1ª parcela
        }

        return DB::transaction(function () use ($company, $d, $type, $desc, $date, $repeat, $mode, $conta, $cat, $group, $amounts) {
            $created = [];
            for ($i = 0; $i < $repeat; $i++) {
                $ym = Months::add($date->format('Y-m'), $i);
                $paid = $type === 'receita' ? true : ($i === 0 && ! empty($d['pago']));
                $created[] = Entry::query()->create([
                    'company_id' => $company->id, 'type' => $type, 'description' => $desc, 'amount_cents' => $amounts[$i], 'category' => $cat,
                    'competence' => $ym, 'due_date' => Months::dateIn($ym, (int) $date->format('d')), 'paid' => $paid,
                    'paid_at' => $paid ? ($type === 'receita' ? Months::dateIn($ym, (int) $date->format('d')) : today()->toDateString()) : null,
                    'bank_account_id' => $conta, 'recurrence_group' => $group, 'recurrence_kind' => $group ? $mode : null,
                    'installment_no' => $group ? $i + 1 : null, 'installments_total' => $group ? $repeat : null,
                    'source' => $d['source'] ?? 'web', 'is_sample' => (bool) ($d['exemplo'] ?? false), 'external_ref' => $d['external_ref'] ?? null,
                    'created_by' => $this->ctx->userId(),
                ]);
            }
            if (empty($d['exemplo'])) {
                $this->audit->log('lancamento.criar', $created[0], ['tipo' => $type, 'desc' => $desc, 'valor' => $amounts[0], 'repetir' => $repeat, 'modo' => $mode, 'origem' => $d['source'] ?? 'web'],
                    channel: $d['source'] ?? 'web');
            }

            return $created;
        });
    }

    public function update(Entry $e, array $d): Entry
    {
        $before = $e->only(['description', 'amount_cents', 'due_date', 'category', 'paid']);
        if (isset($d['desc'])) {
            $e->description = trim(Str::limit(strip_tags($d['desc']), 190, '')) ?: $e->description;
        }
        if (isset($d['valor_cents'])) {
            if ($d['valor_cents'] <= 0) {
                throw new BusinessRule('O valor precisa ser maior que zero.', 'validacao');
            }
            $e->amount_cents = $d['valor_cents'];
        }
        if (! empty($d['data'])) {
            $date = CarbonImmutable::parse($d['data']);
            $e->due_date = $date->toDateString();
            $e->competence = $date->format('Y-m');
            if ($e->type === 'receita') {
                $e->paid_at = $date->toDateString();
            }
        }
        if (array_key_exists('cat', $d) && $d['cat'] !== null) {
            $e->category = $this->category($e->type, $d['cat'], $e->description);
        }
        if (array_key_exists('conta', $d)) {
            $e->bank_account_id = $this->bankAccountId($e->company, $d['conta']);
        }
        if (array_key_exists('pago', $d) && $e->type === 'despesa') {
            $e->paid = (bool) $d['pago'];
            $e->paid_at = $e->paid ? ($e->paid_at ?? today()) : null;
        }
        $e->save();
        $this->audit->log('lancamento.editar', $e, ['antes' => $before, 'depois' => $e->only(array_keys($before))], channel: $d['source'] ?? 'web');

        return $e;
    }

    public function setPaid(Entry $e, bool $paid, ?int $amountCents = null, string $channel = 'web'): Entry
    {
        if ($e->type !== 'despesa') {
            return $e;
        }
        $e->paid = $paid;
        $e->paid_at = $paid ? today() : null;
        if ($amountCents !== null && $amountCents > 0) {
            $e->amount_cents = $amountCents;
        }
        $e->save();
        $this->audit->log($paid ? 'lancamento.pagar' : 'lancamento.reabrir', $e, ['valor' => $e->amount_cents], channel: $channel);

        return $e;
    }

    /** Exclusão lógica (fica na auditoria). escopo "grupo" = esta e as próximas da série. */
    public function delete(Entry $e, string $scope = 'um', string $channel = 'web'): int
    {
        return DB::transaction(function () use ($e, $scope, $channel) {
            $targets = $scope === 'grupo' && $e->recurrence_group
                ? Entry::query()->where('recurrence_group', $e->recurrence_group)->where('competence', '>=', $e->competence)->get()
                : collect([$e]);
            foreach ($targets as $t) {
                $t->delete();
            }
            if (! $e->is_sample) {
                $this->audit->log('lancamento.excluir', $e, ['desc' => $e->description, 'valor' => $e->amount_cents, 'escopo' => $scope, 'qtd' => $targets->count()], channel: $channel);
            }

            return $targets->count();
        });
    }

    public function saveDebt(Company $company, array $d, ?Debt $debt = null): Debt
    {
        $creditor = trim(Str::limit(strip_tags((string) ($d['credor'] ?? '')), 150, ''));
        $saldo = Money::parse($d['saldo'] ?? null);
        $parcela = Money::parse($d['parcela'] ?? null);
        $juros = is_numeric(str_replace(',', '.', (string) ($d['juros'] ?? ''))) ? (float) str_replace(',', '.', (string) $d['juros']) : null;
        if ($creditor === '' || $saldo === null || $parcela === null || $juros === null || $juros < 0 || $juros > 100) {
            throw new BusinessRule('Informe credor, saldo, juros ao mês (0 a 100%) e parcela mínima.', 'validacao');
        }
        $debt ??= new Debt(['company_id' => $company->id]);
        $debt->fill(['creditor' => $creditor, 'balance_cents' => $saldo, 'monthly_rate' => $juros, 'min_payment_cents' => $parcela, 'is_sample' => (bool) ($d['exemplo'] ?? $debt->is_sample ?? false)]);
        $debt->save();
        if (! $debt->is_sample) {
            $this->audit->log('divida.salvar', $debt, ['credor' => $creditor, 'saldo' => $saldo]);
        }

        return $debt;
    }

    public function saveSettings(Company $company, ?int $budgetCents, ?string $strategy): CompanySetting
    {
        $s = CompanySetting::query()->firstOrNew(['company_id' => $company->id]);
        if ($budgetCents !== null) {
            $s->debt_budget_cents = max(0, $budgetCents);
        }
        if (in_array($strategy, ['avalanche', 'bola'], true)) {
            $s->debt_strategy = $strategy;
        }
        $s->debt_strategy ??= 'avalanche';
        $s->save();

        return $s;
    }

    // ---------------------------------------------------------------- exemplos

    public function loadSample(Company $company): void
    {
        $m0 = now()->format('Y-m');
        $m1 = Months::add($m0, -1);
        $base = [
            ['despesa', 'Aluguel', 1100, 'Moradia', 5, true], ['despesa', 'Conta de luz', 230, 'Contas da casa', 10, true], ['despesa', 'Internet', 120, 'Contas da casa', 8, false],
            ['despesa', 'Água', 95, 'Contas da casa', 18, false], ['despesa', 'Mercado do mês', 850, 'Alimentação', 3, true], ['despesa', 'iFood e lanches', 320, 'Delivery e lanches', 20, false],
            ['despesa', 'Netflix', 44.9, 'Assinaturas', 12, true], ['despesa', 'Spotify', 21.9, 'Assinaturas', 12, true], ['despesa', 'Fatura do cartão', 480, 'Dívidas', 20, false],
            ['despesa', 'Parcela do empréstimo', 350, 'Dívidas', 25, false], ['despesa', 'Gasolina', 260, 'Transporte', 9, true], ['receita', 'Salário', 3200, 'Salário', 5, true], ['receita', 'Freela', 400, 'Serviços prestados', 15, true],
        ];
        DB::transaction(function () use ($company, $base, $m0, $m1) {
            foreach ($base as [$tipo, $desc, $valor, $cat, $dia, $pago]) {
                $this->create($company, ['tipo' => $tipo, 'desc' => $desc, 'valor_cents' => (int) round($valor * 100), 'cat' => $cat, 'data' => Months::dateIn($m0, $dia), 'pago' => $pago, 'source' => 'exemplo', 'exemplo' => true]);
            }
            $this->create($company, ['tipo' => 'despesa', 'desc' => 'Internet', 'valor_cents' => 12000, 'cat' => 'Contas da casa', 'data' => Months::dateIn($m1, 8), 'source' => 'exemplo', 'exemplo' => true]);
            $this->create($company, ['tipo' => 'despesa', 'desc' => 'Conta de água', 'valor_cents' => 8800, 'cat' => 'Contas da casa', 'data' => Months::dateIn($m1, 18), 'source' => 'exemplo', 'exemplo' => true]);
            foreach ([['Cartão de crédito', '4800', '12', '480'], ['Empréstimo pessoal', '6200', '4,5', '350'], ['Cheque especial', '1300', '8', '150']] as [$c, $s, $j, $p]) {
                $this->saveDebt($company, ['credor' => $c, 'saldo' => $s, 'juros' => $j, 'parcela' => $p, 'exemplo' => true]);
            }
            $this->saveSettings($company, 120000, 'avalanche');
        });
    }

    public function clearSample(Company $company): void
    {
        DB::transaction(function () use ($company) {
            Entry::query()->where('company_id', $company->id)->where('is_sample', true)->forceDelete();
            Debt::query()->where('company_id', $company->id)->where('is_sample', true)->forceDelete();
            $this->saveSettings($company, 0, null);
        });
    }

    // ---------------------------------------------------------------- apoio

    public function category(string $type, ?string $cat, string $desc): string
    {
        $list = config('wsf.categories.'.$type);
        if ($cat && in_array($cat, $list, true)) {
            return $cat;
        }

        return $type === 'despesa' ? CategoryGuesser::guess($desc) : (CategoryGuesser::guessIncome($desc));
    }

    private function bankAccountId(Company $company, ?string $id): ?string
    {
        if (! $id) {
            return null;
        }

        return BankAccount::query()->where('company_id', $company->id)->whereKey($id)->value('id')
            ?? throw new BusinessRule('Conta bancária inválida para esta empresa.', 'validacao');
    }
}
