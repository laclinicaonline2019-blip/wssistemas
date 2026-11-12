<?php

namespace App\Services\Assistant;

use App\Core\Access\Permissions;
use App\Core\Audit\AuditLogger;
use App\Core\Support\Money;
use App\Core\Support\Months;
use App\Core\Tenancy\TenantContext;
use App\Models\Account;
use App\Models\Company;
use App\Models\Entry;
use App\Models\UsageCounter;
use App\Models\WhatsappPending;
use App\Services\CategoryGuesser;
use App\Services\FinanceService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Assistente de lançamentos e consultas — o mesmo cérebro para o WhatsApp e para a caixa
 * "Lançar rápido" do painel. Mensagens claras são registradas na hora (com DESFAZER);
 * mensagens com dúvida (empresa, descrição, possível repetição, interpretação por IA) pedem confirmação.
 */
class Assistant
{
    private const PENDING_MINUTES = 30;

    public function __construct(
        private readonly MessageInterpreter $rules,
        private readonly AiInterpreter $ai,
        private readonly FinanceService $finance,
        private readonly TenantContext $ctx,
    ) {}

    /**
     * @param  array{account_id:string, key:string, channel:string, role:string, company_ids:?array, default_company_id:?string, can_write:bool, user_id:?string, forced_company_id?:?string, base_date?:?string}  $s
     * @return array{reply:string, intent:string, entry_ids:list<string>, pending:bool, used_ai:bool}
     */
    public function handle(array $s, string $text): array
    {
        return $this->ctx->runFor($s['account_id'], fn () => $this->dispatch($s, trim($text)), $s['company_ids'], $s['role'], $s['user_id']);
    }

    private function dispatch(array $s, string $text): array
    {
        $today = CarbonImmutable::today();
        $base = isset($s['base_date']) && $s['base_date'] ? CarbonImmutable::parse($s['base_date']) : $today;
        $p = $this->rules->interpret($text, $base, $base->equalTo($today));
        $pending = WhatsappPending::query()->where('phone_hash', $s['key'])->where('expires_at', '>', now())->first();

        if ($pending) {
            $out = $this->resolvePending($s, $pending, $p, $text);
            if ($out !== null) {
                return $out;
            }
            $pending->delete(); // mensagem nova: descarta a pergunta anterior
        } elseif (in_array($p['intent'], ['sim', 'nao', 'escolha'], true)) {
            return $this->out('Não há nada aguardando confirmação. '.$this->shortHelp(), $p['intent']);
        }

        if ($p['intent'] === 'desconhecido' && $this->canUseAi($s)) {
            $companies = Company::query()->where('active', true)->pluck('name')->all();
            $aiParsed = $this->ai->interpret($s['account_id'], $text, $companies, $today);
            if ($aiParsed) {
                $p = $aiParsed;
            }
        }

        return match ($p['intent']) {
            'ajuda' => $this->out($this->help(), 'ajuda'),
            'desfazer' => $this->undo($s),
            'corrigir' => $this->correct($s, (int) $p['amount_cents']),
            'consulta_pagar', 'consulta_receber', 'resumo' => $this->query($s, $p, $today),
            'despesa', 'receita', 'pagar' => $this->launch($s, $p),
            default => $this->out("Não entendi. 🤔\n".$this->shortHelp(), 'desconhecido'),
        };
    }

    // ------------------------------------------------------------------ lançamentos

