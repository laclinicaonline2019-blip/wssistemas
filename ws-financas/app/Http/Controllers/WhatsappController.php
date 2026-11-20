<?php

namespace App\Http\Controllers;

use App\Core\Audit\AuditLogger;
use App\Core\Support\BusinessRule;
use App\Http\Concerns\EmployeeRulesInput;
use App\Models\CostCenter;
use App\Models\Company;
use App\Models\Membership;
use App\Models\UsageCounter;
use App\Models\WhatsappContact;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Números autorizados a falar com o assistente em nome do cliente. */
class WhatsappController extends Controller
{
    use EmployeeRulesInput;

    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): View
    {
        $account = $request->attributes->get('account');

        return view('settings.whatsapp', [
            'list' => WhatsappContact::query()->with('defaultCompany', 'user')->orderBy('label')->get(),
            'allCompanies' => Company::query()->where('active', true)->orderBy('name')->get(),
            'members' => Membership::query()->with('user')->where('status', 'active')->get(),
            'usage' => UsageCounter::current($account->id),
            'plan' => $account->plan(),
            'centers' => CostCenter::query()->with('company')->where('active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $account = $request->attributes->get('account');
        if (! $account->plan()?->has('whatsapp')) {
            throw new BusinessRule('O assistente no WhatsApp está disponível nos planos Profissional e Empresarial.', 'plano');
        }
        $d = $this->validated($request);
        $phone = WhatsappContact::normalize($d['phone']);
        if (strlen($phone) < 12 || strlen($phone) > 13) {
            return back()->withInput()->with('error', 'Informe o celular com DDD. Ex.: (11) 98765-4321');
        }
        $hash = WhatsappContact::hashPhone($phone);
        if (WhatsappContact::query()->withoutGlobalScopes()->where('phone_hash', $hash)->exists()) {
            return back()->withInput()->with('error', 'Este número já está vinculado a uma conta do WS Finanças. Cada número atende um único cliente.');
        }
        $c = WhatsappContact::query()->create($d + ['phone' => $phone, 'phone_hash' => $hash, 'phone_last4' => substr($phone, -4), 'active' => true]);
        $code = $c->issueCode();
        $this->audit->log('whatsapp.autorizar', $c, ['final' => $c->phone_last4]);

        return back()->with('ok', 'Número cadastrado. Falta validar: envie o código abaixo do celular final '.$c->phone_last4.' para o WhatsApp do WS Finanças.')
            ->with('wa_code', ['id' => $c->id, 'code' => $code]);
    }

    /** Novo código de validação (o anterior deixa de valer). */
    public function code(WhatsappContact $contact): RedirectResponse
    {
        if ($contact->verified()) {
            return back()->with('ok', 'Este número já está validado.');
        }
        $code = $contact->issueCode();
        $this->audit->log('whatsapp.novo_codigo', $contact, ['final' => $contact->phone_last4]);

        return back()->with('ok', 'Novo código gerado para o final '.$contact->phone_last4.'.')->with('wa_code', ['id' => $contact->id, 'code' => $code]);
    }

    public function update(Request $request, WhatsappContact $contact): RedirectResponse
    {
        $d = $this->validated($request, false);
        $contact->update($d + ['active' => $request->boolean('active')]);
        $this->audit->log('whatsapp.editar', $contact, ['final' => $contact->phone_last4, 'aprovacao' => $contact->requires_approval, 'limite' => $contact->approval_limit_cents,
            'empresas' => $contact->company_ids ?? 'todas', 'centros' => $contact->cost_center_ids ?? 'todos']);

        return back()->with('ok', 'Número atualizado.');
    }

    public function destroy(WhatsappContact $contact): RedirectResponse
    {
        $this->audit->log('whatsapp.remover', $contact, ['final' => $contact->phone_last4]);
        $contact->delete();

        return back()->with('ok', 'Número removido.');
    }

    private function validated(Request $request, bool $withPhone = true): array
    {
        $d = $request->validate(array_filter([
            'phone' => $withPhone ? ['required', 'string', 'max:20'] : null,
            'label' => ['required', 'string', 'max:80'], 'default_company_id' => ['nullable', 'string'], 'user_id' => ['nullable', 'string'],
            'can_write' => ['boolean'], 'reminders_opt_in' => ['boolean'],
        ]));
        $d['default_company_id'] = ! empty($d['default_company_id']) ? Company::query()->whereKey($d['default_company_id'])->value('id') : null;
        $d['user_id'] = ! empty($d['user_id']) ? Membership::query()->where('user_id', $d['user_id'])->value('user_id') : null;
        $d['can_write'] = $request->boolean('can_write');
        $d['reminders_opt_in'] = $request->boolean('reminders_opt_in');
        // número avulso (sem usuário): regras próprias; ligado a usuário: valem as do usuário
        $d += $d['user_id'] ? ['requires_approval' => false, 'approval_limit_cents' => null, 'company_ids' => null, 'cost_center_ids' => null] : $this->employeeRules($request, true);

        return $d;
    }
}
