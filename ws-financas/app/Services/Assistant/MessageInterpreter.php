<?php

namespace App\Services\Assistant;

use App\Core\Support\Money;
use App\Core\Support\Months;
use App\Services\CategoryGuesser;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Interpretador por regras (sem custo, sem IA) das mensagens em português:
 *  "Paguei R$ 350 de energia da empresa A."   → despesa paga
 *  "Recebi R$ 5.000 de um cliente."           → receita
 *  "Cadastre uma conta de R$ 1.200 para pagar dia 20." → despesa em aberto vencendo dia 20
 *  "Quanto tenho para pagar amanhã?"          → consulta
 *  "luz 230 vence 15" / "aluguel 1100 dia 5 fixa" / "geladeira 3000 em 10x" (formatos do painel original)
 * Quando não entende, devolve intent "desconhecido" (e o assistente pode recorrer à IA, se o plano permitir).
 */
class MessageInterpreter
{
    private const STOP = ['paguei', 'pago', 'paga', 'pagamos', 'quitei', 'recebi', 'recebemos', 'recebido', 'recebida', 'entrou', 'ganhei', 'gastei', 'gastamos', 'comprei', 'compramos',
        'vendi', 'vendemos', 'cadastre', 'cadastrar', 'cadastra', 'lance', 'lancar', 'lanca', 'registre', 'registrar', 'registra', 'anote', 'anotar', 'anota', 'adicione', 'adicionar', 'inclua', 'incluir',
        'reais', 'real', 'r', 'de', 'do', 'da', 'dos', 'das', 'no', 'na', 'nos', 'nas', 'o', 'a', 'os', 'as', 'em', 'com', 'pra', 'para', 'por', 'pelo', 'pela', 'fixa', 'fixo', 'mensal', 'todo', 'mes',
        'conta', 'valor', 'hoje', 'ontem', 'amanha', 'foi', 'e', 'um', 'uma', 'uns', 'umas', 'que', 'vence', 'vencimento', 'dia', 'pagar', 'receber', 'favor', 'por favor', 'ai', 'aí', 'meu', 'minha',
        'nova', 'novo', 'agora', 'ja', 'só', 'so', 'tambem', 'mais', 'referente', 'ref', 'vezes', 'x', 'parcelas', 'parcelado', 'parcelada', 'todos', 'meses', 'empresa', 'empresas', 'lancamento', 'despesa', 'receita', 'entrada'];