    private function launch(array $s, array $p, ?string $companyId = null, bool $confirmed = false): array
    {
        if ($deny = $this->denyWrite($s)) {
            return $deny;
        }
        $companies = Company::query()->where('active', true)->orderBy('name')->get();
        $company = $this->resolveCompany($s, $companies, $p['company_hint'] ?? null, $companyId);
        if (! $company) {
            if (! empty($p['company_hint']) && $companies->count() > 1) {
                $intro = 'Não encontrei a empresa "'.$p['company_hint'].'". Para qual empresa é?';
            } else {
                $intro = 'Para qual empresa é este lançamento?';
            }

            return $this->ask($s, ['kind' => 'company', 'parsed' => $p, 'options' => $companies->pluck('id')->all()],
                $intro."\n".$companies->values()->map(fn ($c, $i) => ($i + 1).' - '.$c->label())->implode("\n")."\n\nResponda com o número.", $p);
        }

        // "paguei a luz" / "paguei 350 de energia" → procura conta em aberto
        if ($p['intent'] === 'pagar' || ($p['intent'] === 'despesa' && $p['paid'] && $p['description'] !== '')) {
            $open = $this->findOpenBills($company, (string) $p['description']);
            if ($open->count() === 1) {
                if (! empty($p['ai']) && ! $confirmed) {
                    return $this->ask($s, ['kind' => 'confirm', 'action' => ['op' => 'pay', 'entry_id' => $open->first()->id, 'amount_cents' => $p['amount_cents']]],
                        'Marcar como paga: '.$this->billLine($open->first()).($p['amount_cents'] ? ' com valor '.Money::brl($p['amount_cents']) : '').'? Responda SIM ou NÃO.', $p);
                }

                return $this->execute($s, ['op' => 'pay', 'entry_id' => $open->first()->id, 'amount_cents' => $p['amount_cents']]);
            }
            if ($open->count() > 1) {
                return $this->ask($s, ['kind' => 'bill', 'options' => $open->pluck('id')->all(), 'amount_cents' => $p['amount_cents']],
                    "Encontrei mais de uma conta em aberto. Qual você pagou?\n".$open->values()->map(fn ($e, $i) => ($i + 1).' - '.$this->billLine($e))->implode("\n")."\n\nResponda com o número.", $p);
            }
            if ($p['intent'] === 'pagar') {
                return $this->out('Não achei conta em aberto com "'.($p['description'] ?: $p['raw']).'" em '.$company->name.'. Para lançar como nova despesa já paga, mande com o valor. Ex.: paguei '.Str::lower($p['description'] ?: 'luz').' 230', 'pagar');
            }
        }

        if (! $p['amount_cents']) {
            return $this->out('Qual é o valor? Mande de novo com o valor. Ex.: '.($p['intent'] === 'receita' ? 'recebi 5.000 de um cliente' : 'paguei 350 de energia'), $p['intent']);
        }

        $action = [
            'op' => 'create', 'company_id' => $company->id, 'tipo' => $p['intent'] === 'receita' ? 'receita' : 'despesa',
            'desc' => $p['description'], 'valor_cents' => (int) $p['amount_cents'], 'data' => $p['date'], 'pago' => (bool) $p['paid'],
            'repetir' => $p['installments'] ?: ($p['recurring'] ? 12 : 1), 'modo' => $p['installments'] ? 'parcelada' : 'fixa',
        ];

        if (! $confirmed) {
            $doubts = [];
            if ($action['desc'] === '') {
                $action['desc'] = $action['tipo'] === 'receita' ? 'Entrada' : 'Conta a pagar';
                $doubts[] = 'sem_descricao';
            }
            if (! empty($p['ai'])) {
                $doubts[] = 'ia';
            }
            if ($action['valor_cents'] > 10_000_000) {
                $doubts[] = 'valor_alto';
            }
            if ($this->looksDuplicate($company, $action)) {
                $doubts[] = 'repetido';
            }
            if ($doubts) {
                $why = match (true) {
                    in_array('repetido', $doubts, true) => "⚠️ Parece repetido: já existe um lançamento igual registrado há pouco.\n",
                    in_array('valor_alto', $doubts, true) => "⚠️ Valor alto — confira antes de lançar.\n",
                    default => '',
                };
                $tail = in_array('sem_descricao', $doubts, true) ? "\n\nResponda SIM para lançar assim, mande a descrição (ex.: energia) ou NÃO para cancelar." : "\n\nResponda SIM para lançar ou NÃO para cancelar.";

                return $this->ask($s, ['kind' => 'confirm', 'action' => $action, 'awaiting_description' => in_array('sem_descricao', $doubts, true)],
                    $why.'Confirma? '.$this->describeAction($company, $action).$tail, $p);
            }
        }

        return $this->execute($s, $action, ! empty($p['ai']));
    }

