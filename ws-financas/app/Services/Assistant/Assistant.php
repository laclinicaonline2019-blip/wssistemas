<?php

namespace App\Services\Assistant;

use App\Core\Access\Permissions;
use App\Core\Audit\AuditLogger;
use App\Core\Support\Money;
use App\Core\Support\Months;
use App\Core\Tenancy\TenantContext;
use App\Models\Account;
use App\Models\BankAccount;
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
                if ($p['period_q']) {
                    $p['period'] = $p['period_q'];
                }
            }
        }

        return match ($p['intent']) {
            'ajuda' => $this->out($this->help(), 'ajuda'),
            'desfazer' => $this->undo($s),
            'corrigir' => $this->correct($s, (int) $p['amount_cents']),
            'consulta_pagar', 'consulta_receber', 'resumo' => $this->query($s, $p, $today),
            'consulta_gastos', 'consulta_recebido' => $this->spent($s, $p, $today),
            'saldo' => $this->balance($s, $p, $today),
            'por_categoria' => $this->byCategory($s, $p, $today),
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

        $wa = $s['channel'] === 'whatsapp';
        // valor ambíguo ("gastei 50 e 30") → pergunta qual
        if (! empty($p['amount_options']) && count($p['amount_options']) > 1) {
            return $this->ask($s, ['kind' => 'amount', 'options' => $p['amount_options'], 'company_id' => $company->id],
                "Encontrei mais de um valor na mensagem. Qual devo lançar?\n".collect($p['amount_options'])->values()->map(fn ($c, $i) => ($i + 1).' - '.Money::brl($c))->implode("\n")."\n\nResponda com o número ou NÃO para cancelar.", $p);
        }
        // sem verbo e com cara de entrada ("500 de salário") → pergunta se é receita ou despesa
        if ($wa && ! empty($p['no_verb']) && $p['intent'] === 'despesa' && $p['description'] !== ''
            && CategoryGuesser::guessIncome($p['description']) !== 'Outras entradas' && CategoryGuesser::guess($p['description']) === 'Outros') {
            return $this->ask($s, ['kind' => 'type', 'company_id' => $company->id],
                'Esse valor de '.Money::brl((int) $p['amount_cents']).' ('.$p['description'].") é:\n1 - 💵 Receita (dinheiro que entrou)\n2 - 💸 Despesa (dinheiro que saiu)\n\nResponda 1 ou 2.", $p);
        }
        // "100 de combustível" pelo WhatsApp: gasto já feito, com a data de hoje
        if ($wa && ! empty($p['implicit']) && $p['intent'] === 'despesa') {
            $p['paid'] = true;
        }

        if (! $p['amount_cents']) {
            return $this->out('Qual é o valor? Mande de novo com o valor. Ex.: '.($p['intent'] === 'receita' ? 'recebi 5.000 de um cliente' : 'paguei 350 de energia'), $p['intent']);
        }

        $tipo = $p['intent'] === 'receita' ? 'receita' : 'despesa';
        $date = CarbonImmutable::parse($p['date'] ?? CarbonImmutable::today()->toDateString());
        $today = CarbonImmutable::today();
        $dateDoubt = false;
        if ($wa && ($tipo === 'receita' || $p['paid']) && ! $p['installments'] && ! $p['recurring']) {
            if ($date->gt($today) && empty($p['date_explicit'])) {
                $date = $date->subMonthNoOverflow();   // "gastei 30 dia 20" no dia 16 → dia 20 do mês passado
                $dateDoubt = true;
            } elseif ($date->gt($today) || $date->lt($today->subDays(60))) {
                $dateDoubt = true;                     // pagamento/recebimento no futuro ou muito antigo
            }
        }

        $action = [
            'op' => 'create', 'company_id' => $company->id, 'tipo' => $tipo,
            'desc' => $p['description'], 'valor_cents' => (int) $p['amount_cents'], 'data' => $date->toDateString(), 'pago' => (bool) $p['paid'],
            'repetir' => $p['installments'] ?: ($p['recurring'] ? 12 : 1), 'modo' => $p['installments'] ? 'parcelada' : 'fixa',
            'cat' => $p['category'] ?? null,
        ];

        if (! $confirmed) {
            $doubts = [];
            if ($action['desc'] === '') {
                $action['desc'] = $action['tipo'] === 'receita' ? 'Entrada' : ($action['pago'] ? 'Despesa' : 'Conta a pagar');
                $doubts[] = 'sem_descricao';
            }
            if ($dateDoubt) {
                $doubts[] = 'data';
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
                    in_array('data', $doubts, true) => "📅 Confira a data.\n",
                    default => '',
                };
                $tail = in_array('sem_descricao', $doubts, true) ? "\n\nResponda SIM para lançar assim, mande a descrição (ex.: energia) ou NÃO para cancelar." : "\n\nResponda SIM para lançar ou NÃO para cancelar.";

                return $this->ask($s, ['kind' => 'confirm', 'action' => $action, 'awaiting_description' => in_array('sem_descricao', $doubts, true)],
                    $why.'Confirma? '.$this->describeAction($company, $action).$tail, $p);
            }
        }

        return $this->execute($s, $action, ! empty($p['ai']));
    }

    /** Categoria final: a escolhida/identificada; se as regras não reconhecerem, a IA classifica (quando o plano permitir). */
    private function categoryFor(array $s, array $a): ?string
    {
        $list = (array) config('wsf.categories.'.$a['tipo']);
        if (! empty($a['cat']) && in_array($a['cat'], $list, true)) {
            return $a['cat'];
        }
        $guess = $this->finance->category($a['tipo'], null, (string) $a['desc']);
        if (in_array($guess, ['Outros', 'Outras entradas'], true) && $s['channel'] === 'whatsapp' && $this->canUseAi($s)) {
            $ai = $this->ai->categorize($s['account_id'], (string) $a['desc'], $a['tipo'], $list);
            if ($ai !== null) {
                return $ai;
            }
        }

        return $guess;
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
        $cat = $this->categoryFor($s, $a);
        $created = $this->finance->create($company, [
            'tipo' => $a['tipo'], 'desc' => $a['desc'], 'valor_cents' => $a['valor_cents'], 'data' => $a['data'], 'pago' => $a['pago'],
            'repetir' => $a['repetir'], 'modo' => $a['modo'], 'source' => $channel, 'cat' => $cat,
        ]);
        $ids = array_map(fn ($e) => $e->id, $created);
        $this->remember($s, ['op' => 'create', 'entry_ids' => $ids]);
        $first = $created[0];
        $multi = Company::query()->where('active', true)->count() > 1;

        $title = match (true) {
            $a['tipo'] === 'receita' => '✅ Receita registrada com sucesso!',
            (bool) $first->paid => '✅ Despesa registrada com sucesso!',
            default => '✅ Conta a pagar registrada com sucesso!',
        };
        $lines = [$title, '📝 '.$first->description, CategoryGuesser::emoji($first->category).' Categoria: '.$first->category, '💰 Valor: '.Money::brl($first->amount_cents),
            ($a['tipo'] === 'despesa' && ! $first->paid ? '📅 Vencimento: ' : '📅 Data: ').$first->due_date->format('d/m/Y')];
        if ($multi) {
            $lines[] = '🏢 Empresa: '.$company->name;
        }
        if (count($created) > 1) {
            $lines[] = '🔁 '.($a['modo'] === 'parcelada' ? 'Parcelada em '.count($created).'x' : 'Repetindo por '.count($created).' meses');
        }
        $lines[] = 'Seu controle financeiro já foi atualizado.';

        return $this->out(implode("\n", $lines).$undo, $a['tipo'], $ids, usedAi: $usedAi);
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
        if ($kind === 'amount' && $p['intent'] === 'escolha') {
            $cents = $data['options'][$p['choice'] - 1] ?? null;
            if (! $cents) {
                return $this->out('Número inválido. Responda com um dos números da lista ou NÃO para cancelar.', 'escolha', pending: true);
            }
            $pending->delete();
            $parsed = $data['parsed'];
            $parsed['amount_cents'] = $cents;
            unset($parsed['amount_options']);

            return $this->launch($s, $parsed, $data['company_id'] ?? null);
        }
        if ($kind === 'type' && $p['intent'] === 'escolha' && in_array($p['choice'], [1, 2], true)) {
            $pending->delete();
            $parsed = $data['parsed'];
            $parsed['intent'] = $p['choice'] === 1 ? 'receita' : 'despesa';
            $parsed['paid'] = $p['choice'] === 2;
            $parsed['no_verb'] = false;
            $parsed['implicit'] = false;

            return $this->launch($s, $parsed, $data['company_id'] ?? null, true);
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

    /** @return array{0: Collection, 1: list<string>, 2: string, 3: bool} empresas visíveis, ids filtrados, texto do escopo, várias empresas */
    private function scope(array $s, array $p): array
    {
        $companies = Company::query()->where('active', true)->orderBy('name')->get();
        $hint = ! empty($p['company_hint']) ? $this->matchCompany($companies, $p['company_hint']) : null;
        $hint ??= ! empty($s['forced_company_id']) ? $companies->firstWhere('id', $s['forced_company_id']) : null;
        $ids = $hint ? [$hint->id] : $companies->pluck('id')->all();
        $label = $hint ? ' — '.$hint->name : ($companies->count() > 1 ? ' — todas as empresas' : '');

        return [$companies, $ids, $label, $companies->count() > 1 && ! $hint];
    }

    /** "Quanto gastei hoje / de combustível este mês?" · "Quanto recebi este mês?" — soma no banco, por data do lançamento. */
    private function spent(array $s, array $p, CarbonImmutable $today): array
    {
        [, $ids, $scope, $multi] = $this->scope($s, $p);
        $per = $p['period'] ?? (new MessageInterpreter)->pastPeriod('', $today);
        $income = $p['intent'] === 'consulta_recebido';
        $type = $income ? 'receita' : 'despesa';
        $q = Entry::query()->with('company')->whereIn('company_id', $ids)->where('type', $type)->whereBetween('due_date', [$per['from'], $per['to']]);
        if ($income) {
            $q->where('due_date', '<=', $today->toDateString());   // recebido = até hoje; o que vence depois é "a receber"
        }
        $filter = '';
        if (! empty($p['category'])) {
            $q->where('category', $p['category']);
            $filter = ' com '.CategoryGuesser::emoji($p['category']).' '.$p['category'];
        } elseif (! empty($p['term'])) {
            $words = array_values(array_filter(explode(' ', trim(CategoryGuesser::norm($p['term']))), fn ($w) => strlen($w) > 2));
            $rows = $q->orderBy('due_date')->limit(2000)->get()->filter(fn ($e) => collect($words)->every(fn ($w) => str_contains(CategoryGuesser::norm($e->description), $w)));
            $filter = ' com "'.$p['term'].'"';
        }
        $rows ??= $q->orderBy('due_date')->limit(2000)->get();
        $total = (int) $rows->sum('amount_cents');
        $what = $income ? 'Você recebeu' : 'Você gastou';

        if ($rows->isEmpty()) {
            $none = $income ? 'Nenhum recebimento registrado' : 'Nenhuma despesa registrada';

            return $this->out('📭 '.$none.$filter.' '.$per['label'].$scope.'.', $p['intent']);
        }
        $out = ($income ? '💵 ' : '💸 ').$what.' '.Money::brl($total).$filter.' '.$per['label'].$scope.'.';
        $out .= "\n".$rows->count().' lançamento'.($rows->count() > 1 ? 's' : '');
        if (! $income) {
            $open = (int) $rows->where('paid', false)->sum('amount_cents');
            $out .= $open > 0 ? ' · pago '.Money::brl($total - $open).' · em aberto '.Money::brl($open) : ' · tudo pago';
        }
        if ($income) {
            $later = (int) Entry::query()->whereIn('company_id', $ids)->where('type', 'receita')->whereBetween('due_date', [$today->addDay()->toDateString(), $per['to']])->sum('amount_cents');
            if ($later > 0 && $per['to'] > $today->toDateString()) {
                $out .= "\nAinda a receber ".$per['label'].': '.Money::brl($later).'.';
            }
        }
        $out .= "\n".$rows->sortByDesc('amount_cents')->take(8)->map(fn ($e) => '• '.$e->due_date->format('d/m').' '.$e->description.' — '.Money::brl($e->amount_cents).($multi ? ' ('.$e->company->name.')' : ''))->implode("\n");
        if ($rows->count() > 8) {
            $out .= "\n… e mais ".($rows->count() - 8).'. Detalhes no painel, em Relatórios.';
        }

        return $this->out($out, $p['intent']);
    }

    /** "Qual meu saldo?" — realizado no período (recebido − pago) e a sobra prevista do mês, com a mesma conta do painel. */
    private function balance(array $s, array $p, CarbonImmutable $today): array
    {
        [, $ids, $scope] = $this->scope($s, $p);
        $m = $p['period']['month'] ?? $today->format('Y-m');
        $q = fn () => Entry::query()->whereIn('company_id', $ids);
        $limit = $m === $today->format('Y-m') ? $today->toDateString() : CarbonImmutable::createFromFormat('!Y-m', $m)->endOfMonth()->toDateString();

        $recebido = (int) $q()->where('type', 'receita')->where('competence', $m)->where('due_date', '<=', $limit)->sum('amount_cents');
        $pago = (int) $q()->where('type', 'despesa')->where('competence', $m)->where('paid', true)->sum('amount_cents');
        // mesmos números do quadro do painel (Entradas, Contas do mês, Atrasadas de antes, Sobra prevista)
        $ent = (int) $q()->where('type', 'receita')->where('competence', $m)->sum('amount_cents');
        $desp = (int) $q()->where('type', 'despesa')->where('competence', $m)->sum('amount_cents');
        $atr = (int) $q()->where('type', 'despesa')->where('paid', false)->where('competence', '<', $m)->sum('amount_cents');

        $out = '💰 Saldo de '.Months::label($m).$scope.":\nEntradas recebidas: ".Money::brl($recebido)."\nDespesas pagas: ".Money::brl($pago)
            ."\n*Saldo: ".Money::brl($recebido - $pago).'*'
            ."\n\n📊 Previsão do mês (igual ao painel):\nEntradas: ".Money::brl($ent).' · Contas: '.Money::brl($desp).($atr ? ' · Atrasadas: '.Money::brl($atr) : '')
            ."\nSobra prevista: ".Money::brl($ent - $desp - $atr);
        $banks = BankAccount::query()->whereIn('company_id', $ids)->where('active', true)->get();
        if ($banks->isNotEmpty()) {
            $sum = $banks->sum(fn ($b) => $b->opening_balance_cents + (int) Entry::query()->where('bank_account_id', $b->id)->where('type', 'receita')->sum('amount_cents')
                - (int) Entry::query()->where('bank_account_id', $b->id)->where('type', 'despesa')->where('paid', true)->sum('amount_cents'));
            $out .= "\n🏦 Nas contas bancárias cadastradas: ".Money::brl($sum);
        }

        return $this->out($out, 'saldo');
    }

    /** "Me mostre minhas despesas por categoria" — mesma agregação do relatório do painel. */
    private function byCategory(array $s, array $p, CarbonImmutable $today): array
    {
        [, $ids, $scope] = $this->scope($s, $p);
        $per = $p['period'] ?? (new MessageInterpreter)->pastPeriod('', $today);
        $income = (bool) preg_match('/\b(receitas?|entradas?|recebi\w*|ganh\w*)\b/u', CategoryGuesser::norm((string) ($p['raw'] ?? '')));
        $rows = Entry::query()->whereIn('company_id', $ids)->where('type', $income ? 'receita' : 'despesa')->whereBetween('due_date', [$per['from'], $per['to']])
            ->selectRaw('category, SUM(amount_cents) as total, COUNT(*) as qtd')->groupBy('category')->orderByDesc('total')->get();
        $total = (int) $rows->sum('total');
        if ($total === 0) {
            return $this->out('📭 Nenhuma '.($income ? 'entrada' : 'despesa').' registrada '.$per['label'].$scope.'.', 'por_categoria');
        }
        $lines = $rows->map(fn ($r) => CategoryGuesser::emoji($r->category).' '.($r->category ?? 'Outros').': '.Money::brl((int) $r->total).' ('.round($r->total * 100 / $total).'%)');

        return $this->out('📊 '.($income ? 'Entradas' : 'Despesas').' por categoria '.$per['label'].$scope.":\n".$lines->implode("\n")."\n\nTotal: ".Money::brl($total), 'por_categoria');
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
            ->contains(fn ($e) => CategoryGuesser::norm($e->description) === CategoryGuesser::norm($a['desc'])
                || ($e->created_at->gte(now()->subMinutes(10)) && ($a['desc'] === '' || in_array($a['desc'], ['Despesa', 'Conta a pagar', 'Entrada'], true))));
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
        return "Exemplos:\n• 100 de combustível\n• recebi 3.000 de salário\n• conta de 1.200 para pagar dia 20\n• quanto gastei este mês?\n• qual meu saldo?\nMande AJUDA para ver tudo.";
    }

    public function help(): string
    {
        return "👋 Sou o assistente do *WS Finanças em Dia*.\n\n*Lançar*\n• 100 de combustível\n• gastei 50 no almoço\n• recebi 3.000 de salário\n• paguei 350 de energia da empresa A\n• cadastre uma conta de 1.200 para pagar dia 20\n"
            ."• aluguel 1.100 dia 5 fixa (repete 12 meses)\n• geladeira 3.000 em 10x (parcelado)\n• paguei internet (marca a conta em aberto)\n\n*Consultar*\n• quanto tenho para pagar amanhã?\n• contas desta semana?\n"
            ."• contas vencidas?\n• quanto gastei hoje?\n• quanto gastei de combustível este mês?\n• quanto recebi este mês?\n• qual meu saldo?\n• despesas por categoria\n\n*Corrigir*\n• DESFAZER (cancela o último)\n• CORRIGIR PARA 360 (muda o valor do último)\n\nTudo aparece na hora no painel.";
    }
}
