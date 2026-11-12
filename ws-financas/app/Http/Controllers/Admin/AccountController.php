<?php

namespace App\Http\Controllers\Admin;

use App\Core\Tenancy\TenantContext;
use App\Core\Validation\Document;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Membership;
use App\Models\Plan;
use App\Models\SubscriptionEvent;
use App\Models\UsageCounter;
use App\Services\AccountService;
use App\Services\Billing\BillingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;

/** Proprietário: clientes, assinaturas, suspensão/ativação/cancelamento. Não exibe lançamentos financeiros dos clientes. */
class AccountController extends Controller
{
    public function __construct(private readonly BillingService $billing) {}

    public function index(Request $request): View
    {
        $q = Account::query()->with('subscription.plan')->withCount(['companies', 'memberships'])->latest();
        if ($s = trim((string) $request->query('busca'))) {
            $q->where(fn ($w) => $w->where('name', 'like', '%'.$s.'%')->orWhere('email', 'like', '%'.$s.'%'));
        }
        if ($st = $request->query('status')) {
            $q->whereHas('subscription', fn ($w) => $w->where('status', $st));
        }

        return view('admin.accounts', ['list' => $q->paginate(30)->withQueryString(), 'plans' => Plan::query()->orderBy('sort')->get()]);
    }

    public function store(Request $request, AccountService $accounts): RedirectResponse
    {
        $d = $request->validate(['name' => ['required', 'string', 'max:160'], 'person_type' => ['required', 'in:pf,pj'], 'document' => ['nullable', 'string', 'max:20', new Document],
            'email' => ['required', 'email'], 'admin_name' => ['required', 'string', 'max:160'], 'plan' => ['required', 'exists:plans,code'], 'cycle' => ['required', 'in:monthly,yearly'],
            'trial_days' => ['required', 'integer', 'min:0', 'max:365']]);
        $plan = Plan::query()->where('code', $d['plan'])->firstOrFail();
        [$account, $user] = $accounts->register(['name' => $d['name'], 'person_type' => $d['person_type'], 'document' => ! empty($d['document']) ? preg_replace('/\D/', '', $d['document']) : null,
            'email' => $d['email'], 'company_name' => $d['name'], 'admin_name' => $d['admin_name'], 'admin_email' => $d['email'], 'password' => bin2hex(random_bytes(24))],
            $plan, $d['cycle'], (int) $d['trial_days']);
        Password::sendResetLink(['email' => $user->email]);

        return redirect()->route('admin.accounts.show', $account)->with('ok', 'Cliente criado. O administrador recebeu um e-mail para definir a senha.');
    }

    public function show(Account $account, TenantContext $ctx): View
    {
        $account->load('subscription.plan');

        return view('admin.account', [
            'a' => $account,
            'companies' => Company::query()->where('account_id', $account->id)->get(),
            'members' => Membership::query()->with('user')->where('account_id', $account->id)->get(),
            'invoices' => Invoice::query()->where('account_id', $account->id)->latest('due_date')->limit(36)->get(),
            'events' => SubscriptionEvent::query()->where('account_id', $account->id)->latest('id')->limit(50)->get(),
            'usage' => UsageCounter::query()->where('account_id', $account->id)->latest('period')->limit(6)->get(),
            'plans' => Plan::query()->orderBy('sort')->get(),
        ]);
    }

    public function action(Request $request, Account $account): RedirectResponse
    {
        $d = $request->validate(['action' => ['required', 'in:suspend,reactivate,cancel,extend_trial,change_plan'], 'reason' => ['nullable', 'string', 'max:200'],
            'days' => ['nullable', 'integer', 'min:1', 'max:365'], 'plan' => ['nullable', 'exists:plans,code'], 'cycle' => ['nullable', 'in:monthly,yearly']]);
        $uid = $request->user()->id;
        $account->load('subscription.plan');
        match ($d['action']) {
            'suspend' => $this->billing->suspend($account, $d['reason'] ?: 'manual', $uid),
            'reactivate' => $this->billing->reactivate($account, $uid),
            'cancel' => $this->billing->cancel($account, false, $uid, $d['reason'] ?: 'cancelado pelo proprietário'),
            'extend_trial' => $this->billing->extendTrial($account, (int) ($d['days'] ?? 7), $uid),
            'change_plan' => $this->billing->changePlan($account, Plan::query()->where('code', $d['plan'])->firstOrFail(), $d['cycle'] ?? $account->subscription->cycle, true),
        };

        return back()->with('ok', 'Ação registrada no histórico do cliente.');
    }
}