    public function interpret(string $text, ?CarbonImmutable $today = null, bool $rollover = true): array
    {
        $today ??= CarbonImmutable::today();
        $raw = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        $n = $this->norm($raw);
        $r = ['intent' => 'desconhecido', 'amount_cents' => null, 'date' => null, 'date_explicit' => false, 'paid' => false, 'recurring' => false,
            'installments' => null, 'description' => '', 'company_hint' => null, 'period' => null, 'raw' => $raw];

        if ($n === '') {
            return $r;
        }

        // --- respostas curtas (usadas quando há pergunta pendente)
        if (preg_match('/^(sim|s|confirmo|confirmar|confirma|ok|isso|pode|pode sim|certo|correto|isso mesmo|yes|👍)$/u', $n)) {
            return ['intent' => 'sim'] + $r;
        }
        if (preg_match('/^(nao|n|nao obrigado|negativo|errado|esquece|deixa)$/u', $n)) {
            return ['intent' => 'nao'] + $r;
        }
        if (preg_match('/^(\d{1,2})$/', $n, $m)) {
            return ['intent' => 'escolha', 'choice' => (int) $m[1]] + $r;
        }
        if (preg_match('/^(ajuda|menu|help|comandos|oi|ola|bom dia|boa tarde|boa noite|inicio|\?)$/u', $n)) {
            return ['intent' => 'ajuda'] + $r;
        }
        if (preg_match('/\b(desfazer|desfaz|desfaca)\b|\b(cancela|cancelar|apaga|apagar|exclui|excluir|remove|remover|estorna|estornar)( o| a)? (ultimo|ultima|lancamento|isso)\b|^(cancela|cancelar)$/u', $n)) {
            return ['intent' => 'desfazer'] + $r;
        }
        if (preg_match('/\b(corrig\w*|na verdade|errei|o certo e|o correto e)\b/u', $n) && ($v = $this->amount($raw)) !== null) {
            return ['intent' => 'corrigir', 'amount_cents' => $v[0]] + $r;
        }

        // --- consultas (somente leitura; os números vêm sempre do banco)
        $isQuestion = str_contains($raw, '?') || preg_match('/^(quanto|quantos|quantas|quais|qual|o que|oque|tenho|mostra|mostre|mostrar|me mostra|me mostre|me manda|me envie|me diga|me fala|me passa|lista|liste|listar|ver|resumo|saldo|extrato|relatorio|como estou|como esta|como estamos|contas|minhas|meus|quero (ver|saber)|despesas|gastos|receitas|entradas|recebimentos|lancamentos)\b/u', $n);
        if ($isQuestion && $this->amount($raw) === null) {
            $r['company_hint'] = $this->companyHint($raw, $n);
            $r['intent'] = match (true) {
                (bool) preg_match('/\b(por categoria|categorias|cada categoria|por tipo)\b/u', $n) => 'por_categoria',
                (bool) preg_match('/\b(saldo|sobrou|sobra|quanto (me )?resta|quanto (eu )?tenho (em caixa|na conta|disponivel|sobrando))\b/u', $n) => 'saldo',
                (bool) preg_match('/\b(resumo|como estou|como esta|como estamos|fechamento|extrato|balanco)\b/u', $n) => 'resumo',
                (bool) preg_match('/\b(a receber|para receber|pra receber|vou receber|tenho (para|pra) receber|falta receber)\b/u', $n) => 'consulta_receber',
                (bool) preg_match('/\b(recebi|recebemos|recebido|recebidos|recebida|recebidas|entrou|entraram|entradas?|receitas?|ganhei|ganhamos|faturei|faturamos|faturamento|vendi|vendemos|vendas)\b/u', $n) => 'consulta_recebido',
                (bool) preg_match('/\b(para pagar|pra pagar|a pagar|vence|vencem|vencendo|vencid\w*|atrasad\w*|em aberto|tenho que pagar|contas)\b/u', $n) => 'consulta_pagar',
                (bool) preg_match('/\b(gastei|gastamos|gasto|gastos|despesas?|paguei|pagamos|pago|saiu|sairam|saidas?|custou|torrei)\b/u', $n) => 'consulta_gastos',
                (bool) preg_match('/\b(receber|recebo|entrar|entra|recebimentos)\b/u', $n) => 'consulta_receber',
                default => 'consulta_pagar',
            };
            $r['period'] = in_array($r['intent'], ['consulta_pagar', 'consulta_receber'], true) ? $this->period($n, $today) : $this->pastPeriod($n, $today);
            if (in_array($r['intent'], ['consulta_gastos', 'consulta_recebido'], true)) {
                [$r['category'], $r['term']] = $this->queryFilter($n, $r['intent'] === 'consulta_recebido' ? 'receita' : 'despesa');
            }

            return $r;
        }

        // --- lançamentos
        $work = ' '.$raw.' ';
        $r['company_hint'] = $this->companyHint($raw, $n);
        if ($r['company_hint']) {
            $work = preg_replace('/\b(da|na|pela|para a|pra|p\/)?\s*empresa\s+'.preg_quote($r['company_hint'], '/').'\b/iu', ' ', $work) ?? $work;
        }

        // parcelas: "em 10x", "10 vezes", "em 3 parcelas"
        if (preg_match('/\b(?:em\s+)?(\d{1,3})\s*(?:x|vezes|parcelas)\b/iu', $work, $m) && (int) $m[1] >= 2) {
            $r['installments'] = min(120, (int) $m[1]);
            $work = str_replace($m[0], ' ', $work);
        }
        $r['recurring'] = (bool) preg_match('/\b(fixa|fixo|todo mes|todos os meses|mensal|mensalmente|recorrente)\b/u', $n);

        // datas (antes dos valores, para "20/11" não virar valor)
        [$date, $explicit, $work] = $this->date($work, $today);

        if (($v = $this->amount($work)) !== null) {
            [$r['amount_cents'], $matched] = $v;
            $work = preg_replace('/'.preg_quote($matched, '/').'/u', ' ', $work, 1) ?? $work;
            // outro número com cara de valor na mesma mensagem ("gastei 50 e 30") → ambíguo
            $others = [];
            $rest = $work;
            while (($o = $this->amount($rest)) !== null) {
                $rest = preg_replace('/'.preg_quote($o[1], '/').'/u', ' ', $rest, 1) ?? '';
                if ($o[0] !== $r['amount_cents'] && $o[0] >= 100) {
                    $others[] = $o[0];
                }
            }
            if ($others) {
                $r['amount_options'] = array_values(array_unique(array_merge([$r['amount_cents']], $others)));
            }
        }

        $income = (bool) preg_match('/\b(recebi|recebemos|recebido|recebida|recebimento|entrou|entraram|ganhei|vendi|vendemos|faturei|salario caiu|pix recebido|receita|entrada)\b/u', $n);
        $paidVerb = (bool) preg_match('/\b(paguei|pagamos|pago|paga|quitei|quitamos|gastei|gastamos|comprei|compramos|debitou|debitado)\b/u', $n);
        $toPay = (bool) preg_match('/\b(para pagar|pra pagar|a pagar|vence|vencimento|boleto|cadastr\w*|agend\w*|lanc\w*|registr\w*|anot\w*|conta de|conta da|conta do)\b/u', $n);

        $r['intent'] = $income ? 'receita' : 'despesa';
        $r['paid'] = ! $income && $paidVerb && ! preg_match('/\b(para pagar|pra pagar|a pagar|vence)\b/u', $n);
        // sem verbo nenhum ("100 de combustível"): gasto já feito, salvo marcas de conta futura (vence, dia N, fixa, parcelas)
        $r['no_verb'] = ! $income && ! $paidVerb && ! $toPay;
        $r['implicit'] = $r['no_verb'] && ! $r['recurring'] && ! $r['installments'] && ($date === null || ($explicit && $date->lte($today)));
        if (! $income && ! $paidVerb && ! $toPay && $r['amount_cents'] === null) {
            return ['intent' => 'desconhecido'] + $r;
        }
        if (! $income && $paidVerb && $r['amount_cents'] === null && preg_match('/\b(paguei|pagamos|pago|paga|quitei|quitamos)\b/u', $n)) {
            $r['intent'] = 'pagar'; // "paguei a luz" → marca a conta em aberto ("gastei no mercado" sem valor → pede o valor)
        }

        if ($date === null) {
            $date = $today;
        } elseif (! $explicit) {
            // "dia 20" para conta a pagar: se o dia já passou neste mês, entende próximo mês
            if ($rollover && ! $income && ! $r['paid'] && $date->lt($today)) {
                $date = $date->addMonthNoOverflow();
            }
        }
        $r['date'] = $date->toDateString();
        $r['date_explicit'] = $explicit;
        $r['description'] = $this->description($work);

        return $r;
    }

