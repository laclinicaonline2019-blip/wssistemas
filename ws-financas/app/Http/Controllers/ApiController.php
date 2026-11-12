<?php

namespace App\Http\Controllers;

use App\Core\Audit\AuditLogger;
use App\Core\Support\BusinessRule;
use App\Core\Support\Money;
use App\Core\Support\Months;
use App\Core\Tenancy\TenantContext;
use App\Models\Debt;
use App\Models\Entry;
use App\Models\UsageCounter;
use App\Services\Assistant\AiInterpreter;
use App\Services\Assistant\Assistant;
use App\Services\FinanceService;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/** API JSON do painel. Toda autorização é feita aqui/no middleware — o navegador só exibe. */
class ApiController extends Controller
{
    public function __construct(private readonly FinanceService $finance, private readonly TenantContext $ctx) {}

    public function month(Request $request): JsonResponse
    {
        $month = $this->month_($request);
        $companies = $this->scope($request);

        return response()->json(['mes' => $month, 'itens' => $this->finance->monthItems($companies, $month), 'consolidado' => $companies->count() > 1]);
    }

    public function store(Request $request): JsonResponse
    {
        $d = $request->validate([
            'empresa' => ['required', 'string'], 'tipo' => ['required', 'in:despesa,receita'], 'desc' => ['required', 'string', 'max:190'],
            'valor' => ['required'], 'cat' => ['nullable', 'string', 'max:60'], 'data' => ['required', 'date_format:Y-m-d'],
            'pago' => ['boolean'], 'repetir' => ['integer', 'min:1', 'max:120'], 'modo' => ['nullable', 'in:fixa,parcelada'], 'conta' => ['nullable', 'string'],
        ]);
        $company = $this->finance->company($d['empresa']);
        $created = $this->finance->create($company, ['tipo' => $d['tipo'], 'desc' => $d['desc'], 'valor_cents' => $this->money($d['valor']), 'cat' => $d['cat'] ?? null,
            'data' => $d['data'], 'pago' => (bool) ($d['pago'] ?? false), 'repetir' => (int) ($d['repetir'] ?? 1), 'modo' => $d['modo'] ?? 'fixa', 'conta' => $d['conta'] ?? null]);

        return response()->json(['itens' => array_map(fn ($e) => $this->finance->item($e), $created)], 201);
    }

    public function update(Request $request, Entry $entry): JsonResponse
    {
        $d = $request->validate(['desc' => ['sometimes', 'string', 'max:190'], 'valor' => ['sometimes'], 'data' => ['sometimes', 'date_format:Y-m-d'],
            'cat' => ['nullable', 'string', 'max:60'], 'pago' => ['sometimes', 'boolean'], 'conta' => ['sometimes', 'nullable', 'string']]);
        if (array_key_exists('valor', $d)) {
            $d['valor_cents'] = $this->money($d['valor']);
        }

        return response()->json(['item' => $this->finance->item($this->finance->update($entry, $d))]);
    }

    public function togglePaid(Request $request, Entry $entry): JsonResponse
    {
        $request->validate(['pago' => ['required', 'boolean']]);

        return response()->json(['item' => $this->finance->item($this->finance->setPaid($entry, $request->boolean('pago')))]);
    }

    public function destroy(Request $request, Entry $entry): JsonResponse
    {
        $n = $this->finance->delete($entry, $request->query('escopo') === 'grupo' ? 'grupo' : 'um');

        return response()->json(['removidos' => $n]);
    }

    public function storeDebt(Request $request): JsonResponse
    {
        $company = $this->finance->company((string) $request->input('empresa'));
        $debt = $this->finance->saveDebt($company, $request->only(['credor', 'saldo', 'juros', 'parcela']));

        return response()->json(['id' => $debt->id], 201);
    }

    public function updateDebt(Request $request, Debt $debt): JsonResponse
    {
        $this->finance->saveDebt($this->finance->company($debt->company_id), $request->only(['credor', 'saldo', 'juros', 'parcela']), $debt);

        return response()->json(['id' => $debt->id]);
    }

    public function destroyDebt(Debt $debt): JsonResponse
    {
        $debt->delete();
        app(AuditLogger::class)->log('divida.remover', $debt, ['credor' => $debt->creditor]);

        return response()->json(['ok' => true]);
    }

    public function settings(Request $request): JsonResponse
    {
        $request->validate(['empresa' => ['required', 'string'], 'estrategia' => ['nullable', 'in:avalanche,bola']]);
        $company = $this->finance->company($request->input('empresa'));
        $s = $this->finance->saveSettings($company, $request->has('orc') ? (Money::parse((string) $request->input('orc')) ?? 0) : null, $request->input('estrategia'));

        return response()->json(['orc' => $s->debt_budget_cents / 100, 'estrategia' => $s->debt_strategy]);
    }