    private function execute(array $s, array $a, bool $usedAi = false): array
    {
        if ($deny = $this->denyWrite($s)) {
            return $deny;
        }
        $channel = $s['channel'] === 'whatsapp' ? 'whatsapp' : 'web';
        $undo = $s['channel'] === 'whatsapp' ? "\n\nErrou? Responda DESFAZER ou CORRIGIR PARA <valor>." : '';

        if ($a['op'] === 'pay') {
            $e = Entry::query()->findOrFail($a['entry_id']);
            $prev = ['paid' => $e->paid, 'amount_cents' => $e->amount_cents, 'paid_at' => $e->paid_at?->toDateString()];
            $this->finance->setPaid($e, true, $a['amount_cents'] ?? null, $channel);
            $this->remember($s, ['op' => 'pay', 'entry_id' => $e->id, 'prev' => $prev]);

            return $this->out('✅ Marquei como paga: '.$e->description.' — '.Money::brl($e->amount_cents).' ('.$e->company->name.').'.$undo, 'pagar', [$e->id], usedAi: $usedAi);
        }

        $company = Company::query()->findOrFail($a['company_id']);
        $created = $this->finance->create($company, [
            'tipo' => $a['tipo'], 'desc' => $a['desc'], 'valor_cents' => $a['valor_cents'], 'data' => $a['data'], 'pago' => $a['pago'],
            'repetir' => $a['repetir'], 'modo' => $a['modo'], 'source' => $channel,
        ]);
        $ids = array_map(fn ($e) => $e->id, $created);
        $this->remember($s, ['op' => 'create', 'entry_ids' => $ids]);
        $first = $created[0];

        $line = $a['tipo'] === 'receita'
            ? '✅ Entrada lançada — '.$company->name."\n".$first->description.': '.Money::brl($first->amount_cents).' em '.$first->due_date->format('d/m')
            : '✅ Despesa lançada — '.$company->name."\n".$first->description.': '.Money::brl($first->amount_cents)
                .($first->paid ? ' (paga)' : ', vence '.$first->due_date->format('d/m'))."\nCategoria: ".$first->category;
        if (count($created) > 1) {
            $line .= "\n".($a['modo'] === 'parcelada' ? 'Parcelada em '.count($created).'x' : 'Repetindo por '.count($created).' meses');
        }

        return $this->out($line.$undo, $a['tipo'], $ids, usedAi: $usedAi);
    }

    private function resolvePending(array $s, WhatsappPending $pending, array $p, string $text): ?array
    {
        $data = $pending->payload;
        $kind = $data['kind'];

        if ($p['intent'] === 'nao') {
            $pending->delete();

            return $this->out('Ok, não lancei nada.', 'nao');
        }
        if ($kind === 'confirm' && $p['intent'] === 'sim') {
            $pending->delete();

            return $this->execute($s, $data['action'], ! empty($data['parsed']['ai']));
        }
        if ($kind === 'confirm' && ! empty($data['awaiting_description']) && ($p['intent'] === 'desconhecido' || (in_array($p['intent'], ['despesa', 'receita'], true) && ! $p['amount_cents']))) {
            $pending->delete();
            $action = $data['action'];
            $action['desc'] = Str::ucfirst(Str::limit(trim(strip_tags($text)), 120, ''));

            return $this->execute($s, $action);
        }
        if (($kind === 'company' || $kind === 'bill') && $p['intent'] === 'escolha') {
            $id = $data['options'][$p['choice'] - 1] ?? null;
            if (! $id) {
                return $this->out('Número inválido. Responda com um dos números da lista ou NÃO para cancelar.', 'escolha', pending: true);
            }
            $pending->delete();
            if ($kind === 'company') {
                return $this->launch($s, $data['parsed'], $id);
            }

            return $this->execute($s, ['op' => 'pay', 'entry_id' => $id, 'amount_cents' => $data['amount_cents'] ?? null]);
        }
        if (in_array($p['intent'], ['sim', 'escolha'], true)) {
            return $this->out($kind === 'confirm' ? 'Responda SIM ou NÃO.' : 'Responda com o número da lista ou NÃO para cancelar.', $p['intent'], pending: true);
        }

        return null;
    }

    // ------------------------------------------------------------------ desfazer / corrigir

