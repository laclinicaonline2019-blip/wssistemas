<?php

namespace App\Modules\Banking\Http\Controllers;

use App\Core\Support\Format;
use App\Http\Controllers\Controller;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Banking\Models\BankStatement;
use App\Modules\Banking\Models\BankStatementLine;
use App\Modules\Banking\Services\BankSync;
use App\Modules\Banking\Services\Reconciler;
use App\Modules\Banking\Services\StatementImporter;
use App\Modules\Finance\Models\FinancialCategory;
use App\Modules\Finance\Models\FinancialTransaction;
use App\Modules\Organization\Models\Branch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Conciliação bancária (financeiro.conciliar). */
class BankingWebController extends Controller
{
    public function index(): View
    {
        return view('banking.index', [
            'accounts' => BankAccount::query()->with('branch:id,name')->withCount(['lines as pending_count' => fn ($q) => $q->where('status', 'pending')])->orderBy('name')->get(),
            'branches' => Branch::query()->active()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function storeAccount(Request $request): RedirectResponse
    {
        $account = $this->fill(new BankAccount, $request);

        return redirect()->route('bank.accounts.show', $account)->with('success', 'Conta bancária cadastrada. Importe o extrato (OFX ou CSV).');
    }

    public function updateAccount(Request $request, BankAccount $account): RedirectResponse
    {
        $this->fill($account, $request);

        return back()->with('success', 'Conta bancária atualizada.');
    }

    public function show(Request $request, BankAccount $account, Reconciler $reconciler): View
    {
        $f = $request->validate(['status' => ['nullable', Rule::in(array_keys(BankStatementLine::STATUSES))], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        $status = $f['status'] ?? 'pending';
        $lines = BankStatementLine::query()->where('bank_account_id', $account->id)->where('status', $status)
            ->when($f['from'] ?? null, fn ($q, $d) => $q->where('posted_on', '>=', $d))->when($f['to'] ?? null, fn ($q, $d) => $q->where('posted_on', '<=', $d))
            ->with(['matches.transaction', 'resolver:id,name'])->orderBy('posted_on')->orderBy('id')->paginate(30)->withQueryString();

        return view('banking.account', [
            'account' => $account, 'lines' => $lines, 'status' => $status, 'filters' => $f,
            'suggestions' => $status === 'pending' ? $lines->getCollection()->mapWithKeys(fn ($l) => [$l->id => $reconciler->suggestions($l, 3)]) : collect(),
            'statements' => BankStatement::query()->with('importer:id,name')->where('bank_account_id', $account->id)->latest('created_at')->limit(10)->get(),
            'counts' => BankStatementLine::query()->where('bank_account_id', $account->id)->selectRaw('status, COUNT(*) AS qty')->groupBy('status')->pluck('qty', 'status'),
            'branches' => Branch::query()->active()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function import(Request $request, BankAccount $account, StatementImporter $importer): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:5120']], [], ['file' => 'arquivo do extrato']);
        $file = $request->file('file');
        $s = $importer->importFile($request->user(), $account, (string) file_get_contents($file->getRealPath()), $file->getClientOriginalName());

        return back()->with('success', "Extrato importado: {$s->lines_total} lançamento(s), {$s->lines_new} novo(s)".($s->lines_new < $s->lines_total ? ' (os demais já estavam importados)' : '').'.');
    }

    public function sync(Request $request, BankAccount $account, BankSync $sync): RedirectResponse
    {
        $s = $sync->sync($request->user(), $account);

        return back()->with('success', "Open Finance: {$s->lines_total} lançamento(s) lido(s), {$s->lines_new} novo(s).");
    }

    public function auto(Request $request, BankAccount $account, Reconciler $reconciler): RedirectResponse
    {
        $n = $reconciler->autoReconcile($request->user(), $account);

        return back()->with('success', $n ? "{$n} linha(s) conciliada(s) automaticamente (valor exato, sem ambiguidade). Confira na aba Conciliados." : 'Nenhum caso exato e sem ambiguidade para conciliar automaticamente.');
    }

    public function line(Request $request, BankStatementLine $line, Reconciler $reconciler): View
    {
        $f = $request->validate(['q_from' => ['nullable', 'date'], 'q_to' => ['nullable', 'date'], 'q_amount' => ['nullable', 'string', 'max:20'], 'q_direction' => ['nullable', Rule::in(['in', 'out'])]]);
        $from = $f['q_from'] ?? $line->posted_on->copy()->subDays(10)->toDateString();
        $to = $f['q_to'] ?? $line->posted_on->copy()->addDays(3)->toDateString();
        $amount = isset($f['q_amount']) && $f['q_amount'] !== '' ? Format::parseMoney($f['q_amount']) : null;

        return view('banking.line', [
            'line' => $line->load(['account', 'matches.transaction', 'resolver:id,name']),
            'suggestions' => $line->status === 'pending' ? $reconciler->suggestions($line) : collect(),
            'search' => $line->status === 'pending' ? $reconciler->candidates()
                ->whereBetween('occurred_at', [now()->parse($from, 'America/Sao_Paulo')->startOfDay()->utc(), now()->parse($to, 'America/Sao_Paulo')->endOfDay()->utc()])
                ->when($amount, fn ($q) => $q->where('amount_cents', abs($amount)))->when($f['q_direction'] ?? null, fn ($q, $d) => $q->where('direction', $d))
                ->orderBy('occurred_at')->limit(100)->get() : collect(),
            'q' => ['from' => $from, 'to' => $to, 'amount' => $f['q_amount'] ?? '', 'direction' => $f['q_direction'] ?? ($line->amount_cents > 0 ? 'in' : 'out')],
            'categories' => FinancialCategory::query()->where('is_active', true)->where('type', $line->amount_cents > 0 ? 'income' : 'expense')->orderBy('name')->get(['id', 'name']),
            'branches' => Branch::query()->active()->orderBy('name')->get(['id', 'name']),
            'methods' => array_diff_key(FinancialTransaction::METHODS, ['cash' => true]),
        ]);
    }

    public function match(Request $request, BankStatementLine $line, Reconciler $reconciler): RedirectResponse
    {
        $data = $request->validate(['transactions' => ['required', 'array', 'min:1', 'max:200'], 'transactions.*' => ['string', 'size:26']], [], ['transactions' => 'lançamentos']);
        $reconciler->match($request->user(), $line, $data['transactions']);

        return $this->next($line, 'Linha conciliada.');
    }

    public function ignore(Request $request, BankStatementLine $line, Reconciler $reconciler): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']], [], ['reason' => 'motivo']);
        $reconciler->ignore($request->user(), $line, $data['reason']);

        return $this->next($line, 'Linha marcada como ignorada.');
    }

    public function undo(Request $request, BankStatementLine $line, Reconciler $reconciler): RedirectResponse
    {
        abort_if($line->status === 'pending', 409);
        $reconciler->undo($request->user(), $line);

        return back()->with('success', 'Conciliação desfeita: a linha voltou para "a conciliar" (o histórico fica registrado).');
    }

    public function createEntry(Request $request, BankStatementLine $line, Reconciler $reconciler): RedirectResponse
    {
        $data = $request->validate([
            'category_id' => ['required', 'string', 'size:26'], 'description' => ['required', 'string', 'max:200'], 'counterparty' => ['required', 'string', 'max:150'],
            'method' => ['required', Rule::in(array_keys(array_diff_key(FinancialTransaction::METHODS, ['cash' => true])))], 'branch_id' => ['nullable', 'string', 'size:26'],
        ], [], ['category_id' => 'categoria', 'description' => 'descrição', 'counterparty' => $line->amount_cents < 0 ? 'favorecido' : 'pagador', 'method' => 'forma']);
        $reconciler->createEntry($request->user(), $line, $data);

        return $this->next($line, 'Lançamento criado no financeiro e conciliado.');
    }

    private function next(BankStatementLine $line, string $message): RedirectResponse
    {
        return redirect()->route('bank.accounts.show', $line->bank_account_id)->with('success', $message);
    }

    private function fill(BankAccount $account, Request $request): BankAccount
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'], 'bank_code' => ['nullable', 'regex:/^\d{3,8}$/'], 'agency' => ['nullable', 'string', 'max:20'],
            'account_number' => ['nullable', 'string', 'max:30'], 'branch_id' => ['nullable', 'string', 'size:26'],
            'sync_provider' => ['required', Rule::in(array_keys(BankAccount::SYNC))], 'external_account_id' => ['nullable', 'required_if:sync_provider,pluggy', 'string', 'max:100'],
            'client_id' => ['nullable', 'string', 'max:200'], 'client_secret' => ['nullable', 'string', 'max:300'],
        ], ['bank_code.regex' => 'Código do banco: só números (ex.: 341).'], ['name' => 'nome', 'external_account_id' => 'ID da conta na Pluggy']);
        if (! empty($data['branch_id'])) {
            Branch::query()->findOrFail($data['branch_id']);
        }

        $credentials = $account->credentials ?? [];
        foreach (['client_id', 'client_secret'] as $k) {
            if (! empty($data[$k])) {
                $credentials[$k] = $data[$k]; // vazio = mantém (nunca exibido)
            }
        }
        $account->fill(array_intersect_key($data, array_flip(['name', 'bank_code', 'agency', 'account_number', 'branch_id', 'sync_provider', 'external_account_id']))
            + ['credentials' => $credentials, 'is_active' => $request->boolean('is_active', true)]);
        $account->save();

        return $account;
    }
}