    /** Caixa "Lançar rápido": o mesmo assistente do WhatsApp. */
    public function message(Request $request, Assistant $assistant): JsonResponse
    {
        $d = $request->validate(['texto' => ['required', 'string', 'max:500'], 'empresa' => ['nullable', 'string'], 'mes' => ['nullable', 'string']]);
        $forced = $d['empresa'] && $d['empresa'] !== 'todas' ? $this->finance->company($d['empresa'])->id : null;
        $month = Months::valid($d['mes'] ?? null) ? $d['mes'] : now()->format('Y-m');
        $base = $month === now()->format('Y-m') ? null : $month.'-01';
        $account = $request->attributes->get('account');

        $r = $assistant->handle([
            'account_id' => $this->ctx->accountId(), 'key' => 'web:'.$request->user()->id.':'.$this->ctx->accountId(), 'channel' => 'web', 'role' => $this->ctx->role(),
            'company_ids' => $this->ctx->companyIds(), 'default_company_id' => null, 'can_write' => $account->canWrite(), 'user_id' => $request->user()->id,
            'forced_company_id' => $forced, 'base_date' => $base,
        ], $d['texto']);

        return response()->json(['ok' => ! in_array($r['intent'], ['desconhecido', 'bloqueado'], true), 'texto' => $r['reply'], 'pendente' => $r['pending'], 'ids' => $r['entry_ids']]);
    }

    public function sample(Request $request): JsonResponse
    {
        $this->finance->loadSample($this->finance->company((string) $request->input('empresa')));

        return response()->json(['ok' => true]);
    }

    public function clearSample(Request $request): JsonResponse
    {
        $this->finance->clearSample($this->finance->company((string) $request->input('empresa')));

        return response()->json(['ok' => true]);
    }

    public function cashflow(Request $request, ReportService $reports): JsonResponse
    {
        return response()->json($reports->cashflow($this->scope($request), $this->month_($request)));
    }

    public function report(Request $request, ReportService $reports): JsonResponse
    {
        [$from, $to] = $this->range($request);

        return response()->json($reports->period($this->scope($request), $from, $to, false));
    }

    /** "Análise do Claude" do fechamento (o navegador manda o resumo já calculado na tela). */
    public function analysis(Request $request, AiInterpreter $ai): JsonResponse
    {
        $request->validate(['resumo' => ['required', 'string', 'max:6000']]);
        $account = $request->attributes->get('account');
        $plan = $account->plan();
        if (! $plan?->has('ai')) {
            throw new BusinessRule('A análise por IA não está incluída no seu plano.', 'plano');
        }
        if (UsageCounter::current($account->id)->ai_calls >= $plan->ai_calls_month) {
            throw new BusinessRule('Limite mensal de uso da IA atingido.', 'limite');
        }
        $prompt = 'Você é um educador financeiro brasileiro ajudando uma pessoa ou pequena empresa a organizar as contas e sair das dívidas com calma. Com base nos números abaixo, '
            .'escreva em português do Brasil, em no máximo 220 palavras, sem tabelas e sem markdown: (1) um diagnóstico de duas frases, (2) três a cinco cortes concretos com o valor '
            ."aproximado que cada um libera por mês, (3) a ordem de pagamento das dívidas e se vale tentar renegociar alguma. Tom acolhedor e direto, sem julgamento.\n\n"
            .strip_tags($request->input('resumo'));
        $text = $ai->analysis($account->id, $prompt);
        if (! $text) {
            throw new BusinessRule('Não foi possível concluir a análise agora. Tente de novo em instantes.', 'ia');
        }

        return response()->json(['texto' => $text]);
    }

    // ------------------------------------------------------------ apoio

    private function scope(Request $request): Collection
    {
        $sel = (string) $request->query('empresa', $request->input('empresa', ''));
        if ($sel === 'todas') {
            $account = $request->attributes->get('account');
            if (! $account->plan()?->has('consolidated')) {
                throw new BusinessRule('A visão consolidada está disponível nos planos Profissional e Empresarial.', 'plano');
            }
        }

        return $this->finance->companies($sel === '' ? null : $sel)->when($sel === '', fn ($c) => $c->take(1));
    }

    private function month_(Request $request): string
    {
        $m = (string) $request->query('mes', '');

        return Months::valid($m) ? $m : now()->format('Y-m');
    }

    private function range(Request $request): array
    {
        $from = (string) $request->query('de', '');
        $to = (string) $request->query('ate', '');
        $from = Months::valid($from) ? $from : now()->format('Y-m');
        $to = Months::valid($to) && $to >= $from ? $to : $from;
        if (Months::add($from, 36) < $to) {
            throw new BusinessRule('Período máximo de 36 meses.', 'validacao');
        }

        return [$from, $to];
    }

    private function money(mixed $v): int
    {
        $c = is_numeric($v) && ! is_string($v) ? (int) round($v * 100) : Money::parse((string) $v);
        if ($c === null || $c <= 0) {
            throw new BusinessRule('Valor inválido.', 'validacao');
        }

        return $c;
    }
}