    private function undo(array $s): array
    {
        if ($deny = $this->denyWrite($s)) {
            return $deny;
        }
        $last = Cache::get($this->lastKey($s));
        if (! $last) {
            return $this->out('Não encontrei lançamento recente para desfazer (vale por 24 horas). No painel você pode editar ou excluir qualquer lançamento.', 'desfazer');
        }
        Cache::forget($this->lastKey($s));
        $channel = $s['channel'] === 'whatsapp' ? 'whatsapp' : 'web';

        if ($last['op'] === 'pay') {
            $e = Entry::query()->find($last['entry_id']);
            if ($e) {
                $e->forceFill(['paid' => $last['prev']['paid'], 'amount_cents' => $last['prev']['amount_cents'], 'paid_at' => $last['prev']['paid_at']])->save();
                app(AuditLogger::class)->log('lancamento.desfazer_pagamento', $e, [], channel: $channel);

                return $this->out('↩️ Desfeito: '.$e->description.' voltou para em aberto.', 'desfazer', [$e->id]);
            }
        } else {
            $entries = Entry::query()->whereIn('id', $last['entry_ids'])->get();
            foreach ($entries as $e) {
                $this->finance->delete($e, 'um', $channel);
            }
            if ($entries->isNotEmpty()) {
                return $this->out('↩️ Cancelado: '.$entries->first()->description.' — '.Money::brl($entries->first()->amount_cents).($entries->count() > 1 ? ' e as '.($entries->count() - 1).' repetições' : '').'.', 'desfazer', $entries->pluck('id')->all());
            }
        }

        return $this->out('Esse lançamento já tinha sido removido.', 'desfazer');
    }

    private function correct(array $s, int $cents): array
    {
        if ($deny = $this->denyWrite($s)) {
            return $deny;
        }
        $last = Cache::get($this->lastKey($s));
        $id = $last['op'] ?? null ? ($last['op'] === 'pay' ? $last['entry_id'] : ($last['entry_ids'][0] ?? null)) : null;
        $e = $id ? Entry::query()->find($id) : null;
        if (! $e || $cents <= 0) {
            return $this->out('Não encontrei lançamento recente para corrigir. Use o painel para editar.', 'corrigir');
        }
        $old = $e->amount_cents;
        $this->finance->update($e, ['valor_cents' => $cents, 'source' => $s['channel'] === 'whatsapp' ? 'whatsapp' : 'web']);

        return $this->out('✏️ Corrigido: '.$e->description.' de '.Money::brl($old).' para '.Money::brl($cents).'.', 'corrigir', [$e->id]);
    }

    // ------------------------------------------------------------------ consultas