    public function norm(string $s): string
    {
        $s = Str::lower(Str::ascii($s));
        $s = preg_replace('/[^a-z0-9$,.\/?! ]+/u', ' ', $s) ?? '';
        $s = preg_replace('/[.!?,]+(\s|$)/u', ' ', $s) ?? '';

        return trim(preg_replace('/\s+/', ' ', $s) ?? '');
    }

    /** @return array{0:int,1:string}|null valor em centavos + trecho original */
    private function amount(string $s): ?array
    {
        $re = '/(?:r\$\s*)?(\d{1,3}(?:\.\d{3})+(?:,\d{1,2})?|\d+(?:[.,]\d{1,2})?)(\s*(?:mil|k)\b)?(\s*(?:reais|real|conto|contos))?/iu';
        if (! preg_match_all($re, $s, $all, PREG_SET_ORDER)) {
            return null;
        }
        // preferir o número com R$ ou com "reais"/"mil"; senão o primeiro
        $pick = null;
        foreach ($all as $m) {
            if (stripos($m[0], 'r$') !== false || ! empty($m[2]) || ! empty($m[3])) {
                $pick = $m;
                break;
            }
        }
        $pick ??= $all[0];
        $cents = Money::parse($pick[1].(! empty($pick[2]) ? ' mil' : ''));

        return $cents !== null && $cents > 0 ? [$cents, $pick[0]] : null;
    }

