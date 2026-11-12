<?php

namespace App\Http\Controllers;

use App\Core\Access\Permissions;
use App\Core\Tenancy\TenantContext;
use App\Models\BankAccount;
use App\Models\Membership;
use App\Models\Plan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PageController extends Controller
{
    public function home(Request $request): View|RedirectResponse
    {
        if ($request->user()) {
            return redirect()->route($request->user()->is_platform_admin && ! $request->user()->memberships()->withoutGlobalScopes()->exists() ? 'admin.dashboard' : 'app');
        }

        return view('pages.home', ['plans' => Plan::query()->where('active', true)->orderBy('sort')->get()]);
    }

    public function privacy(): View
    {
        return view('pages.privacy');
    }

    public function terms(): View
    {
        return view('pages.terms');
    }

    /** O painel principal (mesmo visual do WS Finanças original). */
    public function app(Request $request, TenantContext $ctx): View
    {
        $account = $request->attributes->get('account');
        $companies = $request->attributes->get('companies');
        $plan = $account->plan();

        return view('app.dashboard', [
            'boot' => [
                'empresas' => $companies->map(fn ($c) => ['id' => $c->id, 'nome' => $c->name, 'apelido' => $c->alias])->values(),
                'contas' => BankAccount::query()->where('active', true)->orderBy('name')->get(['id', 'company_id', 'name'])->map(fn ($b) => ['id' => $b->id, 'empresa' => $b->company_id, 'nome' => $b->name])->values(),
                'consolidado' => (bool) $plan?->has('consolidated') && $companies->count() > 1,
                'relatoriosAvancados' => (bool) $plan?->has('advanced_reports'),
                'ia' => (bool) $plan?->has('ai') && config('wsf.ai.mode') !== 'off' && (string) config('services.anthropic.key') !== '',
                'whatsapp' => (bool) $plan?->has('whatsapp'),
                'whatsappNumero' => config('services.whatsapp.display_number'),
                'podeLancar' => Permissions::can('financeiro.lancar') && $account->canWrite(),
                'podeExportar' => Permissions::can('relatorios.exportar'),
                'bloqueado' => ! $account->canWrite(),
                'categorias' => config('wsf.categories'),
                'hoje' => today()->toDateString(),
            ],
        ]);
    }

    public function switchAccount(Request $request): RedirectResponse
    {
        $id = (string) $request->input('membership_id');
        $ok = Membership::query()->withoutGlobalScopes()->where('id', $id)->where('user_id', $request->user()->id)->where('status', 'active')->exists();
        if ($ok) {
            $request->session()->put('membership_id', $id);
        }

        return redirect()->route('app');
    }
}