    private function query(array $s, array $p, CarbonImmutable $today): array
    {
        $companies = Company::query()->where('active', true)->orderBy('name')->get();
        $hint = $p['company_hint'] ? $this->matchCompany($companies, $p['company_hint']) : null;
        $hint ??= ! empty($s['forced_company_id']) ? $companies->firstWhere('id', $s['forced_company_id']) : null;
        $ids = $hint ? [$hint->id] : $companies->pluck('id')->all();
        $scope = $hint ? ' em '.$hint->name : ($companies->count() > 1 ? ' (todas as empresas)' : '');
        $per = $p['period'] ?? ['from' => $today->startOfMonth()->toDateString(), 'to' => $today->endOfMonth()->toDateString(), 'label' => 'neste mês'];

        if ($p['intent'] === 'resumo') {
            $m = $today->format('Y-m');
            $q = fn () => Entry::query()->whereIn('company_id', $ids);
            $ent = (int) $q()->where('type', 'receita')->where('competence', $m)->sum('amount_cents');
            $desp = (int) $q()->where('type', 'despesa')->where('competence', $m)->sum('amount_cents');
            $pago = (int) $q()->where('type', 'despesa')->where('competence', $m)->where('paid', true)->sum('amount_cents');
            $atr = (int) $q()->where('type', 'despesa')->where('paid', false)->where('competence', '<', $m)->sum('amount_cents');

            return $this->out('📊 Resumo de '.Months::label($m).$scope.":\nEntradas: ".Money::brl($ent)."\nContas do mês: ".Money::brl($desp).' (pago '.Money::brl($pago).")\nAtrasadas de antes: "
                .Money::brl($atr)."\nSobra prevista: ".Money::brl($ent - $desp - $atr), 'resumo');
        }

        $type = $p['intent'] === 'consulta_receber' ? 'receita' : 'despesa';
        $q = Entry::query()->with('company')->whereIn('company_id', $ids)->where('type', $type)->orderBy('due_date');
        if ($type === 'despesa') {
            $q->where('paid', false);
        }
        if (! empty($per['overdue'])) {
            $q->where('due_date', '<', $today->toDateString());
        } else {
            $q->whereBetween('due_date', [$per['from'], $per['to']]);
        }
        $list = $q->limit(200)->get();
        $total = (int) $list->sum('amount_cents');
        $verb = $type === 'despesa' ? (! empty($per['overdue']) ? 'em contas vencidas' : 'para pagar '.$per['label']) : 'de entradas '.$per['label'];
        if ($list->isEmpty()) {
            return $this->out(($type === 'despesa' ? '🎉 Nada ' : 'Nenhuma entrada ').($type === 'despesa' ? $verb : $per['label']).$scope.'.', $p['intent']);
        }
        $lines = $list->take(10)->map(fn ($e) => '• '.$e->description.' — '.Money::brl($e->amount_cents).' ('.$e->due_date->format('d/m').($companies->count() > 1 && ! $hint ? ', '.$e->company->name : '').')');

        return $this->out('Você tem '.Money::brl($total).' '.$verb.$scope.":\n".$lines->implode("\n").($list->count() > 10 ? "\n… e mais ".($list->count() - 10).'. Veja tudo no painel.' : ''), $p['intent']);
    }

    // ------------------------------------------------------------------ apoio

    private function resolveCompany(array $s, Collection $companies, ?string $hint, ?string $forced): ?Company
    {
        if ($forced) {
            return $companies->firstWhere('id', $forced);
        }
        if (! empty($s['forced_company_id'])) {
            return $companies->firstWhere('id', $s['forced_company_id']);
        }
        if ($hint) {
            return $this->matchCompany($companies, $hint);
        }
        if ($companies->count() === 1) {
            return $companies->first();
        }

        return $s['default_company_id'] ? $companies->firstWhere('id', $s['default_company_id']) : null;
    }

    private function matchCompany(Collection $companies, string $hint): ?Company
    {
        $h = CategoryGuesser::norm($hint);
        $exact = $companies->filter(fn ($c) => CategoryGuesser::norm($c->name) === $h || ($c->alias && CategoryGuesser::norm($c->alias) === $h));
        if ($exact->count() === 1) {
            return $exact->first();
        }
        $partial = $companies->filter(fn ($c) => str_contains(CategoryGuesser::norm($c->name), $h) || ($c->alias && str_contains(CategoryGuesser::norm($c->alias), $h)));

        return $partial->count() === 1 ? $partial->first() : null;
    }

    private function findOpenBills(Company $company, string $desc): Collection
    {
        $words = array_values(array_filter(explode(' ', trim(CategoryGuesser::norm($desc))), fn ($w) => strlen($w) > 2));
        if (! $words) {
            return collect();
        }
        $open = Entry::query()->where('company_id', $company->id)->where('type', 'despesa')->where('paid', false)
            ->where('competence', '<=', now()->addMonth()->format('Y-m'))->orderBy('due_date')->limit(300)->get();
        $all = $open->filter(fn ($e) => collect($words)->every(fn ($w) => str_contains(CategoryGuesser::norm($e->description), $w)));
        $found = $all->isNotEmpty() ? $all : $open->filter(fn ($e) => collect($words)->contains(fn ($w) => str_contains(CategoryGuesser::norm($e->description), $w)));

        // a mesma conta repetida em vários meses: fica com a mais antiga em aberto
        return $found->groupBy(fn ($e) => $e->recurrence_group ?? $e->id)->map->first()->values()->take(9);
    }

    private function looksDuplicate(Company $company, array $a): bool
    {
        return Entry::query()->where('company_id', $company->id)->where('type', $a['tipo'])->where('amount_cents', $a['valor_cents'])
            ->where('created_at', '>=', now()->subDay())->get()
            ->contains(fn ($e) => CategoryGuesser::norm($e->description) === CategoryGuesser::norm($a['desc']) || $e->created_at->gte(now()->subMinutes(10)));
    }