    /** @return array{0:?CarbonImmutable,1:bool,2:string} data, se é explícita (dd/mm) e texto sem a data */
    private function date(string $work, CarbonImmutable $today): array
    {
        if (preg_match('/\b(\d{1,2})\/(\d{1,2})(?:\/(\d{2,4}))?\b/u', $work, $m)) {
            $y = isset($m[3]) ? ((int) $m[3] < 100 ? 2000 + (int) $m[3] : (int) $m[3]) : (int) $today->format('Y');
            if (checkdate((int) $m[2], (int) $m[1], $y)) {
                $d = CarbonImmutable::create($y, (int) $m[2], (int) $m[1]);

                return [$d, true, str_replace($m[0], ' ', $work)];
            }
        }
        $n = Str::lower(Str::ascii($work));
        foreach (['depois de amanha' => 2, 'amanha' => 1, 'hoje' => 0, 'ontem' => -1, 'anteontem' => -2] as $word => $add) {
            if (preg_match('/\b'.$word.'\b/u', $n)) {
                return [$today->addDays($add), true, preg_replace('/\b'.$word.'\b/iu', ' ', Str::ascii($work)) ?? $work];
            }
        }
        if (preg_match('/\b(?:vence|venc\.?|vencimento|vencendo|dia|todo dia|para o dia|pro dia)\s*(?:dia\s*)?(\d{1,2})\b/iu', $work, $m) && (int) $m[1] >= 1 && (int) $m[1] <= 31) {
            $d = $today->setDay(min((int) $m[1], $today->daysInMonth));

            return [$d, false, str_replace($m[0], ' ', $work)];
        }

        return [null, false, $work];
    }

    /** Período de consultas sobre o que já aconteceu (gastos, recebimentos, saldo). */
    public function pastPeriod(string $n, CarbonImmutable $today): array
    {
        foreach (Months::NAMES as $i => $name) {
            if (preg_match('/\b(em|de|no mes de|do mes de|mes de)\s+'.Str::ascii($name).'\b/u', $n)) {
                $d = CarbonImmutable::create((int) $today->format('Y'), $i + 1, 1);
                if ($d->gt($today)) {
                    $d = $d->subYear();
                }

                return ['from' => $d->toDateString(), 'to' => $d->endOfMonth()->toDateString(), 'label' => 'em '.$name.'/'.$d->format('Y'), 'month' => $d->format('Y-m')];
            }
        }
        $mon = $today->startOfWeek(CarbonImmutable::MONDAY);

        return match (true) {
            (bool) preg_match('/\banteontem\b/u', $n) => ['from' => $today->subDays(2)->toDateString(), 'to' => $today->subDays(2)->toDateString(), 'label' => 'anteontem'],
            (bool) preg_match('/\bontem\b/u', $n) => ['from' => $today->subDay()->toDateString(), 'to' => $today->subDay()->toDateString(), 'label' => 'ontem'],
            (bool) preg_match('/\bhoje\b/u', $n) => ['from' => $today->toDateString(), 'to' => $today->toDateString(), 'label' => 'hoje'],
            (bool) preg_match('/\bsemana passada\b/u', $n) => ['from' => $mon->subWeek()->toDateString(), 'to' => $mon->subDay()->toDateString(), 'label' => 'na semana passada'],
            (bool) preg_match('/\b(semana|7 dias|sete dias)\b/u', $n) => ['from' => $mon->toDateString(), 'to' => $today->toDateString(), 'label' => 'nesta semana'],
            (bool) preg_match('/\b(mes passado|ultimo mes|mes anterior)\b/u', $n) => ['from' => $today->subMonthNoOverflow()->startOfMonth()->toDateString(), 'to' => $today->subMonthNoOverflow()->endOfMonth()->toDateString(),
                'label' => 'no mês passado', 'month' => $today->subMonthNoOverflow()->format('Y-m')],
            (bool) preg_match('/\b(este ano|esse ano|neste ano|nesse ano|no ano|ano todo)\b/u', $n) => ['from' => $today->startOfYear()->toDateString(), 'to' => $today->endOfYear()->toDateString(), 'label' => 'em '.$today->format('Y')],
            default => ['from' => $today->startOfMonth()->toDateString(), 'to' => $today->endOfMonth()->toDateString(), 'label' => 'neste mês', 'month' => $today->format('Y-m')],
        };
    }

    /**
     * Filtro de consulta: "de combustível", "com mercado", "no ifood" → categoria (nome ou palavra-chave) ou termo da descrição.
     *
     * @return array{0:?string,1:?string}
     */
    private function queryFilter(string $n, string $type): array
    {
        $strip = '/\b(hoje|ontem|anteontem|(n?esta|n?essa|na) semana|semana passada|(n?este|n?esse|no) mes|mes passado|ultimo mes|mes anterior|(n?este|n?esse|no) ano|ano todo|ate agora|ate hoje|total|no total|ao todo|por favor|'
            .'(em|de|no mes de|do mes de|mes de) ('.implode('|', array_map(fn ($m) => Str::ascii($m), Months::NAMES)).'))\b/u';
        $q = trim(preg_replace('/\s+/', ' ', preg_replace($strip, ' ', $n) ?? '') ?? '');
        if (! preg_match('/\b(?:de|com|em|no|na|nos|nas|pra|para|do|da)\s+([a-z0-9][a-z0-9 ]{1,40})$/u', $q, $m)) {
            return [null, null];
        }
        $term = trim(preg_replace('/^(o|a|os|as|um|uma|meu|minha|meus|minhas)\s+/u', '', $m[1]) ?? '');
        if ($term === '' || preg_match('/^(despesas?|gastos?|receitas?|entradas?|dinheiro|reais|tudo|mim|voce)$/u', $term)) {
            return [null, null];
        }
        $cat = CategoryGuesser::find($term, $type);

        return [$cat, $cat ? null : $term];
    }

    private function period(string $n, CarbonImmutable $today): array
    {
        return match (true) {
            (bool) preg_match('/\b(vencid\w*|atrasad\w*|em atraso)\b/u', $n) => ['from' => null, 'to' => $today->subDay()->toDateString(), 'label' => 'vencidas', 'overdue' => true],
            (bool) preg_match('/\bdepois de amanha\b/u', $n) => ['from' => $today->addDays(2)->toDateString(), 'to' => $today->addDays(2)->toDateString(), 'label' => 'depois de amanhã'],
            (bool) preg_match('/\bamanha\b/u', $n) => ['from' => $today->addDay()->toDateString(), 'to' => $today->addDay()->toDateString(), 'label' => 'amanhã'],
            (bool) preg_match('/\bhoje\b/u', $n) => ['from' => $today->toDateString(), 'to' => $today->toDateString(), 'label' => 'hoje'],
            (bool) preg_match('/\b(semana|7 dias|sete dias)\b/u', $n) => ['from' => $today->toDateString(), 'to' => $today->addDays(6)->toDateString(), 'label' => 'nos próximos 7 dias'],
            (bool) preg_match('/\b(proximo mes|mes que vem)\b/u', $n) => ['from' => $today->addMonthNoOverflow()->startOfMonth()->toDateString(), 'to' => $today->addMonthNoOverflow()->endOfMonth()->toDateString(), 'label' => 'no próximo mês'],
            default => ['from' => $today->startOfMonth()->toDateString(), 'to' => $today->endOfMonth()->toDateString(), 'label' => 'neste mês'],
        };
    }

    private function companyHint(string $raw, string $n): ?string
    {
        if (preg_match('/\bempresa\s+([\p{L}\p{N}][\p{L}\p{N}&\-]*(?:\s+(?!de\b|do\b|da\b|para\b|pra\b|no\b|na\b|dia\b|vence\b|com\b|em\b)[\p{L}\p{N}&\-]+){0,3})/iu', $raw, $m)) {
            return trim(rtrim($m[1], '.,!?'));
        }

        return null;
    }

    private function description(string $work): string
    {
        $work = preg_replace('/\b(?:em|de)\s+(?:dinheiro|pix|boleto|cartao|cartão|debito|débito|credito|crédito)\b/iu', ' ', $work) ?? $work;
        $words = preg_split('/\s+/u', trim($work)) ?: [];
        $keep = [];
        foreach ($words as $w) {
            $clean = trim($w, " \t.,!?:;\"'()");
            $key = Str::lower(Str::ascii($clean));
            if ($clean === '' || in_array($key, self::STOP, true) || preg_match('/^\d+$/', $key)) {
                continue;
            }
            $keep[] = $clean;
        }
        $d = trim(implode(' ', $keep));

        return $d === '' ? '' : Str::ucfirst(Str::limit($d, 120, ''));
    }
}