    private function describeAction(Company $company, array $a): string
    {
        $kind = $a['tipo'] === 'receita' ? 'Entrada' : ($a['pago'] ? 'Despesa paga' : 'Conta a pagar');
        $date = CarbonImmutable::parse($a['data'])->format('d/m/Y');
        $rep = $a['repetir'] > 1 ? ($a['modo'] === 'parcelada' ? ', em '.$a['repetir'].'x' : ', repetindo por '.$a['repetir'].' meses') : '';

        return $kind.' de '.Money::brl($a['valor_cents']).' — '.$a['desc'].' — '.($a['tipo'] === 'despesa' && ! $a['pago'] ? 'vence ' : 'em ').$date.$rep.' — '.$company->name.'.';
    }

    private function billLine(Entry $e): string
    {
        return $e->description.' — '.Money::brl($e->amount_cents).' (vence '.$e->due_date->format('d/m').')';
    }

    private function ask(array $s, array $payload, string $question, array $parsed): array
    {
        $payload['parsed'] ??= $parsed;
        WhatsappPending::query()->updateOrCreate(['phone_hash' => $s['key']], ['account_id' => $s['account_id'], 'payload' => $payload, 'expires_at' => now()->addMinutes(self::PENDING_MINUTES)]);

        return $this->out($question, 'pergunta', pending: true, usedAi: ! empty($parsed['ai']));
    }

    private function denyWrite(array $s): ?array
    {
        if (! $s['can_write']) {
            $account = Account::query()->find($s['account_id']);
            $why = $account && ! $account->canWrite()
                ? 'A assinatura está suspensa: os dados continuam disponíveis para consulta, mas novos lançamentos estão bloqueados. Regularize no painel, em Assinatura.'
                : 'Este número/usuário só tem permissão de consulta.';

            return $this->out('🔒 '.$why, 'bloqueado');
        }
        if (! Permissions::can('financeiro.lancar')) {
            return $this->out('🔒 Seu perfil só tem permissão de consulta.', 'bloqueado');
        }

        return null;
    }

    private function canUseAi(array $s): bool
    {
        $account = Account::query()->with('subscription.plan')->find($s['account_id']);
        $plan = $account?->plan();
        if (! $plan || ! $plan->has('ai') || ! $this->ai->available()) {
            return false;
        }

        return UsageCounter::current($s['account_id'])->ai_calls < $plan->ai_calls_month;
    }

    private function remember(array $s, array $last): void
    {
        Cache::put($this->lastKey($s), $last, now()->addDay());
    }

    private function lastKey(array $s): string
    {
        return 'wsf:last:'.$s['key'];
    }

    private function out(string $reply, string $intent, array $ids = [], bool $pending = false, bool $usedAi = false): array
    {
        return ['reply' => $reply, 'intent' => $intent, 'entry_ids' => $ids, 'pending' => $pending, 'used_ai' => $usedAi];
    }

    private function shortHelp(): string
    {
        return "Exemplos:\n• paguei 350 de energia da empresa A\n• recebi 5.000 de um cliente\n• conta de 1.200 para pagar dia 20\n• quanto tenho para pagar amanhã?\nMande AJUDA para ver tudo.";
    }

    public function help(): string
    {
        return "👋 Sou o assistente do *WS Finanças em Dia*.\n\n*Lançar*\n• paguei 350 de energia da empresa A\n• recebi 5.000 de um cliente\n• cadastre uma conta de 1.200 para pagar dia 20\n"
            ."• aluguel 1.100 dia 5 fixa (repete 12 meses)\n• geladeira 3.000 em 10x (parcelado)\n• paguei internet (marca a conta em aberto)\n\n*Consultar*\n• quanto tenho para pagar amanhã?\n• contas desta semana?\n"
            ."• contas vencidas?\n• resumo do mês\n\n*Corrigir*\n• DESFAZER (cancela o último)\n• CORRIGIR PARA 360 (muda o valor do último)\n\nTudo aparece na hora no painel.";
    }
}
